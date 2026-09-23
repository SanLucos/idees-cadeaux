<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Idea;
use App\Entity\Occasion;
use App\Exception\ApiProblemException;
use App\Repository\OccasionRepository;

/**
 * Validates and applies the editable fields of an idea (spec §5.4)
 * from a decoded JSON body. Only keys present in the body are touched,
 * so the same code serves POST (all fields) and PATCH (merge-patch).
 * Each failure has its own stable code (CLAUDE.md règle 7).
 */
final class IdeaFieldsApplier
{
    public function __construct(private readonly OccasionRepository $occasions)
    {
    }

    public static function parseTitle(mixed $value): string
    {
        $title = \is_string($value) ? trim($value) : '';
        if ('' === $title || mb_strlen($title) > Idea::TITLE_MAX_LENGTH) {
            throw new ApiProblemException('validation.idea_title_invalid', 'Title is required, 120 characters max.', 422);
        }

        return $title;
    }

    /**
     * @param array<string, mixed> $body
     */
    public function apply(Idea $idea, array $body): void
    {
        if (\array_key_exists('title', $body)) {
            $idea->setTitle(self::parseTitle($body['title']));
        }

        if (\array_key_exists('url', $body)) {
            $idea->setUrl(self::parseUrl($body['url']));
        }

        if (\array_key_exists('priceAmount', $body) || \array_key_exists('priceCurrency', $body)) {
            $amount = \array_key_exists('priceAmount', $body) ? self::parseAmount($body['priceAmount']) : $idea->getPriceAmount();
            $currency = \array_key_exists('priceCurrency', $body) ? self::parseCurrency($body['priceCurrency']) : $idea->getPriceCurrency();
            $idea->setPrice($amount, $currency);
        }

        if (\array_key_exists('note', $body)) {
            $idea->setNote(self::parseNote($body['note']));
        }

        if (\array_key_exists('occasion', $body)) {
            $idea->setOccasion($this->parseOccasion($body['occasion']));
        }
    }

    private static function parseUrl(mixed $value): ?string
    {
        if (null === $value || (\is_string($value) && '' === trim($value))) {
            return null;
        }

        $url = \is_string($value) ? trim($value) : '';
        $scheme = strtolower((string) parse_url($url, \PHP_URL_SCHEME));
        if (mb_strlen($url) > Idea::URL_MAX_LENGTH
            || !\in_array($scheme, ['http', 'https'], true)
            || null === parse_url($url, \PHP_URL_HOST)
            || false === filter_var($url, \FILTER_VALIDATE_URL)
        ) {
            throw new ApiProblemException('validation.idea_url_invalid', 'Link must be an http(s) URL.', 422);
        }

        return $url;
    }

    /** Decimal string with two digits ("38" → "38.00"), never a float. */
    private static function parseAmount(mixed $value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        $raw = \is_int($value) || \is_float($value) ? (string) $value : (\is_string($value) ? str_replace(',', '.', trim($value)) : '');
        if (1 !== preg_match('/^\d{1,8}(\.\d{1,2})?$/', $raw)) {
            throw new ApiProblemException('validation.idea_price_invalid', 'Price must be a positive amount with at most two decimals.', 422);
        }

        [$units, $cents] = array_pad(explode('.', $raw), 2, '');
        $units = ltrim($units, '0');

        return ('' === $units ? '0' : $units).'.'.str_pad($cents, 2, '0');
    }

    private static function parseCurrency(mixed $value): string
    {
        $currency = \is_string($value) ? strtoupper(trim($value)) : '';
        if (1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new ApiProblemException('validation.idea_currency_invalid', 'Currency must be an ISO 4217 code.', 422);
        }

        return $currency;
    }

    private static function parseNote(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $note = \is_string($value) ? trim($value) : null;
        if (null === $note || mb_strlen($note) > Idea::NOTE_MAX_LENGTH) {
            throw new ApiProblemException('validation.idea_note_invalid', 'Note is 2000 characters max.', 422);
        }

        return '' === $note ? null : $note;
    }

    private function parseOccasion(mixed $value): ?Occasion
    {
        if (null === $value || '' === $value) {
            return null;
        }

        $occasion = \is_string($value) ? $this->occasions->findOneByCode($value) : null;
        if (null === $occasion) {
            throw new ApiProblemException('validation.idea_occasion_invalid', 'Unknown occasion.', 422);
        }

        return $occasion;
    }
}
