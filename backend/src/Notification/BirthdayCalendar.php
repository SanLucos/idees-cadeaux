<?php

declare(strict_types=1);

namespace App\Notification;

/**
 * Spec §5.11: a 29 February birthday is celebrated on 28 February in
 * non-leap years. Mirrors mobile/src/utils/birthday.ts.
 */
final class BirthdayCalendar
{
    /**
     * @return array{int, \DateTimeImmutable} days until the next occurrence (0 = today), and that date
     */
    public static function next(int $day, int $month, \DateTimeImmutable $today): array
    {
        $start = $today->setTime(0, 0);
        $occurrence = self::occurrence($day, $month, (int) $start->format('Y'), $start->getTimezone());
        if ($occurrence < $start) {
            $occurrence = self::occurrence($day, $month, (int) $start->format('Y') + 1, $start->getTimezone());
        }

        return [(int) $start->diff($occurrence)->days, $occurrence];
    }

    private static function occurrence(int $day, int $month, int $year, \DateTimeZone $zone): \DateTimeImmutable
    {
        $isLeap = 0 === $year % 4 && (0 !== $year % 100 || 0 === $year % 400);
        $effectiveDay = 2 === $month && 29 === $day && !$isLeap ? 28 : $day;

        return new \DateTimeImmutable(\sprintf('%04d-%02d-%02d', $year, $month, $effectiveDay), $zone);
    }
}
