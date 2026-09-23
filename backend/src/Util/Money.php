<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Money as decimal strings ("38.50") on the wire and in the database,
 * integer cents for arithmetic — never floats.
 */
final class Money
{
    /**
     * Normalises user input ("38,5", 38.5, "38") to "38.50"; null when
     * the input is not a positive-or-zero amount with ≤ 2 decimals.
     */
    public static function parse(mixed $value): ?string
    {
        $raw = \is_int($value) || \is_float($value) ? (string) $value : (\is_string($value) ? str_replace(',', '.', trim($value)) : '');
        if (1 !== preg_match('/^\d{1,8}(\.\d{1,2})?$/', $raw)) {
            return null;
        }

        [$units, $cents] = array_pad(explode('.', $raw), 2, '');
        $units = ltrim($units, '0');

        return ('' === $units ? '0' : $units).'.'.str_pad($cents, 2, '0');
    }

    public static function toCents(string $amount): int
    {
        [$units, $cents] = array_pad(explode('.', $amount), 2, '0');

        return (int) $units * 100 + (int) str_pad(substr($cents, 0, 2), 2, '0');
    }

    public static function fromCents(int $cents): string
    {
        return \sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
