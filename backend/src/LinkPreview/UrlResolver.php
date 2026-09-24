<?php

declare(strict_types=1);

namespace App\LinkPreview;

/**
 * Resolves a reference found in a page (image, redirect location)
 * against the page's URL. Returns null for anything that isn't http(s)
 * once resolved (data:, javascript:, mailto:…).
 */
final class UrlResolver
{
    public static function resolve(string $reference, string $base): ?string
    {
        $reference = trim($reference);
        if ('' === $reference) {
            return null;
        }

        $baseParts = parse_url($base);
        if (false === $baseParts || !isset($baseParts['scheme'], $baseParts['host'])) {
            return null;
        }
        $origin = $baseParts['scheme'].'://'.$baseParts['host'].(isset($baseParts['port']) ? ':'.$baseParts['port'] : '');

        if (1 === preg_match('#^[a-z][a-z0-9+.-]*:#i', $reference)) {
            $resolved = $reference;
        } elseif (str_starts_with($reference, '//')) {
            $resolved = $baseParts['scheme'].':'.$reference;
        } elseif (str_starts_with($reference, '/')) {
            $resolved = $origin.$reference;
        } elseif (str_starts_with($reference, '?') || str_starts_with($reference, '#')) {
            $resolved = $origin.($baseParts['path'] ?? '/').$reference;
        } else {
            $path = $baseParts['path'] ?? '/';
            $directory = substr($path, 0, (int) strrpos($path, '/') + 1) ?: '/';
            $resolved = $origin.$directory.$reference;
        }

        $scheme = strtolower((string) parse_url($resolved, \PHP_URL_SCHEME));

        return \in_array($scheme, ['http', 'https'], true) ? $resolved : null;
    }
}
