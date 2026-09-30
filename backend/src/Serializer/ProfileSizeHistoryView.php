<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Entity\ProfileSize;
use App\Entity\ProfileSizeHistory;
use App\Entity\User;

/**
 * The history of a size (spec §5.2, §11 décision 51), for the only
 * people who may read it: the profile's owner and, for a child profile,
 * its manager. Friends read the current value and nothing else — for
 * them the field is absent, in the API as in /sync.
 */
final class ProfileSizeHistoryView
{
    /** Whether any of `$viewers` (the acting profile, the adult behind it) reads this history. */
    public static function isReadableBy(ProfileSize $size, ?User ...$viewers): bool
    {
        $owner = $size->getUser();
        foreach ($viewers as $viewer) {
            if (null !== $viewer && ($owner === $viewer || $owner->isManagedBy($viewer))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{id: string, value: string, since: string}> oldest first; the last one is the current value
     */
    public static function entries(ProfileSize $size): array
    {
        return array_map(
            static fn (ProfileSizeHistory $entry) => ['id' => $entry->getId()->toRfc4122(), 'value' => $entry->getValue(), 'since' => $entry->getCreatedAt()->format(\DATE_ATOM)],
            $size->getHistory(),
        );
    }
}
