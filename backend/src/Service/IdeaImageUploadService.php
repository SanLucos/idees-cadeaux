<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Idea;
use App\Serializer\IdeaNormalizer;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Idea image (spec §5.4, §9): a large version (fits 1200 px) and a
 * square thumbnail (256 px, for list cards), both re-encoded as JPEG,
 * which strips EXIF. The thumbnail's path is derived from the large
 * one's (IdeaNormalizer::thumbnailPath).
 */
final class IdeaImageUploadService
{
    private const int LARGE_MAX_SIDE = 1200;
    private const int THUMBNAIL_SIZE = 256;

    public function __construct(
        #[Autowire(service: 'default.storage')]
        private readonly FilesystemOperator $storage,
        private readonly UploadedImageReader $reader,
    ) {
    }

    public function upload(Idea $idea, UploadedFile $file): string
    {
        $source = $this->reader->read($file);

        $path = \sprintf('ideas/%s-%s.jpg', $idea->getId()->toRfc4122(), bin2hex(random_bytes(4)));
        $options = ['visibility' => 'public', 'mimetype' => 'image/jpeg'];
        $this->storage->write($path, UploadedImageReader::toJpeg(UploadedImageReader::fit($source, self::LARGE_MAX_SIDE)), $options);
        $this->storage->write(IdeaNormalizer::thumbnailPath($path), UploadedImageReader::toJpeg(UploadedImageReader::square($source, self::THUMBNAIL_SIZE)), $options);

        $this->delete($idea->getImagePath());

        return $path;
    }

    public function delete(?string $path): void
    {
        if (null === $path) {
            return;
        }

        foreach ([$path, IdeaNormalizer::thumbnailPath($path)] as $object) {
            try {
                $this->storage->delete($object);
            } catch (\Throwable) {
                // Best-effort cleanup; an orphaned object is not worth failing the request for.
            }
        }
    }
}
