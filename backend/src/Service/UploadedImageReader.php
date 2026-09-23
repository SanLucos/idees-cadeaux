<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ApiProblemException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Shared upload checks (spec §9): valid upload, 5 MB max, JPEG/PNG/WebP
 * only, and actually decodable. Callers re-encode from the returned
 * pixels, which is what strips EXIF: GD never carries source metadata
 * into the image it writes out.
 */
final class UploadedImageReader
{
    private const int MAX_BYTES = 5 * 1024 * 1024;
    private const array ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function read(UploadedFile $file): \GdImage
    {
        if (!$file->isValid()) {
            throw new ApiProblemException('upload.invalid', 'Upload failed.', 422);
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw new ApiProblemException('upload.too_large', 'File exceeds the 5 MB limit.', 422);
        }
        if (!\in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            throw new ApiProblemException('upload.invalid_type', 'Only JPEG, PNG and WebP images are accepted.', 422);
        }

        $image = @imagecreatefromstring((string) file_get_contents($file->getPathname()));
        if (false === $image) {
            throw new ApiProblemException('upload.invalid_image', 'The file is not a readable image.', 422);
        }

        return $image;
    }

    public static function toJpeg(\GdImage $image, int $quality = 85): string
    {
        ob_start();
        imagejpeg($image, null, $quality);

        return (string) ob_get_clean();
    }

    /** Centre-cropped square. */
    public static function square(\GdImage $source, int $size): \GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $crop = min($width, $height);

        $target = imagecreatetruecolor($size, $size);
        imagecopyresampled($target, $source, 0, 0, (int) (($width - $crop) / 2), (int) (($height - $crop) / 2), $size, $size, $crop, $crop);

        return $target;
    }

    /** Scaled down (never up) to fit within `$maxSide`, aspect ratio kept. */
    public static function fit(\GdImage $source, int $maxSide): \GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $ratio = min(1, $maxSide / max($width, $height));
        $targetWidth = max(1, (int) round($width * $ratio));
        $targetHeight = max(1, (int) round($height * $ratio));

        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $target;
    }
}
