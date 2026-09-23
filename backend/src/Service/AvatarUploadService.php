<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Avatar upload (spec §5.2, §9): validated by UploadedImageReader,
 * resized to a fixed square and re-encoded (which strips EXIF).
 */
final class AvatarUploadService
{
    private const int TARGET_SIZE = 512;

    public function __construct(
        #[Autowire(service: 'default.storage')]
        private readonly FilesystemOperator $storage,
        private readonly UploadedImageReader $reader,
    ) {
    }

    public function upload(User $user, UploadedFile $file): string
    {
        $source = $this->reader->read($file);
        $square = UploadedImageReader::square($source, self::TARGET_SIZE);
        $binary = UploadedImageReader::toJpeg($square);

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
}
