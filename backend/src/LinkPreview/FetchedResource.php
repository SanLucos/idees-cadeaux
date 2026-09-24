<?php

declare(strict_types=1);

namespace App\LinkPreview;

final readonly class FetchedResource
{
    public function __construct(
        public string $url,
        public string $contentType,
        public string $body,
    ) {
    }
}
