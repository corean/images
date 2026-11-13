<?php

namespace App\Http\Controllers;

use App\Services\ImageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ImageController extends Controller
{
    private const string PREVIEW_PREFIX = 'previews/';

    /**
     * 확장자와 정식 MIME 서브타입이 다른 것. 그 밖은 확장자를 그대로 쓴다.
     *
     * @var array<string, string>
     */
    private const array CONTENT_SUBTYPES = ['jpg' => 'jpeg', 'tif' => 'tiff'];

    private ImageService $imageService;

    public function __construct(ImageService $imageService)
    {
        $this->imageService = $imageService;
    }

    public function show(Request $request, string $bucket, string $path): Response
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        if ($this->restricted()) {
            $this->ensureBucketAllowed($bucket);
            $this->ensureNotPreviewCache($path);

            if (! in_array(strtolower($extension), config('images.original_extensions', []), true)) {
                throw new NotFoundHttpException;
            }
        }

        $imageData = $this->imageService->getStorageDisk($bucket, $path);

        return new Response($imageData, 200, [
            'Content-Type' => 'image/'.(self::CONTENT_SUBTYPES[strtolower($extension)] ?? $extension),
            'Cache-Control' => 'public, max-age=31536000',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    public function resize(Request $request, string $bucket, string $size, string $path): Response
    {
        $size = urldecode($size);
        if (! preg_match('/^(\d+)x(\d+)(c|!)?$/', $size, $matches)) {
            throw new \InvalidArgumentException('Invalid size parameter format');
        }

        $width = (int) $matches[1];
        $height = (int) $matches[2];
        $forceCrop = isset($matches[3]) && in_array($matches[3], ['c', '!'], true);

        if ($this->restricted()) {
            $this->ensureBucketAllowed($bucket);
            $this->ensureNotPreviewCache($path);
            $this->checkSizeListed($bucket, $size, $width, $height, $forceCrop);
        }

        // Validate dimensions
        if (($width > 3000) || ($height > 3000)) {
            throw new \InvalidArgumentException('Maximum dimension exceeded');
        }

        if ($width === 0 && $height === 0) {
            throw new \InvalidArgumentException('Both dimensions cannot be zero');
        }

        $options = [
            'width' => $width,
            'height' => $height,
            'forceCrop' => $forceCrop,
            'maintainAspectRatio' => true,
        ];

        // If both dimensions are specified and forceCrop is true,
        // we don't maintain aspect ratio
        if ($width > 0 && $height > 0 && $forceCrop) {
            $options['maintainAspectRatio'] = false;
        }

        $headers = [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'public, max-age=31536000',
            'ETag' => $this->imageService->previewETag($bucket, $path, $options),
            'Vary' => 'Accept',
            'Access-Control-Allow-Origin' => '*',
        ];

        // ETag 는 미리보기 경로에서 나오므로 여기서 이미 확정된다.
        // 스토리지를 건드리기 전에 조건부 요청을 끝낸다.
        if ($request->header('If-None-Match') === $headers['ETag']) {
            return new Response(null, 304, $headers);
        }

        return new Response(
            $this->imageService->getProcessedImage($bucket, $path, $options),
            200,
            $headers,
        );
    }

    /**
     * 허용 목록을 쓰는 서버(cloudv 오더하우 전용)인지. CT117 은 목록 없이 모든 버킷을 서빙한다.
     */
    private function restricted(): bool
    {
        return config('images.allowed_buckets', []) !== [];
    }

    private function ensureBucketAllowed(string $bucket): void
    {
        if (! in_array($bucket, config('images.allowed_buckets', []), true)) {
            throw new NotFoundHttpException;
        }
    }

    /**
     * 미리보기 캐시는 리사이즈 라우트가 만들어 주는 것이지 직접 열람하는 대상이 아니다.
     */
    private function ensureNotPreviewCache(string $path): void
    {
        if (str_starts_with($path, self::PREVIEW_PREFIX)) {
            throw new NotFoundHttpException;
        }
    }

    /**
     * 목록 밖 크기는 강제 모드면 404, 아니면 로그만 남기고 처리한다. 목록이 없는 버킷은 검사하지 않는다.
     */
    private function checkSizeListed(string $bucket, string $size, int $width, int $height, bool $forceCrop): void
    {
        $listed = config("images.sizes.{$bucket}");
        if (! is_array($listed)) {
            return;
        }

        foreach ($listed as $entry) {
            if (preg_match('/^(\d+)x(\d+)(c|!)?$/', $entry, $parts)
                && (int) $parts[1] === $width && (int) $parts[2] === $height && isset($parts[3]) === $forceCrop) {
                return;
            }
        }

        if (config('images.enforce_sizes', false)) {
            throw new NotFoundHttpException;
        }

        Log::info('image.size.unlisted', ['bucket' => $bucket, 'size' => $size]);
    }
}
