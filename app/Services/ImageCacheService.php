<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

class ImageCacheService
{
    protected int $ttl = 604800; // 1주일

    public function getCacheKey(string $bucket, string $path, string $size = ''): string
    {
        return Hash::make("img_{$bucket}_{$path}_{$size}");
    }

    public function get(string $key)
    {
        return Cache::store('redis')->get($key);
    }

    public function put(string $key, $value): void
    {
        Cache::store('redis')->put($key, $value, $this->ttl);
    }

    public function has(string $key): bool
    {
        return Cache::store('redis')->has($key);
    }
}