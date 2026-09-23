export interface FriendSummary {
  id: string;
  displayName: string | null;
  avatarUrl: string | null;
  /** Only present once friends (spec §4: a pending request shows pseudo + avatar only). */
  birthDay?: number | null;
  birthMonth?: number | null;
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
  /** Accepted friendships only: ideas of theirs I can see (lot 3). */
  ideaCount?: number;
}

export interface ContactMatch {
  id: string;
  displayName: string | null;
}
