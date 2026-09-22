<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Link and Conversion are for spec §5.16 (lot 7 bis) and §5.15's
 * link-based joining — only Request is reachable until then.
 */
enum FriendshipOrigin: string
{
    case Request = 'request';
    case Link = 'link';
    case Conversion = 'conversion';
}
