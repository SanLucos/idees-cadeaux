export interface FriendSummary {
  id: string;
  displayName: string | null;
  avatarUrl: string | null;
}

export type FriendshipStatus = 'pending' | 'accepted' | 'declined' | 'expired';

export interface Friendship {
  id: string;
  status: FriendshipStatus;
  origin: string;
  direction: 'incoming' | 'outgoing';
  createdAt: string;
  respondedAt: string | null;
  user: FriendSummary;
}

export interface ContactMatch {
  id: string;
  displayName: string | null;
}
