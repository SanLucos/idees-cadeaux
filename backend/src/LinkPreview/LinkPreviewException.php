<?php

declare(strict_types=1);

namespace App\LinkPreview;

/**
 * Any reason a page (or its image) could not be fetched: refused by the
 * SSRF guard, network error, timeout, too large, wrong content type.
 * Deliberately one type: the API answers every case the same way, so a
 * client can't use the endpoint to map what's reachable (spec §5.5).
 */
final class LinkPreviewException extends \RuntimeException
{
}
