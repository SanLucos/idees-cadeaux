<?php

declare(strict_types=1);

namespace App\LinkPreview;

/**
 * Any reason a page (or its image) could not be fetched: refused by the
 * SSRF guard, network error, timeout, too large, wrong content type.
 * Deliberately one type: the API answers every case the same way, so a
 * client can't use the endpoint to map what's reachable (spec §5.5).
 *
 * `lastUrl` is the last address the fetcher got to (after redirects):
 * a shortened link (amzn.eu/d/…) can still say what it pointed to even
 * when the page behind it can't be read. Never sent to the client.
 */
final class LinkPreviewException extends \RuntimeException
{
    public function __construct(string $message = '', ?\Throwable $previous = null, public readonly ?string $lastUrl = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
