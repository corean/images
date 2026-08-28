<?php

namespace Tests\Unit;

use App\Services\ImageService;
use Aws\Command;
use Aws\S3\Exception\S3Exception;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use League\Flysystem\UnableToReadFile;
use Mockery;
use Mockery\MockInterface;
use ReflectionProperty;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Tests\TestCase;

/**
 * getStorageDisk() 의 예외 변환을 고정한다.
 *
 * 예전에는 모든 예외(권한 오류, 연결 실패, 오브젝트 없음)가 NotFoundHttpException(404)
 * 하나로 뭉개졌다. AccessDenied 든 연결 타임아웃이든 클라이언트에게는 "이미지가
 * 없다"로 보였고, NotFoundHttpException 은 기본 리포트 대상이 아니라 로그에도
 * 흔적이 남지 않았다. 실제로 이 마스킹 때문에 스토리지 설정 오류를 원인 불명
 * 404 로 몇 시간을 들여 진단해야 했다.
 */
class ImageServiceStorageExceptionTest extends TestCase
{
    private const string BUCKET = 'orderhow';

    private const string PATH = 'cropped-images/1-abc.jpg';

    private function serviceWithDisk(MockInterface $disk): ImageService
    {
        $service = new ImageService;

        $disks = new ReflectionProperty($service, 'disks');
        $disks->setAccessible(true);
        $disks->setValue($service, [self::BUCKET => $disk]);

        return $service;
    }

    private function unableToReadFile(S3Exception $cause): UnableToReadFile
    {
        return UnableToReadFile::fromLocation(self::PATH, '', $cause);
    }

    private function s3Exception(string $errorCode): S3Exception
    {
        return new S3Exception('S3 error', new Command('GetObject'), ['code' => $errorCode]);
    }

    private function connectionError(): S3Exception
    {
        return new S3Exception('cURL error 28: Connection timed out', new Command('GetObject'), ['connection_error' => true]);
    }

    /**
     * 오브젝트가 실제로 없는 경우만 404 다. 흔한 정상 상황이므로 로그를 남기지 않는다.
     */
    public function test_missing_object_still_returns_404_and_is_not_logged(): void
    {
        Log::spy();

        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('get')->once()->andThrow($this->unableToReadFile($this->s3Exception('NoSuchKey')));

        try {
            $this->serviceWithDisk($disk)->getStorageDisk(self::BUCKET, self::PATH);
            $this->fail('예외가 발생해야 한다.');
        } catch (NotFoundHttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        Log::shouldNotHaveReceived('error');
    }

    /**
     * 버킷이 없는 경우도 "이 경로에 없다"는 점에서 404 다.
     */
    public function test_missing_bucket_returns_404(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('get')->once()->andThrow($this->unableToReadFile($this->s3Exception('NoSuchBucket')));

        $this->expectException(NotFoundHttpException::class);

        $this->serviceWithDisk($disk)->getStorageDisk(self::BUCKET, self::PATH);
    }

    /**
     * 권한·자격증명 오류는 "없음"이 아니라 설정 문제다. 500 으로 구분하고
     * 로그를 남겨야 다음에 원인 불명 404 로 몇 시간을 태우지 않는다.
     */
    public function test_access_denied_returns_500_and_is_logged(): void
    {
        Log::spy();

        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('get')->once()->andThrow($this->unableToReadFile($this->s3Exception('AccessDenied')));

        try {
            $this->serviceWithDisk($disk)->getStorageDisk(self::BUCKET, self::PATH);
            $this->fail('예외가 발생해야 한다.');
        } catch (HttpException $e) {
            $this->assertSame(500, $e->getStatusCode());
        }

        Log::shouldHaveReceived('error')->once();
    }

    /**
     * 연결 실패·타임아웃은 일시적 장애다. 503 으로 구분해 재시도 가능함을 알리고
     * 로그를 남긴다.
     */
    public function test_connection_failure_returns_503_and_is_logged(): void
    {
        Log::spy();

        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('get')->once()->andThrow($this->unableToReadFile($this->connectionError()));

        try {
            $this->serviceWithDisk($disk)->getStorageDisk(self::BUCKET, self::PATH);
            $this->fail('예외가 발생해야 한다.');
        } catch (ServiceUnavailableHttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }

        Log::shouldHaveReceived('error')->once();
    }

    /**
     * AWS 정보가 없는 일반적인 실패(로컬 디스크 등)는 기존과 같이 404 로 폴백한다.
     */
    public function test_a_generic_failure_without_aws_context_still_falls_back_to_404(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('get')->once()->andThrow(UnableToReadFile::fromLocation(self::PATH));

        $this->expectException(NotFoundHttpException::class);

        $this->serviceWithDisk($disk)->getStorageDisk(self::BUCKET, self::PATH);
    }

    /**
     * 원래 예외가 previous 로 보존돼야 한다. 이게 없으면 로그에 스택트레이스가
     * 잘려 AWS 오류 코드까지 추적할 수 없다.
     */
    public function test_the_original_exception_chain_is_preserved(): void
    {
        $cause = $this->s3Exception('AccessDenied');
        $wrapped = $this->unableToReadFile($cause);

        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('get')->once()->andThrow($wrapped);

        try {
            $this->serviceWithDisk($disk)->getStorageDisk(self::BUCKET, self::PATH);
            $this->fail('예외가 발생해야 한다.');
        } catch (HttpException $e) {
            $this->assertSame($wrapped, $e->getPrevious());
            $this->assertSame($cause, $e->getPrevious()->getPrevious());
        }
    }
}
