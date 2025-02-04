<?php

namespace App\Http\Middleware;

use Illuminate\Cache\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class ImageRateLimit
{
    protected $limiter;

    public function __construct(RateLimiter $limiter)
    {
        $this->limiter = $limiter;
    }

    public function handle($request, \Closure $next)
    {
        $key = 'image_' . $request->ip();

        if ($this->limiter->tooManyAttempts($key, 100)) { // 분당 100개 제한
            return response()->json([
                'error' => 'Too many requests',
                'retry_after' => $this->limiter->availableIn($key)
            ], 429);
        }

        $this->limiter->hit($key, 60); // 60초 동안 기록 유지

        return $next($request);
    }
}