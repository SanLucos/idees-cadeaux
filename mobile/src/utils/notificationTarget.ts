import type { AppNotification } from '../types/notification';

export interface NotificationTarget {
  path: string;
  /** Switch to this child profile first (« Pour Jules », spec §5.15). */
  actAs: string | null;
}

/**
 * Spec §5.11 "les notifications ouvrent l'écran concerné (deep link)".
 * An idea made private is no longer reachable: its owner's list instead.
 */
export function notificationTarget(n: AppNotification): NotificationTarget {
  const p = n.payload;
  const actAs = p.subject?.id ?? null;

  const path = (() => {
    switch (n.type) {
      case 'friend_request_received':
        return '/tabs/friends';
      case 'friend_request_accepted':
      case 'friend_joined_via_link':
        return p.actor ? `/tabs/friends/${p.actor.id}` : '/tabs/friends';
      case 'birthday_reminder':
        return p.friend ? `/tabs/friends/${p.friend.id}` : '/tabs/friends';
      case 'share_link_suspended':
        return '/tabs/profile';
      case 'contribution_opened':
      case 'pledge_added':
      case 'contribution_goal_reached':
        return p.contribution ? `/contributions/${p.contribution.id}` : `/ideas/${p.idea?.id}`;
      case 'idea_unpublished':
        return p.owner ? `/tabs/friends/${p.owner.id}` : '/tabs/activity';
      default:
        return p.idea ? `/ideas/${p.idea.id}` : '/tabs/activity';
    }
  })();

  return { path, actAs };
}
