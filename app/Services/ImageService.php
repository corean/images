<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Vips\Driver as VipsDriver;
use Intervention\Image\ImageManager;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ImageService
{
    private ImageManager $imageManager;
    private ImageCacheService $cache;
    private const string PREVIEW_DIR = 'previews';

    public function __construct(ImageCacheService $cache)
    {
        $driver = config('app.image_driver');

        $this->imageManager = ImageManager::withDriver(
            $driver === 'vips' ? VipsDriver::class : GdDriver::class,
        );
        $this->cache = $cache;
    }

    private function getMinioStorage(string $bucket): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::build([
            'driver'                  => 's3',
            'key'                     => config('filesystems.disks.minio.key'),
            'secret'                  => config('filesystems.disks.minio.secret'),
            'region'                  => config('filesystems.disks.minio.region'),
            'bucket'                  => $bucket,
            'url'                     => config('filesystems.disks.minio.url'),
            'endpoint'                => config('filesystems.disks.minio.endpoint'),
            'use_path_style_endpoint' => config('filesystems.disks.minio.use_path_style_endpoint', false),
            'throw'                   => config('filesystems.disks.minio.throw'),
            'root'                    => config('filesystems.disks.minio.root'),
        ]);
    }

    public function getStorageDisk(string $bucket, string $path): string
    {
        try {
            return $this->getMinioStorage($bucket)->get($path);

        } catch (\Exception $e) {
            throw new NotFoundHttpException("Failed to retrieve image: {$e->getMessage()}");
        }
    }

    private function generatePreviewPath(string $path, array $options): string
    {
        $width = $options['width'] ?? 0;
        $height = $options['height'] ?? 0;
        $crop = $options['forceCrop'] ?? false;

        $previewDir = self::PREVIEW_DIR;
        $dimensions = "{$width}x{$height}" . ($crop ? '!' : '');
        $pathHash = md5($path); // 긴 경로를 해시로 변환

        // previews/width_heightCrop/ab/abcdef123456... 형식으로 저장
        // 해시의 앞 2자리로 하위 디렉토리 생성하여 파일 분산 저장
        return "{$previewDir}/{$dimensions}/" .
               substr($pathHash, 0, 2) . '/' .
               $pathHash . '.webp';
    }

    public function getProcessedImage(string $bucket, string $path, ?array $options = null): string
    {
        // 옵션이 없으면 원본 반환
        if (!$options) {
            return $this->getStorageDisk($bucket, $path);
        }

        $previewPath = $this->generatePreviewPath($path, $options);
        $disk = $this->getMinioStorage($bucket);

        try {
            return $disk->get($previewPath);
        } catch (\Throwable $e) {
            //
        }

        // 원본 이미지 로드
        $imageData = $this->getStorageDisk($bucket, $path);

        // 이미지 처리
        $processedImage = $this->processImage(
            $imageData,
            $options['width'],
            $options['height'],
            $options['forceCrop'] ?? false
        );

        // 처리된 이미지를 미리보기 디렉토리에 저장
        try {
            // 디렉토리가 없으면 생성
            $previewDir = dirname($previewPath);
            if (!$disk->exists($previewDir)) {
                $disk->makeDirectory($previewDir);
            }

            $disk->put($previewPath, $processedImage);

            // 메모리 캐시에도 저장
            $cacheKey = $this->cache->getCacheKey($bucket, $path, json_encode($options));
            $this->cache->put($cacheKey, $processedImage);

            return $processedImage;
        } catch (\Exception $e) {
            // 저장 실패시 처리된 이미지만 반환
            \Log::error("Failed to save preview image: {$e->getMessage()}");
            return $processedImage;
        }
    }

    public function processImage(string $imageData, int $width, int $height, bool $crop = false): string
    {
        try {
            $image = $this->imageManager->read($imageData);
            $originalWidth = $image->width();
            $originalHeight = $image->height();

            // 한쪽 차원이 0인 경우의 처리 개선
            if ($width === 0 && $height > 0) {
                // 세로 기준으로 가로 크기 계산
                $ratio = $height / $originalHeight;
                $width = (int)round($originalWidth * $ratio);
            } elseif ($height === 0 && $width > 0) {
                // 가로 기준으로 세로 크기 계산
                $ratio = $width / $originalWidth;
                $height = (int)round($originalHeight * $ratio);
            }

            // 계산된 크기가 원본보다 크면 원본 크기 사용
            if ($width > $originalWidth) {
                $ratio = $originalWidth / $width;
                $width = $originalWidth;
                $height = (int)round($height * $ratio);
            }
            if ($height > $originalHeight) {
                $ratio = $originalHeight / $height;
                $height = $originalHeight;
                $width = (int)round($width * $ratio);
            }

            // 최소 크기 보장
            $width = max($width, 1);
            $height = max($height, 1);

            try {
                if ($crop) {
                    $image->cover($width, $height);
                } else {
                    $image->resize($width, $height, function ($constraint) {
                        $constraint->aspectRatio();
                        $constraint->upsize();
                    });
                }

                return $image->toWebp(80);
            } catch (\Exception $e) {
                \Log::error("Image resize failed: {$e->getMessage()}", [
                    'originalWidth'  => $originalWidth,
                    'originalHeight' => $originalHeight,
                    'targetWidth'    => $width,
                    'targetHeight'   => $height,
                ]);
                throw new \InvalidArgumentException("Image processing failed: {$e->getMessage()}");
            }
        } catch (\Exception $e) {
            throw new \RuntimeException("Image processing failed: {$e->getMessage()}");
        }
    }
}