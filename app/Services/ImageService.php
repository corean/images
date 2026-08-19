<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Vips\Driver as VipsDriver;
use Intervention\Image\ImageManager;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ImageService
{
    private const string PREVIEW_DIR = 'previews';

    private ?ImageManager $imageManager = null;

    /**
     * 버킷별 S3 디스크. 한 요청에서 원본과 미리보기를 모두 읽으므로
     * 재사용하지 않으면 S3 클라이언트를 두 번 만들게 된다.
     *
     * @var array<string, Filesystem>
     */
    private array $disks = [];

    private function getImageManager(): ImageManager
    {
        return $this->imageManager ??= ImageManager::withDriver(
            config('app.image_driver') === 'vips' ? VipsDriver::class : GdDriver::class,
        );
    }

    private function getMinioStorage(string $bucket): Filesystem
    {
        return $this->disks[$bucket] ??= Storage::build([
            'driver' => 's3',
            'key' => config('filesystems.disks.minio.key'),
            'secret' => config('filesystems.disks.minio.secret'),
            'region' => config('filesystems.disks.minio.region'),
            'bucket' => $bucket,
            'url' => config('filesystems.disks.minio.url'),
            'endpoint' => config('filesystems.disks.minio.endpoint'),
            'use_path_style_endpoint' => config('filesystems.disks.minio.use_path_style_endpoint', false),
            'throw' => config('filesystems.disks.minio.throw'),
            'root' => config('filesystems.disks.minio.root'),
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

    /**
     * @param  array{width: int, height: int, forceCrop?: bool}  $options
     */
    private function generatePreviewPath(string $path, array $options): string
    {
        $width = $options['width'] ?? 0;
        $height = $options['height'] ?? 0;
        $crop = $options['forceCrop'] ?? false;

        $previewDir = self::PREVIEW_DIR;
        $dimensions = "{$width}x{$height}".($crop ? '!' : '');
        $pathHash = md5($path);

        return "{$previewDir}/{$dimensions}/".
               substr($pathHash, 0, 2).'/'.
               $pathHash.'.webp';
    }

    /**
     * @param  array{width: int, height: int, forceCrop?: bool}|null  $options
     */
    public function getProcessedImage(string $bucket, string $path, ?array $options = null): string
    {
        if (! $options) {
            return $this->getStorageDisk($bucket, $path);
        }

        $previewPath = $this->generatePreviewPath($path, $options);
        $disk = $this->getMinioStorage($bucket);

        try {
            return $disk->get($previewPath);
        } catch (\Throwable $e) {
            //
        }

        $imageData = $this->getStorageDisk($bucket, $path);

        $processedImage = $this->processImage(
            $imageData,
            $options['width'],
            $options['height'],
            $options['forceCrop'] ?? false
        );

        try {
            $disk->put($previewPath, $processedImage);
        } catch (\Exception $e) {
            \Log::error("Failed to save preview image: {$e->getMessage()}");
        }

        return $processedImage;
    }

    public function processImage(string $imageData, int $width, int $height, bool $crop = false): string
    {
        try {
            $image = $this->getImageManager()->read($imageData);
            $originalWidth = $image->width();
            $originalHeight = $image->height();

            // 한쪽 차원이 0인 경우의 처리 개선
            if ($width === 0 && $height > 0) {
                $ratio = $height / $originalHeight;
                $width = (int) round($originalWidth * $ratio);
            } elseif ($height === 0 && $width > 0) {
                $ratio = $width / $originalWidth;
                $height = (int) round($originalHeight * $ratio);
            }

            // 계산된 크기가 원본보다 크면 원본 크기 사용
            if ($width > $originalWidth) {
                $ratio = $originalWidth / $width;
                $width = $originalWidth;
                $height = (int) round($height * $ratio);
            }
            if ($height > $originalHeight) {
                $ratio = $originalHeight / $height;
                $height = $originalHeight;
                $width = (int) round($width * $ratio);
            }

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
                    'originalWidth' => $originalWidth,
                    'originalHeight' => $originalHeight,
                    'targetWidth' => $width,
                    'targetHeight' => $height,
                ]);
                throw new \InvalidArgumentException("Image processing failed: {$e->getMessage()}");
            }
        } catch (\Exception $e) {
            throw new \RuntimeException("Image processing failed: {$e->getMessage()}");
        }
    }
}
