<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\MediaUrls;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /media/{path}?e=…&s=…: an uploaded image from the private bucket,
 * for whoever holds a URL the API signed (App\Service\MediaUrls). A
 * missing, expired or forged signature is a plain 404.
 */
final class MediaController
{
    public function __construct(
        private readonly MediaUrls $media,
        #[Autowire(service: 'default.storage')]
        private readonly FilesystemOperator $storage,
    ) {
    }

    #[Route('/media/{path}', name: 'media', methods: ['GET'], requirements: ['path' => '(avatars|ideas)/[A-Za-z0-9-]+\.jpg'])]
    public function __invoke(string $path, Request $request): Response
    {
        $expires = $request->query->getInt('e');
        $notFound = new Response('', 404, ['Cache-Control' => 'no-store']);
        if (!$this->media->isValid($path, $expires, (string) $request->query->get('s', ''))) {
            return $notFound;
        }

        try {
            if (!$this->storage->fileExists($path)) {
                return $notFound;
            }
            $stream = $this->storage->readStream($path);
        } catch (FilesystemException) {
            return $notFound;
        }

        return new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age='.max(0, $expires - time()),
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
