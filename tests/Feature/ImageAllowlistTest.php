<?php

namespace Tests\Feature;

use App\Services\ImageService;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * cloudv 의 오더하우 전용 리사이저는 orderhow 버킷의 사진만, 정해진 크기로만 내보낸다.
 *
 * 버킷·확장자·크기 제한이 없으면 원본 버킷을 비공개로 둬도 리사이저가 아무 키나 돌려주고
 * (2026-11-12 images.connple.com/orderhow/mysql-binlog/... 가 200), 임의 크기마다 CPU 와 미리보기 쓰기가 생긴다.
 * CT117 처럼 IMAGE_ALLOWED_BUCKETS 를 두지 않은 서버는 기존 동작을 유지한다.
 *
 * @see orderhow docs/work/storage-r2-migration/plan.md §3
 */
class ImageAllowlistTest extends TestCase
{
    private const string PATH = 'cropped-images/1-abc.jpg';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'images.allowed_buckets' => ['orderhow'],
            'images.sizes' => ['orderhow' => ['400x400c', '0x200', '2000x0']],
            'images.enforce_sizes' => false,
        ]);
    }

    private function mockService(callable $expectations): void
    {
        $mock = Mockery::mock(ImageService::class)->makePartial();
        $expectations($mock);

        $this->instance(ImageService::class, $mock);
    }

    private function expectNoStorageAccess(): void
    {
        $this->mockService(function (MockInterface $mock): void {
            $mock->shouldNotReceive('getStorageDisk');
            $mock->shouldNotReceive('getProcessedImage');
        });
    }

    public function test_other_buckets_are_not_served(): void
    {
        $this->expectNoStorageAccess();

        $this->get('/disclo-bot/'.self::PATH)->assertNotFound();
        $this->get('/disclo-bot/400x400c/'.self::PATH)->assertNotFound();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonImagePaths(): array
    {
        return [
            'binlog' => ['mysql-binlog/binlog.001198'],
            'spreadsheet' => ['pg-settlement-uploads/settlement.xlsx'],
            'archive' => ['backups/2026-11-05.zip'],
            'json' => ['phones/list.json'],
            'no extension' => ['no-extension'],
            'preview cache' => ['previews/400x400!/ab/abcdef.webp'],
        ];
    }

    #[DataProvider('nonImagePaths')]
    public function test_the_original_route_serves_only_photos(string $path): void
    {
        $this->expectNoStorageAccess();

        $this->get('/orderhow/'.$path)->assertNotFound();
    }

    public function test_the_preview_cache_cannot_be_resized_directly(): void
    {
        $this->expectNoStorageAccess();

        $this->get('/orderhow/400x400c/previews/400x400!/ab/abcdef.webp')->assertNotFound();
    }

    public function test_a_listed_size_is_served_in_either_crop_notation(): void
    {
        $this->mockService(function (MockInterface $mock): void {
            $mock->shouldReceive('getProcessedImage')->twice()->andReturn('webp-bytes');
        });

        $this->get('/orderhow/400x400c/'.self::PATH)->assertOk();
        $this->get('/orderhow/400x400!/'.self::PATH)->assertOk();
    }

    public function test_an_unlisted_size_is_logged_but_served_while_observing(): void
    {
        Log::spy();
        $this->mockService(function (MockInterface $mock): void {
            $mock->shouldReceive('getProcessedImage')->once()->andReturn('webp-bytes');
        });

        $this->get('/orderhow/137x91/'.self::PATH)->assertOk();

        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'image.size.unlisted'
            && $context['bucket'] === 'orderhow' && $context['size'] === '137x91')->once();
    }

    public function test_an_unlisted_size_is_rejected_when_enforced(): void
    {
        config(['images.enforce_sizes' => true]);
        $this->expectNoStorageAccess();

        $this->get('/orderhow/137x91/'.self::PATH)->assertNotFound();
        $this->get('/orderhow/400x400/'.self::PATH)->assertNotFound();
    }

    public function test_a_listed_original_photo_is_served_with_a_canonical_type_and_cors(): void
    {
        $this->mockService(function (MockInterface $mock): void {
            $mock->shouldReceive('getStorageDisk')->once()->andReturn('bytes');
        });

        $response = $this->get('/orderhow/'.self::PATH);

        $response->assertOk();
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_resized_images_allow_cross_origin_reads(): void
    {
        $this->mockService(function (MockInterface $mock): void {
            $mock->shouldReceive('getProcessedImage')->once()->andReturn('webp-bytes');
        });

        $this->assertSame('*', $this->get('/orderhow/2000x0/'.self::PATH)->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_without_an_allowlist_every_bucket_and_size_is_served(): void
    {
        config(['images.allowed_buckets' => [], 'images.enforce_sizes' => true]);
        $this->mockService(function (MockInterface $mock): void {
            $mock->shouldReceive('getStorageDisk')->once()->andReturn('bytes');
            $mock->shouldReceive('getProcessedImage')->once()->andReturn('webp-bytes');
        });

        $this->get('/disclo-bot/products/a.json')->assertOk();
        $this->get('/orderhow/137x91/'.self::PATH)->assertOk();
    }
}
