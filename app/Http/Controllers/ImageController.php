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
            'Content-Type' => 'image/' . pathinfo($path, PATHINFO_EXTENSION),
            'Cache-Control' => 'public, max-age=31536000',
        ]);
    }

    public function resize(Request $request, string $size, string $bucket, string $path): Response
    {
        if (!preg_match('/^(\d+)x(\d+)(!)?$/', $size, $matches)) {
            throw new \InvalidArgumentException('Invalid size parameter format');
        }

        $width = (int)$matches[1];
        $height = (int)$matches[2];
        $forceCrop = isset($matches[3]) && $matches[3] === '!'; // 크롭 여부

        // 최대 크기 제한 설정
        if ($width > 3000 || $height > 3000) {
            throw new \InvalidArgumentException('Maximum dimension exceeded');
        }

        $options = [
            'width'     => $width,
            'height'    => $height,
            'forceCrop' => $forceCrop,
        ];
        ray($options)->label('options');

        $processedImage = $this->imageService->getProcessedImage($bucket, $path, $options);

        return new Response($processedImage, 200, [
            'Content-Type'  => 'image/webp',
            'Cache-Control' => 'public, max-age=31536000',
            'ETag'          => md5($processedImage),
        ]);
    }
}