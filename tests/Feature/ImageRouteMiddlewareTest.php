<?php

namespace Tests\Feature;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 이미지 라우트가 세션·쿠키 미들웨어를 타지 않는지 고정한다.
 *
 * web 그룹에 있던 시절 모든 이미지 응답에 XSRF-TOKEN·laravel_session 쿠키가
 * 약 900B 씩 실렸다. 바이너리 응답에 Set-Cookie 가 붙으면 대역폭 낭비이고,
 * 공용 캐시·CDN 이 그 응답을 캐시 불가로 취급한다.
 */
class ImageRouteMiddlewareTest extends TestCase
{
    private const array SESSION_MIDDLEWARE = [
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        ValidateCsrfToken::class,
    ];

    /**
     * @return array<string, array<int, string>>
     */
    public static function imageRouteNames(): array
    {
        return [
            'resize' => ['image.resize'],
            'show' => ['image.show'],
        ];
    }

    #[DataProvider('imageRouteNames')]
    public function test_image_routes_use_the_image_group_not_web(string $name): void
    {
        $route = Route::getRoutes()->getByName($name);

        $this->assertNotNull($route, "라우트 {$name} 이 등록되어 있지 않다.");
        $this->assertContains('image', $route->middleware());
        $this->assertNotContains('web', $route->middleware());
    }

    public function test_image_group_carries_no_session_middleware(): void
    {
        $groups = app(Kernel::class)->getMiddlewareGroups();

        $this->assertArrayHasKey('image', $groups);

        foreach (self::SESSION_MIDDLEWARE as $middleware) {
            $this->assertNotContains($middleware, $groups['image']);
        }
    }

    /**
     * 그룹 구성만 보는 단언은 미들웨어 이름이 바뀌면 조용히 통과한다.
     * 실제 응답에 Set-Cookie 가 실리는지로 확인한다.
     */
    public function test_image_group_response_sets_no_cookies(): void
    {
        Route::middleware('image')->get('/__image_middleware_probe', fn () => response('ok'));

        $response = $this->get('/__image_middleware_probe');

        $response->assertOk();
        $this->assertSame([], $response->headers->getCookies());
    }

    /**
     * 위 단언이 무의미하지 않음을 보이는 대조군. web 그룹은 쿠키를 붙인다.
     */
    public function test_web_group_response_does_set_cookies(): void
    {
        Route::middleware('web')->get('/__web_middleware_probe', fn () => response('ok'));

        $response = $this->get('/__web_middleware_probe');

        $response->assertOk();
        $this->assertNotSame([], $response->headers->getCookies());
    }
}
