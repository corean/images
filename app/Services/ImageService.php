<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ImageService
{
    private ImageManager $imageManager;

    public function __construct()
    {
        $this->imageManager = ImageManager::withDriver(GdDriver::class);
    }

    public function getStorageDisk(string $bucket, string $path): string
    {
        try {
            $disk = Storage::build([
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

            if ( ! $disk->exists($path)) {
                throw new NotFoundHttpException("Image not found at path: {$path}");
            }

            return $disk->get($path);
        } catch (\Exception $e) {
            throw new NotFoundHttpException("Failed to retrieve image: {$e->getMessage()}");
        }
    }

    public function processImage(string $imageData, int $width, int $height, bool $crop = false): string
    {
        try {
            $image = $this->imageManager->read($imageData);


            // 리사이즈 로직 개선
            try {
                if ($crop) {
                    $image->cover($width, $height); // 비율 유지하며 지정 크기에 맞춰 크롭
                } else {
                    $image->resize($width, $height, function ($constraint) {
                        $constraint->aspectRatio();  // 비율 유지
                        $constraint->upsize();       // 원본보다 크게 리사이즈 방지
                    });
                }
            } catch (\Exception $e) {
                throw new \InvalidArgumentException("Image processing failed: {$e->getMessage()}");
            }

            return $image->toWebp(80); // WebP 포맷으로 변환, 품질 80으로 설정
        } catch (\Exception $e) {
            throw new \RuntimeException("Image processing failed: {$e->getMessage()}");
        }
    }
}
