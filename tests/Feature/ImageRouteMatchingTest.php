<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * 두 이미지 라우트의 매칭 경계를 고정한다.
 *
 * resize 와 show 는 세그먼트 수만 다르고 path 가 둘 다 .* 라서, 사이즈 패턴이
 * 어디까지를 사이즈로 보는지가 곧 라우팅 결과를 결정한다. 컨트롤러를 타지 않고
 * 라우터에만 물어보므로 서비스 mock 이 필요 없다.
 */
class ImageRouteMatchingTest extends TestCase
{
    /**
     * @return array{name: string, parameters: array<string, string>}
     */
    private function matchRoute(string $uri): array
    {
        $route = Route::getRoutes()->match(Request::create($uri, 'GET'));

        return [
            'name' => (string) $route->getName(),
            'parameters' => $route->parameters(),
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function resizeSizeSegments(): array
    {
        return [
            'plain' => ['400x300'],
            'crop with c' => ['400x300c'],
            'crop with bang' => ['400x300!'],
            'crop with encoded bang' => ['400x300%21'],
            'zero width' => ['0x300'],
            'oversized' => ['9999x9999'],
        ];
    }

    /**
     * 사이즈 패턴에 맞는 2번째 세그먼트는 resize 로 간다. 상한 초과(9999)도
     * 라우트는 통과하고 컨트롤러가 거부하는 구조다.
     */
    #[DataProvider('resizeSizeSegments')]
    public function test_a_size_like_segment_routes_to_resize(string $size): void
    {
        $matched = $this->matchRoute('/orderhow/'.$size.'/cropped-images/1-abc.jpg');

        $this->assertSame('image.resize', $matched['name']);
        $this->assertSame('orderhow', $matched['parameters']['bucket']);
        $this->assertSame('cropped-images/1-abc.jpg', $matched['parameters']['path']);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function nonSizeSegments(): array
    {
        return [
            'plain word' => ['thumbnails'],
            'single dimension' => ['400'],
            'unknown crop flag' => ['400x300z'],
            'dimensions with a dash' => ['400-300'],
        ];
    }

    /**
     * 사이즈 패턴에 맞지 않는 2번째 세그먼트는 resize 가 아니라 show 의 path 로
     * 흡수된다. 즉 오타 난 사이즈는 400 이 아니라 "그런 이름의 원본을 찾는"
     * 요청이 되고, 원본이 없으니 404 가 된다.
     */
    #[DataProvider('nonSizeSegments')]
    public function test_a_non_size_segment_is_absorbed_into_the_show_path(string $segment): void
    {
        $matched = $this->matchRoute('/orderhow/'.$segment.'/1-abc.jpg');

        $this->assertSame('image.show', $matched['name']);
        $this->assertSame('orderhow', $matched['parameters']['bucket']);
        $this->assertSame($segment.'/1-abc.jpg', $matched['parameters']['path']);
    }

    /**
     * path 는 .* 라서 슬래시를 포함한 다단계 경로를 통째로 받는다.
     * 버킷 안의 디렉터리 구조를 그대로 프록시하는 것이 이 앱의 목적이다.
     */
    public function test_the_path_captures_multiple_segments(): void
    {
        $matched = $this->matchRoute('/orderhow/400x300/user-images/2026/08/a-b-c.jpg');

        $this->assertSame('image.resize', $matched['name']);
        $this->assertSame('user-images/2026/08/a-b-c.jpg', $matched['parameters']['path']);
    }

    public function test_the_show_path_captures_multiple_segments(): void
    {
        $matched = $this->matchRoute('/orderhow/user-images/2026/08/a-b-c.jpg');

        $this->assertSame('image.show', $matched['name']);
        $this->assertSame('user-images/2026/08/a-b-c.jpg', $matched['parameters']['path']);
    }

    /**
     * resize 는 세그먼트 3개를 요구하므로, 사이즈처럼 보이는 세그먼트가
     * 마지막이면 그건 사이즈가 아니라 파일 이름이다.
     */
    public function test_a_trailing_size_like_segment_is_a_filename(): void
    {
        $matched = $this->matchRoute('/orderhow/400x300');

        $this->assertSame('image.show', $matched['name']);
        $this->assertSame('400x300', $matched['parameters']['path']);
    }

    /**
     * 버킷만 있는 요청은 매칭될 라우트가 없다. path 가 .* 라 빈 문자열도
     * 받을 것처럼 보이지만, 세그먼트가 없으면 라우트 자체가 성립하지 않는다.
     */
    public function test_a_bucket_without_a_path_matches_nothing(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->matchRoute('/orderhow');
    }

    /**
     * 헬스체크는 이미지 라우트보다 먼저 등록되어 있어야 한다. /up 이
     * show 라우트의 bucket 으로 먹히면 기동 확인 수단이 사라진다.
     */
    public function test_the_health_check_is_not_shadowed_by_the_image_routes(): void
    {
        $this->get('/up')->assertOk();
    }
}
