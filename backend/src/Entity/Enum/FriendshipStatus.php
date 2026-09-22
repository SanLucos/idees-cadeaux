<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Declined and Expired are terminal outcomes of a request; Pending
 * turns into Accepted or Declined via the addressee's action, or into
 * Expired via the daily scheduled task (spec §5.3, 30 days).
 * Cancellation (by the requester) and removal (of an accepted
 * friendship) don't get their own status — they're a soft-delete
 * (App\Entity\Trait\SoftDeletableTrait) of the row instead, since
 * "status" here means the outcome of the request, not whether the row
 * is still active.
 */
enum FriendshipStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Expired = 'expired';
}
