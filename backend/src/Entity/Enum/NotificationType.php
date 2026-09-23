<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Spec §5.11's table, one case per row (cotisation: created,
 * pledge added, goal reached). Recipients: App\Notification\
 * NotificationRecipients; default channels below.
 */
enum NotificationType: string
{
    case FriendRequestReceived = 'friend_request_received';
    case FriendRequestAccepted = 'friend_request_accepted';
    case FriendJoinedViaLink = 'friend_joined_via_link';
    case ShareLinkSuspended = 'share_link_suspended';
    case IdeaPublished = 'idea_published';
    case SuggestionPublished = 'suggestion_published';
    case IdeaReserved = 'idea_reserved';
    case CommentAdded = 'comment_added';
    case ContributionOpened = 'contribution_opened';
    case PledgeAdded = 'pledge_added';
    case ContributionGoalReached = 'contribution_goal_reached';
    case SuggestionGifted = 'suggestion_gifted';
    case IdeaUnpublished = 'idea_unpublished';
    case BirthdayReminder = 'birthday_reminder';

    /**
     * @return NotificationChannel[]
     */
    public function defaultChannels(): array
    {
        return match ($this) {
            self::FriendRequestReceived => [NotificationChannel::InApp, NotificationChannel::Push, NotificationChannel::Email],
            self::IdeaPublished, self::SuggestionPublished, self::SuggestionGifted, self::IdeaUnpublished => [NotificationChannel::InApp],
            default => [NotificationChannel::InApp, NotificationChannel::Push],
        };
    }
}
