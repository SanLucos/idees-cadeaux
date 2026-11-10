/** Pseudo and avatar only, as in a friend request (spec §4). */
export interface SuggestedPerson {
  id: string;
  displayName: string | null;
  avatarUrl: string | null;
}

/** A suggestion I received (spec §11 décision 65): one per person suggested, with everyone who suggested them. */
export interface ReceivedFriendSuggestion {
  id: string;
  createdAt: string;
  suggested: SuggestedPerson;
  suggestedBy: { id: string; displayName: string | null }[];
}

/** One I made: always « en attente » until it succeeds (then it's gone, and I'm notified). */
export interface SentFriendSuggestion {
  id: string;
  status: 'pending';
  createdAt: string;
  recipient: SuggestedPerson;
  suggested: SuggestedPerson;
}
