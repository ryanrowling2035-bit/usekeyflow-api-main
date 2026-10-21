<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class ImageCompressionService
{
    /**
     * Portal ceiling for the compressed variant — PropertyFinder/Bayut accept landscape images
     * up to 1920x1080 and downscale anything larger themselves. Everything that publishes a
     * compressed image must stay inside this box.
     */
    public const MAX_WIDTH = 1920;

    public const MAX_HEIGHT = 1080;

    protected ImageManager $manager;

    protected array $compressibleMimes = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/webp',
        'image/bmp',
        'image/tiff',
        'image/avif',
        'image/heic',
        'image/heif',
    ];

    public function __construct(
        protected FileStorageService $fileStorage
    ) {
        $this->manager = new ImageManager(new Driver);
    }

    public function isCompressible(UploadedFile $file): bool
    {
        return in_array($file->getMimeType(), $this->compressibleMimes);
    }

    /**
     * Compress an image and store both original and compressed versions.
     *
     * @return array{originalPath: string, compressedPath: string, thumbnailPath: string|null}
     */
    public function compressAndStore(UploadedFile $file, string $uniqueName, string $baseFolder = 'properties'): array
    {
        $baseFolder = rtrim($baseFolder, '/');

        if (! $this->isCompressible($file)) {
            $url = $this->fileStorage->uploadPublicFile($file, $baseFolder.'/originals', $uniqueName);

            return [
                'originalPath' => $url,
                'compressedPath' => $url,
                'thumbnailPath' => null,
            ];
        }

        // Store original
        $originalUrl = $this->fileStorage->uploadPublicFile($file, $baseFolder.'/originals', $uniqueName);

        $compressedKey = $baseFolder.'/compressed/'.$uniqueName;

        // Read and process image
        $image = $this->manager->read($file->getPathname());

        // Scale down to fit within the portal box, maintaining aspect ratio (never upscales, no stretch)
        $image->scaleDown(width: self::MAX_WIDTH, height: self::MAX_HEIGHT);

        // Apply watermark scaled to 30% of image width, centered
        $watermark = getScaledWatermarkImage((int) ($image->width() * 0.3));
        if ($watermark) {
            $image->place($watermark, 'center');
        }

        $extension = strtolower(pathinfo($uniqueName, PATHINFO_EXTENSION));

        // Compress and encode (skip compression for small files, but still apply watermark)
        if ($file->getSize() < 153600) {
            // Small file - no compression, but watermark applied
            $encoded = match ($extension) {
                'png' => $image->toPng(),
                'webp' => $image->toWebp(100),
                'avif' => $image->toAvif(100),
                default => $image->toJpeg(100),
            };
        } else {
            // Large file - compress and apply watermark
            $encoded = match ($extension) {
                'png' => $image->toPng(),
                'webp' => $image->toWebp(75),
                'avif' => $image->toAvif(75),
                default => $image->toJpeg(75),
            };
        }

        $compressedUrl = $this->fileStorage->putPublic($compressedKey, (string) $encoded);

        // Generate 150x150 thumbnail
        $thumbnailPath = generateThumbnail($file->getPathname(), $uniqueName, $baseFolder);

        return [
            'originalPath' => $originalUrl,
            'compressedPath' => $compressedUrl,
            'thumbnailPath' => $thumbnailPath,
        ];
    }

    /**
     * Copy an already-stored compressed image to a fresh key beside it, scaling it into the
     * portal box on the way when the source predates that cap (Salesforce-era variants are
     * 1800x1200). Duplicating a listing is otherwise a byte copy, so a legacy oversized photo
     * would keep propagating into new listings — and those get published to the portals.
     *
     * The watermark is already baked into the source and scales with it, so this only resizes.
     * Non-images, unreadable sources and already-compliant images fall back to a plain copy.
     */
    public function copyCappedByUrl(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        $bytes = $this->fileStorage->readBytes($url);
        $size = $bytes !== null ? @getimagesizefromstring($bytes) : false;

        if ($size === false || ($size[0] <= self::MAX_WIDTH && $size[1] <= self::MAX_HEIGHT)) {
            return $this->fileStorage->copyPublicByUrl($url);
        }

        $key = $this->fileStorage->keyFromPublicUrl($url);
        if ($key === null) {
            return $this->fileStorage->copyPublicByUrl($url);
        }

        try {
            $image = $this->manager->read($bytes)->scaleDown(width: self::MAX_WIDTH, height: self::MAX_HEIGHT);
        } catch (\Throwable) {
            return $this->fileStorage->copyPublicByUrl($url);
        }

        $encoded = match (strtolower(pathinfo($key, PATHINFO_EXTENSION))) {
            'png' => $image->toPng(),
            'webp' => $image->toWebp(75),
            'avif' => $image->toAvif(75),
            default => $image->toJpeg(75),
        };

        return $this->fileStorage->putPublic($this->fileStorage->siblingKey($key), (string) $encoded);
    }

    /**
     * Produce the watermarked/compressed and 150x150 thumbnail BYTES for an image without
     * storing them anywhere — used by the manual import to write the variants into a local
     * directory. Mirrors the encode settings of compressAndStore() and generateThumbnail().
     * Returns nulls when the source isn't a compressible image or can't be processed.
     *
     * @return array{compressed: string|null, thumbnail: string|null}
     */
    public function variantBytes(string $sourcePath, string $name, ?int $sourceSize = null): array
    {
        $none = ['compressed' => null, 'thumbnail' => null];
        if (! in_array(@mime_content_type($sourcePath) ?: '', $this->compressibleMimes, true)) {
            return $none;
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $size = $sourceSize ?? (filesize($sourcePath) ?: 0);

        try {
            // Compressed: scale down to fit within the portal box, watermark centered, encode (quality by size).
            $image = $this->manager->read($sourcePath);
            $image->scaleDown(width: self::MAX_WIDTH, height: self::MAX_HEIGHT);
            $watermark = getScaledWatermarkImage((int) ($image->width() * 0.3));
            if ($watermark) {
                $image->place($watermark, 'center');
            }
            $quality = $size < 153600 ? 100 : 75;
            $compressed = match ($extension) {
                'png' => $image->toPng(),
                'webp' => $image->toWebp($quality),
                'avif' => $image->toAvif($quality),
                default => $image->toJpeg($quality),
            };

            // Thumbnail: 150x150 cover.
            $thumb = $this->manager->read($sourcePath)->cover(150, 150);
            $thumbnail = match ($extension) {
                'png' => $thumb->toPng(),
                'webp' => $thumb->toWebp(75),
                'avif' => $thumb->toAvif(75),
                default => $thumb->toJpeg(75),
            };

            return ['compressed' => (string) $compressed, 'thumbnail' => (string) $thumbnail];
        } catch (\Throwable) {
            return $none;
        }
    }
}
