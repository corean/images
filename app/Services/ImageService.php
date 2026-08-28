<?php

namespace App\Services;

use Aws\Exception\AwsException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Vips\Driver as VipsDriver;
use Intervention\Image\ImageManager;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

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
        return $this->disks[$bucket] ??= Storage::build($this->minioDiskConfig($bucket));
    }

    /**
     * @return array<string, mixed>
     */
    private function minioDiskConfig(string $bucket): array
    {
        return [
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
            'http' => config('filesystems.disks.minio.http'),
            'retries' => config('filesystems.disks.minio.retries'),
        ];
    }

    public function getStorageDisk(string $bucket, string $path): string
    {
        try {
            return $this->getMinioStorage($bucket)->get($path);
        } catch (\Throwable $e) {
            throw $this->translateStorageFailure($e, $bucket, $path);
        }
    }

    /**
     * 스토리지 예외를 원인에 맞는 HTTP 예외로 바꾼다.
     *
     * 전에는 권한 오류·연결 실패·오브젝트 없음이 전부 404 하나로 뭉개졌다.
     * NotFoundHttpException 은 기본 리포트 대상이 아니라 로그에도 남지 않아,
     * 설정 오류를 원인 불명 404 로 진단해야 했다. 오브젝트가 실제로 없는
     * 경우(NoSuchKey/NoSuchBucket)만 404 로 두고, 그 외는 500/503 으로
     * 구분해 로그에 남긴다.
     */
    private function translateStorageFailure(\Throwable $e, string $bucket, string $path): HttpExceptionInterface
    {
        $aws = $this->findAwsException($e);

        if ($aws?->isConnectionError()) {
            Log::error("Failed to reach storage for {$bucket}/{$path}: {$e->getMessage()}");

            return new ServiceUnavailableHttpException(null, "Failed to retrieve image: {$e->getMessage()}", $e);
        }

        $code = $aws?->getAwsErrorCode();

        if ($code !== null && ! in_array($code, ['NoSuchKey', 'NoSuchBucket'], true)) {
            Log::error("Storage access error for {$bucket}/{$path} ({$code}): {$e->getMessage()}");

            return new HttpException(500, "Failed to retrieve image: {$e->getMessage()}", $e);
        }

        return new NotFoundHttpException("Failed to retrieve image: {$e->getMessage()}", $e);
    }

    private function findAwsException(\Throwable $e): ?AwsException
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof AwsException) {
                return $current;
            }
        }

        return null;
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
     * 미리보기의 ETag. 저장 경로만으로 결정되므로 바이트를 읽기 전에 계산된다.
     *
     * 전에는 md5(처리된 바이트) 라서 조건부 요청이 304 로 끝나는 경우에도
     * 미리보기 전체를 MinIO 에서 받아야 했다. 미리보기 경로는
     * (원본 경로, 폭, 높이, 크롭) 의 순수 함수이고 그 조합의 내용은 이미
     * 미리보기 캐시에 고정돼 있으므로, 경로 기반 ETag 는 바이트 기반과
     * 같은 것을 식별하면서 왕복만 없앤다.
     *
     * 버킷을 넣는 이유는 미리보기 경로에 버킷이 들어가지 않아서다.
     * ETag 는 URL 단위로 검증되므로 버킷이 달라도 충돌하지 않지만,
     * 같은 값이 서로 다른 내용을 가리키는 상태를 만들지 않는다.
     *
     * @param  array{width: int, height: int, forceCrop?: bool}  $options
     */
    public function previewETag(string $bucket, string $path, array $options): string
    {
        return md5($bucket.'/'.$this->generatePreviewPath($path, $options));
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
