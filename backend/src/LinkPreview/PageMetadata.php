<?php

declare(strict_types=1);

namespace App\LinkPreview;

final readonly class PageMetadata
{
    public function __construct(
        public ?string $title,
        public ?string $imageUrl,
        public ?string $priceAmount,
        public ?string $priceCurrency,
    ) {
    }
}
