<?php

namespace App\Http\Controllers;

use App\Services\ImageService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ImageController extends Controller
{
    private ImageService $imageService;

    public function __construct(ImageService $imageService)
    {
        $this->imageService = $imageService;
    }

    public function show(Request $request, string $bucket, string $path): Response
    {
        $imageData = $this->imageService->getStorageDisk($bucket, $path);

        return new Response($imageData, 200, [
            'Content-Type' => 'image/'.pathinfo($path, PATHINFO_EXTENSION),
            'Cache-Control' => 'public, max-age=31536000',
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
}
