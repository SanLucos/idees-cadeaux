<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Exception\ApiProblemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Avatar upload (spec §5.2, §9): MIME/size validated, resized to a
 * fixed square, and re-encoded from raw pixel data — which is also
 * what strips EXIF, since GD never carries source metadata into the
 * image it writes out.
 */
final class AvatarUploadService
{
    private const int MAX_BYTES = 5 * 1024 * 1024;
    private const int TARGET_SIZE = 512;
    private const array ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        #[Autowire(service: 'default.storage')]
        private readonly FilesystemOperator $storage,
    ) {
    }

    public function upload(User $user, UploadedFile $file): string
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

        $source = @imagecreatefromstring((string) file_get_contents($file->getPathname()));
        if (false === $source) {
            throw new ApiProblemException('upload.invalid_image', 'The file is not a readable image.', 422);
        }

        $square = self::resizeToSquare($source, self::TARGET_SIZE);
        imagedestroy($source);

        ob_start();
        imagejpeg($square, null, 85);
        $binary = (string) ob_get_clean();
        imagedestroy($square);

        $previous = $user->getAvatarPath();

        $path = \sprintf('avatars/%s-%s.jpg', $user->getId()->toRfc4122(), bin2hex(random_bytes(4)));
        $this->storage->write($path, $binary, ['visibility' => 'public', 'mimetype' => 'image/jpeg']);

        if (null !== $previous) {
            try {
                $this->storage->delete($previous);
            } catch (\Throwable) {
                // Best-effort cleanup; an orphaned object is not worth failing the upload for.
            }
        }

        return $path;
    }

    /**
     * @param \GdImage $source
     */
    private static function resizeToSquare(\GdImage $source, int $targetSize): \GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $cropSize = min($width, $height);
        $cropX = (int) (($width - $cropSize) / 2);
        $cropY = (int) (($height - $cropSize) / 2);

        $target = imagecreatetruecolor($targetSize, $targetSize);
        imagecopyresampled($target, $source, 0, 0, $cropX, $cropY, $targetSize, $targetSize, $cropSize, $cropSize);

        return $target;
    }
}
