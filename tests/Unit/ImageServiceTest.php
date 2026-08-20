<?php

namespace Tests\Unit;

use App\Services\ImageService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Tests\TestCase;

/**
 * 리사이즈 동작과 S3 디스크 재사용을 고정한다.
 *
 * 테스트는 vips 확장에 의존하지 않도록 GD 드라이버로 강제한다.
 */
class ImageServiceTest extends TestCase
{
    private function service(): ImageService
    {
        config()->set('app.image_driver', 'gd');

        return new ImageService;
    }

    private function pngFixture(int $width, int $height): string
    {
        $canvas = imagecreatetruecolor($width, $height);
        imagefilledrectangle($canvas, 0, 0, $width, $height, imagecolorallocate($canvas, 200, 30, 30));

        ob_start();
        imagepng($canvas);
        imagedestroy($canvas);

        return (string) ob_get_clean();
    }

    private function dimensions(string $binary): array
    {
        $info = getimagesizefromstring($binary);

        return [$info[0], $info[1]];
    }

    public function test_it_encodes_output_as_webp(): void
    {
        $result = $this->service()->processImage($this->pngFixture(600, 600), 200, 200, true);

        $this->assertSame(IMAGETYPE_WEBP, getimagesizefromstring($result)[2]);
    }

    public function test_it_downscales_to_the_requested_size(): void
    {
        $result = $this->service()->processImage($this->pngFixture(600, 600), 400, 400, true);

        $this->assertSame([400, 400], $this->dimensions($result));
    }

    /**
     * 원본보다 큰 요청은 원본 크기에서 멈춘다. 이 성질 덕분에 호출측이 저해상도
     * 원본을 신경 쓰지 않고 큰 사이즈를 요청할 수 있다.
     */
    public function test_it_never_upscales_beyond_the_source(): void
    {
        $result = $this->service()->processImage($this->pngFixture(300, 300), 400, 400, true);

        $this->assertSame([300, 300], $this->dimensions($result));
    }

    /**
     * crop=false 경로는 지금까지 테스트가 없었다.
     *
     * 코드는 resize() 에 aspectRatio·upsize 제약 클로저를 넘기지만 그건
     * Intervention v2 API 다. 설치된 v3 의 resize(?int, ?int) 는 3번째 인자를
     * 받지 않고, PHP 는 사용자 정의 메서드의 초과 인자를 조용히 버린다.
     * 결과적으로 클로저는 죽은 코드이고 resize 는 비율을 무시하는 하드
     * 리사이즈로 동작한다 — 2:1 원본이 1:1 로 늘어난다.
     *
     * 현행 동작을 고정해 둔다. 수정은 별도 작업이다.
     *
     * @see docs/work/test-coverage/research.md
     */
    public function test_it_stretches_the_image_when_crop_is_disabled(): void
    {
        $result = $this->service()->processImage($this->pngFixture(600, 300), 200, 200, false);

        $this->assertSame([200, 200], $this->dimensions($result));
    }

    /**
     * 한쪽 차원만 주면 나머지는 원본 비율로 계산된다. 이 계산은 Intervention
     * 이 아니라 processImage 가 직접 하므로 위의 결함과 무관하게 비율이 유지된다.
     */
    public function test_it_derives_the_width_from_the_height(): void
    {
        $result = $this->service()->processImage($this->pngFixture(600, 300), 0, 150, false);

        $this->assertSame([300, 150], $this->dimensions($result));
    }

    public function test_it_derives_the_height_from_the_width(): void
    {
        $result = $this->service()->processImage($this->pngFixture(600, 300), 300, 0, false);

        $this->assertSame([300, 150], $this->dimensions($result));
    }

    /**
     * 계산된 크기가 0 으로 떨어져도 1px 로 올려 인코딩 실패를 막는다.
     */
    public function test_it_clamps_a_degenerate_dimension_to_one_pixel(): void
    {
        $result = $this->service()->processImage($this->pngFixture(600, 10), 30, 0, false);

        $this->assertSame([30, 1], $this->dimensions($result));
    }

    public function test_it_rejects_data_that_is_not_an_image(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Image processing failed');

        $this->service()->processImage('this-is-not-an-image', 200, 200, true);
    }

    /**
     * 콜드 요청은 미리보기와 원본을 모두 읽는다. 디스크를 재사용하지 않으면
     * 요청 한 번에 S3 클라이언트를 두 번 만들게 된다.
     */
    public function test_it_reuses_the_storage_disk_per_bucket(): void
    {
        $service = $this->service();

        $method = new \ReflectionMethod($service, 'getMinioStorage');
        $method->setAccessible(true);

        $first = $method->invoke($service, 'orderhow');
        $second = $method->invoke($service, 'orderhow');
        $other = $method->invoke($service, 'other-bucket');

        $this->assertInstanceOf(Filesystem::class, $first);
        $this->assertSame($first, $second);
        $this->assertNotSame($first, $other);
    }

    /**
     * 미리보기 경로는 경로·크기·크롭 여부만으로 결정되어야 한다.
     * 예전 캐시 키는 bcrypt(랜덤 솔트)라 같은 입력에도 매번 달라졌다.
     */
    public function test_preview_path_is_deterministic(): void
    {
        $service = $this->service();

        $method = new \ReflectionMethod($service, 'generatePreviewPath');
        $method->setAccessible(true);

        $options = ['width' => 400, 'height' => 400, 'forceCrop' => true];

        $this->assertSame(
            $method->invoke($service, 'cropped-images/1-abc.jpg', $options),
            $method->invoke($service, 'cropped-images/1-abc.jpg', $options),
        );

        $this->assertNotSame(
            $method->invoke($service, 'cropped-images/1-abc.jpg', $options),
            $method->invoke($service, 'cropped-images/1-abc.jpg', ['width' => 200, 'height' => 200, 'forceCrop' => true]),
        );
    }
}
