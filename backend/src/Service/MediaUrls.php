<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Uploaded images live in a private bucket (spec §11 décision 41); the
 * app reads them through signed URLs served by MediaController.
 *
 * URLs are stable for a week — same URL, so the HTTP cache and the
 * device's copy stay valid — and each stays usable for 7 to 14 days.
 * The sync version of every document carrying one moves with the week
 * (App\Sync\SyncService), so devices get fresh URLs before theirs expire.
 */
final class MediaUrls
{
    public const int PERIOD = 604800;

    public function __construct(
        private readonly string $mediaBaseUrl,
        private readonly string $secret,
    ) {
    }

    public static function windowStart(?int $now = null): \DateTimeImmutable
    {
        $now ??= time();

        return (new \DateTimeImmutable())->setTimestamp(intdiv($now, self::PERIOD) * self::PERIOD);
    }

    public function url(?string $path, ?int $now = null): ?string
    {
        if (null === $path) {
            return null;
        }
        $expires = self::windowStart($now)->getTimestamp() + 2 * self::PERIOD;

        return \sprintf('%s/media/%s?e=%d&s=%s', rtrim($this->mediaBaseUrl, '/'), $path, $expires, $this->sign($path, $expires));
    }

    public function isValid(string $path, int $expires, string $signature): bool
    {
        $now = time();

        return $expires >= $now && $expires <= $now + 2 * self::PERIOD && hash_equals($this->sign($path, $expires), $signature);
    }

    private function sign(string $path, int $expires): string
    {
        return hash_hmac('sha256', 'media|'.$path.'|'.$expires, $this->secret);
    }
}
