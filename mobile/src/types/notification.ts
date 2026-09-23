export type NotificationType =
  | 'friend_request_received'
  | 'friend_request_accepted'
  | 'friend_joined_via_link'
  | 'share_link_suspended'
  | 'idea_published'
  | 'suggestion_published'
  | 'idea_reserved'
  | 'comment_added'
  | 'contribution_opened'
  | 'pledge_added'
  | 'contribution_goal_reached'
  | 'suggestion_gifted'
  | 'idea_unpublished'
  | 'birthday_reminder';

export const NOTIFICATION_TYPES: NotificationType[] = [
  'friend_request_received',
  'friend_request_accepted',
  'friend_joined_via_link',
  'idea_published',
  'suggestion_published',
  'idea_reserved',
  'comment_added',
  'contribution_opened',
  'pledge_added',
  'contribution_goal_reached',
  'suggestion_gifted',
  'idea_unpublished',
  'birthday_reminder',
  'share_link_suspended',
];

export type NotificationChannel = 'in_app' | 'email' | 'push';

interface Person {
  id: string;
  displayName: string | null;
  managedBy?: string | null;
}

/** Display data only: the server never puts an amount here (CLAUDE.md règle 3). */
export interface AppNotification {
  id: string;
  type: NotificationType;
  payload: {
    actor?: Person;
    friend?: Person;
    owner?: Person;
    subject?: Person;
    idea?: { id: string; title: string };
    contribution?: { id: string };
    friendship?: { id: string };
    days?: number;
    date?: string;
  };
  readAt: string | null;
  createdAt: string;
}

export interface NotificationPage {
  member: AppNotification[];
  totalItems: number;
  unreadCount: number;
  page: number;
}

export interface NotificationSettings {
  consents: Record<'email' | 'push', { granted: boolean; consentedAt: string | null }>;
  preferences: Record<NotificationType, Record<NotificationChannel, boolean>>;
  birthdayReminderDays: number[];
}
