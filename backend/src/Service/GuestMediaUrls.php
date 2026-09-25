<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ShareLink;

/**
 * Spec §5.16: the guest view's image URLs are signed and short-lived.
 * They point at GuestPageController::media, which also re-checks the
 * link itself: disabling or regenerating it cuts the images too.
 */
final class GuestMediaUrls
{
    public const int TTL = 3600;
    public const array KINDS = ['avatar', 'image', 'thumb'];

    public function __construct(private readonly string $secret)
    {
    }

    /** A path (no host), for the web page, where images stay same-origin. */
    public function path(ShareLink $link, string $kind, string $id, ?int $now = null): string
    {
        $expires = ($now ?? time()) + self::TTL;

        return \sprintf('/u/%s/media/%s/%s?e=%d&s=%s', $link->getToken(), $kind, $id, $expires, $this->sign($link->getToken(), $kind, $id, $expires));
    }

    public function isValid(string $token, string $kind, string $id, int $expires, string $signature): bool
    {
        return $expires >= time() && hash_equals($this->sign($token, $kind, $id, $expires), $signature);
    }

    private function sign(string $token, string $kind, string $id, int $expires): string
    {
        return hash_hmac('sha256', implode('|', ['guest-media', $token, $kind, $id, $expires]), $this->secret);
    }
}
