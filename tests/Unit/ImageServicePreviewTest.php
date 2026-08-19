<?php

namespace Tests\Unit;

use App\Services\ImageService;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Mockery;
use Mockery\MockInterface;
use ReflectionProperty;
use Tests\TestCase;

/**
 * 미리보기 경로의 S3 왕복 횟수를 고정한다.
 *
 * 예전 콜드 경로는 왕복 5회였다: 미리보기 get(miss) → 원본 get →
 * 디렉터리 exists → makeDirectory → 미리보기 put. S3 에는 디렉터리 개념이 없어
 * 가운데 두 번은 순수 낭비였다.
 */
class ImageServicePreviewTest extends TestCase
{
    private const string BUCKET = 'orderhow';

    private const string ORIGINAL = 'cropped-images/1-abc.jpg';

    private function serviceWithDisk(MockInterface $disk): ImageService
    {
        config()->set('app.image_driver', 'gd');

        $service = new ImageService;

        $disks = new ReflectionProperty($service, 'disks');
        $disks->setAccessible(true);
        $disks->setValue($service, [self::BUCKET => $disk]);

        return $service;
    }

    private function pngFixture(): string
    {
        $canvas = imagecreatetruecolor(600, 600);
        imagefilledrectangle($canvas, 0, 0, 600, 600, imagecolorallocate($canvas, 10, 120, 200));

        ob_start();
        imagepng($canvas);
        imagedestroy($canvas);

        return (string) ob_get_clean();
    }

    public function test_it_returns_the_stored_preview_without_reading_the_original(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('get')->once()->andReturn('cached-preview-bytes');
        $disk->shouldNotReceive('put');
        $disk->shouldNotReceive('exists');
        $disk->shouldNotReceive('makeDirectory');

        $result = $this->serviceWithDisk($disk)->getProcessedImage(
            self::BUCKET,
            self::ORIGINAL,
            ['width' => 400, 'height' => 400, 'forceCrop' => true],
        );

        $this->assertSame('cached-preview-bytes', $result);
    }

    public function test_a_cold_request_makes_exactly_three_storage_calls(): void
    {
        $original = $this->pngFixture();

        $disk = Mockery::mock(Filesystem::class);

        $disk->shouldReceive('get')
            ->once()
            ->with(Mockery::pattern('#^previews/400x400!/#'))
            ->andThrow(new FileNotFoundException('missing preview'));

        $disk->shouldReceive('get')
            ->once()
            ->with(self::ORIGINAL)
            ->andReturn($original);

        $disk->shouldReceive('put')
            ->once()
            ->with(Mockery::pattern('#^previews/400x400!/#'), Mockery::type('string'))
            ->andReturnTrue();

        $disk->shouldNotReceive('exists');
        $disk->shouldNotReceive('makeDirectory');

        $result = $this->serviceWithDisk($disk)->getProcessedImage(
            self::BUCKET,
            self::ORIGINAL,
            ['width' => 400, 'height' => 400, 'forceCrop' => true],
        );

        $this->assertSame(IMAGETYPE_WEBP, getimagesizefromstring($result)[2]);
    }

    /**
     * 미리보기 저장이 실패해도 요청은 처리된 이미지를 돌려줘야 한다.
     */
    public function test_it_still_serves_the_image_when_the_preview_write_fails(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('get')->once()->with(Mockery::pattern('#^previews/#'))->andThrow(new FileNotFoundException('miss'));
        $disk->shouldReceive('get')->once()->with(self::ORIGINAL)->andReturn($this->pngFixture());
        $disk->shouldReceive('put')->once()->andThrow(new \RuntimeException('storage down'));

        $result = $this->serviceWithDisk($disk)->getProcessedImage(
            self::BUCKET,
            self::ORIGINAL,
            ['width' => 200, 'height' => 200, 'forceCrop' => true],
        );

        $this->assertSame(IMAGETYPE_WEBP, getimagesizefromstring($result)[2]);
    }
}
