<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('image')->group(base_path('routes/image.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /**
         * 이미지 서빙 전용 그룹. 세션·CSRF 쿠키를 붙이지 않는다.
         *
         * 바이너리 응답에 Set-Cookie 가 실리면 응답당 약 900B 가 낭비되고,
         * 공용 캐시·CDN 이 해당 응답을 캐시 불가로 취급한다.
         * 레이트 리밋이 필요하면 내장 throttle 미들웨어를 여기에 넣는다.
         */
        $middleware->group('image', []);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
