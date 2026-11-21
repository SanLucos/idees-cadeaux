<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Link and Conversion are for spec §5.16 (lot 7 bis) and §5.15's
 * link-based joining. Family: a child shared with its managers' friends
 * (spec §11 décision 59), kept in line by App\Service\FamilyFriendships.
 * Introduction: both accepted a team introduction; Admin: created
 * silently by the administrator (spec §5.17, §11 décision 71).
 */
enum FriendshipOrigin: string
{
    case Request = 'request';
    case Link = 'link';
    case Conversion = 'conversion';
    case Family = 'family';
    case Introduction = 'introduction';
    case Admin = 'admin';

    /**
     * What its two people see: a friendship the administrator created
     * silently looks like any other (spec §11 décision 71). Only the
     * administration shows the stored origin.
     */
    public function exposed(): self
    {
        return self::Admin === $this ? self::Request : $this;
    }
}
