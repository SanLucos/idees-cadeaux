<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Where a friend suggestion stands (spec §11 décision 65). Only its
 * recipient (A) ever sees anything but « en attente »: to its author,
 * `pending`, `sent` and `dismissed` all look the same, and `accepted`
 * shows only as the « A et B sont maintenant amis » notification.
 * A cancellation by the author is a soft delete.
 */
enum FriendSuggestionStatus: string
{
    /** A hasn't answered yet. */
    case Pending = 'pending';
    /** A sent B a friend request from it (FriendSuggestion::$friendship). */
    case Sent = 'sent';
    /** A chose « Ignorer ». */
    case Dismissed = 'dismissed';
    /** B accepted that request: A and B are friends. */
    case Accepted = 'accepted';
}
