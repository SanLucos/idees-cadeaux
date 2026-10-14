<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Link and Conversion are for spec §5.16 (lot 7 bis) and §5.15's
 * link-based joining. Family: a child shared with its managers' friends
 * (spec §11 décision 59), kept in line by App\Service\FamilyFriendships.
 */
enum FriendshipOrigin: string
{
    case Request = 'request';
    case Link = 'link';
    case Conversion = 'conversion';
    case Family = 'family';
}
