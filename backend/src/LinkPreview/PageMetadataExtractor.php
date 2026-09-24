<?php

declare(strict_types=1);

namespace App\LinkPreview;

use App\Util\Money;

/**
 * Title, image and price from a product page (spec §5.5): JSON-LD
 * `Product` first (the most structured), then OpenGraph (and its
 * `product:price:*` extension), then Twitter Cards, then `<title>`.
 * Pure: no network, the caller fetches.
 */
final class PageMetadataExtractor
{
    public const int TITLE_MAX_LENGTH = 120;

    public function extract(string $html, string $contentType, string $pageUrl): PageMetadata
    {
        $document = self::load($html, $contentType);
        $xpath = new \DOMXPath($document);

        $meta = [];
        foreach ($xpath->query('//meta[@content]') ?: [] as $node) {
            \assert($node instanceof \DOMElement);
            $key = strtolower(trim($node->getAttribute('property') ?: $node->getAttribute('name') ?: $node->getAttribute('itemprop')));
            if ('' !== $key && !isset($meta[$key])) {
                $meta[$key] = trim($node->getAttribute('content'));
            }
        }

        $product = $this->findJsonLdProduct($xpath);
        $offer = self::firstOffer($product['offers'] ?? null);

        $title = self::firstNonEmpty(
            self::text($product['name'] ?? null),
            $meta['og:title'] ?? null,
            $meta['twitter:title'] ?? null,
            self::text($xpath->query('//title')->item(0)?->textContent),
        );

        $baseHref = $xpath->query('//base[@href]')->item(0);
        $base = $baseHref instanceof \DOMElement ? (UrlResolver::resolve($baseHref->getAttribute('href'), $pageUrl) ?? $pageUrl) : $pageUrl;
        $image = self::firstNonEmpty(
            self::imageUrl($product['image'] ?? null),
            $meta['og:image:secure_url'] ?? null,
            $meta['og:image'] ?? null,
            $meta['og:image:url'] ?? null,
            $meta['twitter:image'] ?? null,
            $meta['twitter:image:src'] ?? null,
        );

        $amount = self::parsePrice($offer['price'] ?? $offer['lowPrice'] ?? $meta['product:price:amount'] ?? $meta['og:price:amount'] ?? $meta['price'] ?? null);
        $currency = self::parseCurrency($offer['priceCurrency'] ?? $meta['product:price:currency'] ?? $meta['og:price:currency'] ?? $meta['pricecurrency'] ?? null);

        return new PageMetadata(
            null === $title ? null : self::truncate($title),
            null === $image ? null : UrlResolver::resolve($image, $base),
            $amount,
            null === $amount ? null : $currency,
        );
    }

    private static function load(string $html, string $contentType): \DOMDocument
    {
        // DOMDocument assumes Latin-1 unless told otherwise: convert to
        // UTF-8 using the header's charset, then say so explicitly.
        $charset = 1 === preg_match('/charset=["\']?([\w-]+)/i', $contentType, $m) ? $m[1] : null;
        if (null === $charset && 1 === preg_match('/<meta[^>]+charset=["\']?([\w-]+)/i', substr($html, 0, 4096), $m)) {
            $charset = $m[1];
        }
        if (null !== $charset && 'utf-8' !== strtolower($charset) && \in_array(strtoupper($charset), array_map('strtoupper', mb_list_encodings()), true)) {
            $html = (string) mb_convert_encoding($html, 'UTF-8', $charset);
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    /** @return array<string, mixed>|null */
    private function findJsonLdProduct(\DOMXPath $xpath): ?array
    {
        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $script) {
            $data = json_decode(trim($script->textContent), true);
            if (\is_array($data) && null !== $product = self::searchProduct($data, 0)) {
                return $product;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $node
     *
     * @return array<string, mixed>|null
     */
    private static function searchProduct(array $node, int $depth): ?array
    {
        if ($depth > 5) {
            return null;
        }

        $types = (array) ($node['@type'] ?? []);
        if (\in_array('Product', $types, true) || \in_array('ProductGroup', $types, true)) {
            return $node;
        }

        foreach ($node as $child) {
            if (\is_array($child) && null !== $found = self::searchProduct($child, $depth + 1)) {
                return $found;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private static function firstOffer(mixed $offers): ?array
    {
        if (!\is_array($offers)) {
            return null;
        }
        if (array_is_list($offers)) {
            foreach ($offers as $offer) {
                if (\is_array($offer)) {
                    return $offer;
                }
            }

            return null;
        }

        return $offers;
    }

    private static function imageUrl(mixed $image): ?string
    {
        if (\is_string($image)) {
            return $image;
        }
        if (\is_array($image)) {
            if (\is_string($image['url'] ?? null)) {
                return $image['url'];
            }
            if (\is_string($image['contentUrl'] ?? null)) {
                return $image['contentUrl'];
            }
            if (array_is_list($image) && [] !== $image) {
                return self::imageUrl($image[0]);
            }
        }

        return null;
    }

    /**
     * Page prices come in every shape: "38,00", "1 299,99", "1,299.99",
     * "€38", 38.5. Returns the API's decimal string, or null.
     */
    public static function parsePrice(mixed $value): ?string
    {
        if (\is_int($value) || \is_float($value)) {
            return $value >= 0 ? Money::parse(number_format((float) $value, 2, '.', '')) : null;
        }
        if (!\is_string($value)) {
            return null;
        }

        $raw = preg_replace('/[^\d.,]/u', '', $value) ?? '';
        $lastComma = strrpos($raw, ',');
        $lastDot = strrpos($raw, '.');
        if (false !== $lastComma && false !== $lastDot) {
            // Both: the last one is the decimal separator.
            $decimal = $lastComma > $lastDot ? ',' : '.';
            $raw = str_replace(',' === $decimal ? '.' : ',', '', $raw);
            $raw = str_replace(',', '.', $raw);
        } elseif (false !== $lastComma) {
            // Only commas: decimal if followed by 1–2 digits, else thousands.
            $raw = 1 === preg_match('/,\d{1,2}$/', $raw) && 1 === substr_count($raw, ',') ? str_replace(',', '.', $raw) : str_replace(',', '', $raw);
        } elseif (false !== $lastDot && (substr_count($raw, '.') > 1 || 1 === preg_match('/\.\d{3}$/', $raw))) {
            // "1.299" or "1.299.000": thousands separators.
            $raw = str_replace('.', '', $raw);
        }

        return Money::parse($raw);
    }

    private static function parseCurrency(mixed $value): string
    {
        $currency = \is_string($value) ? strtoupper(trim($value)) : '';

        return 1 === preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'EUR';
    }

    private static function text(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }

    private static function firstNonEmpty(?string ...$values): ?string
    {
        foreach ($values as $value) {
            $value = null === $value ? '' : trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($value, \ENT_QUOTES | \ENT_HTML5, 'UTF-8')));
            if ('' !== $value) {
                return $value;
            }
        }

        return null;
    }

    private static function truncate(string $title): string
    {
        return mb_strlen($title) <= self::TITLE_MAX_LENGTH ? $title : rtrim(mb_substr($title, 0, self::TITLE_MAX_LENGTH - 1)).'…';
    }
}
