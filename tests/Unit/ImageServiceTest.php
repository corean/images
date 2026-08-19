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
