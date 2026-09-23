<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Exception\ApiProblemException;

/**
 * Pseudo and birthday rules (spec §5.2), shared by /users/me and
 * /managed-profiles so an adult and a child profile validate alike.
 */
final class ProfileFields
{
    public static function displayName(mixed $value): string
    {
        $displayName = trim((string) $value);
        if (mb_strlen($displayName) < 2 || mb_strlen($displayName) > 30) {
            throw new ApiProblemException('validation.display_name_invalid', 'Pseudo must be 2 to 30 characters.', 422);
        }

        return $displayName;
    }

    /**
     * Applies birthDay / birthMonth / birthYear present in `$body`:
     * day and month together, year optional, all null clears it.
     *
     * @param array<string, mixed> $body
     */
    public static function applyBirthDate(User $user, array $body): void
    {
        if (!\array_key_exists('birthDay', $body) && !\array_key_exists('birthMonth', $body) && !\array_key_exists('birthYear', $body)) {
            return;
        }

        $day = \array_key_exists('birthDay', $body) ? $body['birthDay'] : $user->getBirthDay();
        $month = \array_key_exists('birthMonth', $body) ? $body['birthMonth'] : $user->getBirthMonth();
        $year = \array_key_exists('birthYear', $body) ? $body['birthYear'] : $user->getBirthYear();

        if (null === $day && null === $month && null === $year) {
            $user->setBirthDate(null, null, null);

            return;
        }

        if (!\is_int($day) || !\is_int($month) || $month < 1 || $month > 12 || $day < 1 || $day > 31) {
            throw new ApiProblemException('validation.birth_date_invalid', 'birthDay and birthMonth are both required together and must be valid.', 422);
        }
        if (null !== $year && (!\is_int($year) || $year < 1900 || $year > (int) date('Y'))) {
            throw new ApiProblemException('validation.birth_date_invalid', 'birthYear is out of range.', 422);
        }

        $user->setBirthDate($day, $month, $year);
    }
}
