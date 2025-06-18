# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Laravel 11 application that provides an image service with resizing capabilities. The application acts as a proxy to MinIO (S3-compatible) storage, providing on-demand image processing and caching.

### Key Architecture Components

- **ImageController**: Handles HTTP requests for image retrieval and resizing
- **ImageService**: Core service for image processing using Intervention Image with VIPS/GD drivers
- **ImageCacheService**: Manages Redis-based caching for processed images
- **MinIO Integration**: S3-compatible storage for original and processed images

## Development Commands

### Installation & Setup
```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

### Development Server
```bash
# Full development stack (server, queue, logs, vite)
composer run dev

# Individual services
php artisan serve
php artisan queue:listen --tries=1
php artisan pail --timeout=0
npm run dev
```

### Build & Production
```bash
npm run build
```

### Testing
```bash
php artisan test
./vendor/bin/phpunit
```

### Code Quality
```bash
./vendor/bin/pint  # Laravel Pint for code formatting
```

## Image Processing Architecture

### URL Structure
- `/{bucket}/{path}` - Original image
- `/{bucket}/{size}/{path}` - Resized image
  - Size format: `{width}x{height}` or `{width}x{height}!` (forced crop)
  - Example: `/my-bucket/300x200/path/to/image.jpg`

### Storage Layout
- **Original images**: Stored in MinIO buckets
- **Processed images**: Cached in `previews/{dimensions}/{hash[0:2]}/{hash}.webp`
- **Memory cache**: Redis with 1-week TTL

### Image Processing Options
- Maximum dimensions: 3000x3000px
- Supported formats: Input (various) → Output (WebP)
- Features: Aspect ratio preservation, upsize prevention, force crop mode

## Configuration

### Environment Variables
Key variables for image processing:
- `MINIO_*`: MinIO storage configuration
- `REDIS_*`: Redis cache configuration
- `APP_IMAGE_DRIVER`: Image driver (vips/gd)

### Rate Limiting
- 100 requests per minute per IP for image endpoints
- Configured in `ImageRateLimit` middleware

## Key Files

### Core Application
- `app/Http/Controllers/ImageController.php` - Image request handling
- `app/Services/ImageService.php` - Image processing logic
- `app/Services/ImageCacheService.php` - Caching layer
- `app/Http/Middleware/ImageRateLimit.php` - Rate limiting

### Configuration
- `config/filesystems.php` - Storage configuration
- `routes/web.php` - Image routing patterns

### Dependencies
- `intervention/image-driver-vips` - High-performance image processing
- `aws/aws-sdk-php` - S3/MinIO connectivity
- `league/flysystem-aws-s3-v3` - Filesystem abstraction

## Image Driver Configuration

The application supports both VIPS (recommended) and GD image drivers:
- VIPS: Higher performance, better memory usage
- GD: Fallback option, wider compatibility
- Set via `APP_IMAGE_DRIVER` environment variable