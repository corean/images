<?php

namespace Tests\Feature;

use App\Services\ImageService;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * 이미지 서빙의 HTTP 계약을 고정한다.
 *
 * ImageService 는 Storage::build() 로 디스크를 직접 만들기 때문에
 * Storage::fake() 로 대체되지 않는다. 컨트롤러 계층만 검증하는 것이 목적이므로
 * 서비스를 컨테이너에서 mock 으로 바꿔치기한다.
 */
class ImageControllerTest extends TestCase
{
    private const string BUCKET = 'orderhow';

    private const string PATH = 'cropped-images/1-abc.jpg';

    /**
     * 컨트롤러는 'public, max-age=31536000' 을 넣지만 Symfony 가 디렉티브를
     * 알파벳순으로 정규화해 내보낸다. 실제 응답 헤더 값으로 고정한다.
     */
    private const string CACHE_CONTROL = 'max-age=31536000, public';

    private function mockService(callable $expectations): void
    {
        $this->mock(ImageService::class, function (MockInterface $mock) use ($expectations): void {
            $expectations($mock);
        });
    }

    public function test_it_serves_the_original_image(): void
    {
        $this->mockService(function (MockInterface $mock): void {
            $mock->shouldReceive('getStorageDisk')
                ->once()
                ->with(self::BUCKET, self::PATH)
                ->andReturn('original-bytes');
        });

        $response = $this->get('/'.self::BUCKET.'/'.self::PATH);

        $response->assertOk();
        $response->assertContent('original-bytes');
        $this->assertSame(self::CACHE_CONTROL, $response->headers->get('Cache-Control'));
    }

    /**
     * Content-Type 은 경로의 확장자를 그대로 붙여 만든다. jpg 는 정식 MIME 이
     * image/jpeg 이지만 현재 구현은 image/jpg 를 내보낸다. 동작 변경 전에
     * 현행을 고정해 둔다.
     *
     * @see docs/work/test-coverage/research.md
     */
    public function test_it_derives_the_content_type_from_the_path_extension(): void
    {
        $this->mockService(function (MockInterface $mock): void {
            $mock->shouldReceive('getStorageDisk')->once()->andReturn('bytes');
        });

        $response = $this->get('/'.self::BUCKET.'/'.self::PATH);

        $this->assertSame('image/jpg', $response->headers->get('Content-Type'));
    }

    /**
     * 확장자가 없는 경로는 서브타입이 빈 image/ 를 내보낸다. 잘못된 헤더지만
     * 현재 동작이므로 고정해 두고, 수정은 별도 작업으로 분리한다.
     *
     * @see docs/work/test-coverage/research.md
     */
    public function test_a_path_without_an_extension_yields_an_empty_content_subtype(): void
    {
        $this->mockService(function (MockInterface $mock): void {
            $mock->shouldReceive('getStorageDisk')->once()->andReturn('bytes');
        });

        $response = $this->get('/'.self::BUCKET.'/no-extension');

        $response->assertOk();
        $this->assertSame('image/', $response->headers->get('Content-Type'));
    }

    /**
     * 서비스는 스토리지 실패를 NotFoundHttpException 으로 바꿔 던진다.
     * 그 예외가 404 응답으로 나가는지 확인한다.
     */
    public function test_a_missing_original_returns_not_found(): void
    {
        $this->mockService(function (MockInterface $mock): void {
            $mock->shouldReceive('getStorageDisk')
                ->once()
                ->andThrow(new NotFoundHttpException('Failed to retrieve image: missing'));
        });

        $this->get('/'.self::BUCKET.'/'.self::PATH)->assertNotFound();
    }

    public function test_it_serves_a_resized_image_as_webp(): void
    {
        $this->mockService(function (MockInterface $mock): void {
            $mock->shouldReceive('getProcessedImage')
                ->once()
                ->andReturn('webp-bytes');
        });

        $response = $this->get('/'.self::BUCKET.'/400x300/'.self::PATH);

        $response->assertOk();
        $response->assertContent('webp-bytes');
        $this->assertSame('image/webp', $response->headers->get('Content-Type'));
        $this->assertSame(self::CACHE_CONTROL, $response->headers->get('Cache-Control'));
        $this->assertSame('Accept', $response->headers->get('Vary'));
        $this->assertNotNull($response->headers->get('ETag'));
    }

    /**
     * 리사이즈 옵션이 사이즈 문자열에서 올바로 파싱되는지 확인한다.
     *
     * c 는 NPM 프록시가 ! 를 삼키는 문제 때문에 추가된 대체 표기이고(f99621f),
     * %21 은 인코딩된 ! 가 그대로 도달하는 프로덕션 케이스다(82426dc).
     * 두 커밋 모두 회귀 테스트 없이 들어왔다.
     *
     * @return array<string, array{string, int, int, bool}>
     */
    public static function sizeFormats(): array
    {
        return [
            'plain' => ['400x300', 400, 300, false],
            'crop with c' => ['400x300c', 400, 300, true],
            'crop with bang' => ['400x300!', 400, 300, true],
            'crop with encoded bang' => ['400x300%21', 400, 300, true],
            'width only' => ['400x0', 400, 0, false],
            'height only' => ['0x300', 0, 300, false],
        ];
    }

    #[DataProvider('sizeFormats')]
    public function test_it_passes_the_parsed_options_to_the_service(
        string $size,
        int $width,
        int $height,
        bool $forceCrop,
    ): void {
        $this->mockService(function (MockInterface $mock) use ($width, $height, $forceCrop): void {
            $mock->shouldReceive('getProcessedImage')
                ->once()
                ->with(
                    self::BUCKET,
                    self::PATH,
                    Mockery::on(fn (array $options): bool => $options['width'] === $width
                        && $options['height'] === $height
                        && $options['forceCrop'] === $forceCrop),
                )
                ->andReturn('webp-bytes');
        });

        $this->get('/'.self::BUCKET.'/'.$size.'/'.self::PATH)->assertOk();
    }

    /**
     * ETag 는 처리된 바이트와 사이즈 문자열로 만든다. 같은 ETag 로 재요청하면
     * 본문 없이 304 가 나가야 한다. CDN 대역폭이 여기에 달려 있다.
     */
    public function test_a_matching_if_none_match_returns_not_modified(): void
    {
        $this->mockService(function (MockInterface $mock): void {
            $mock->shouldReceive('getProcessedImage')->once()->andReturn('webp-bytes');
        });

        $response = $this->get(
            '/'.self::BUCKET.'/400x300/'.self::PATH,
            ['If-None-Match' => md5('webp-bytes400x300')],
        );

        $response->assertNoContent(304);
    }

    public function test_a_stale_if_none_match_returns_the_image(): void
    {
        $this->mockService(function (MockInterface $mock): void {
            $mock->shouldReceive('getProcessedImage')->once()->andReturn('webp-bytes');
        });

        $response = $this->get(
            '/'.self::BUCKET.'/400x300/'.self::PATH,
            ['If-None-Match' => md5('stale')],
        );

        $response->assertOk();
        $this->assertSame(md5('webp-bytes400x300'), $response->headers->get('ETag'));
    }

    /**
     * 사이즈 문자열이 달라지면 ETag 도 달라져야 한다. 바이트만으로 해시하면
     * 크롭 여부가 다른 두 결과가 같은 ETag 를 가질 수 있다.
     */
    public function test_the_etag_covers_the_size_string(): void
    {
        $this->mockService(function (MockInterface $mock): void {
            $mock->shouldReceive('getProcessedImage')->twice()->andReturn('webp-bytes');
        });

        $plain = $this->get('/'.self::BUCKET.'/400x300/'.self::PATH);
        $cropped = $this->get('/'.self::BUCKET.'/400x300c/'.self::PATH);

        $this->assertNotSame(
            $plain->headers->get('ETag'),
            $cropped->headers->get('ETag'),
        );
    }

    /**
     * 잘못된 사이즈 요청은 거부되어야 한다.
     *
     * 현재 구현은 InvalidArgumentException 을 그대로 던져 클라이언트에 500 이
     * 나간다. 4xx 가 적절하지만 커버리지 작업에서 동작을 바꾸지 않고
     * 현행을 고정해 둔다.
     *
     * @see docs/work/test-coverage/research.md
     *
     * @return array<string, array<int, string>>
     */
    public static function rejectedSizes(): array
    {
        return [
            'width over the cap' => ['3001x300'],
            'height over the cap' => ['400x3001'],
            'both dimensions zero' => ['0x0'],
        ];
    }

    #[DataProvider('rejectedSizes')]
    public function test_it_rejects_an_invalid_size(string $size): void
    {
        $this->mockService(function (MockInterface $mock): void {
            $mock->shouldNotReceive('getProcessedImage');
        });

        $this->get('/'.self::BUCKET.'/'.$size.'/'.self::PATH)->assertStatus(500);
    }
}
