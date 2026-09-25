/** The owner's share link (spec §5.16), as GET /share-link returns it. */
export interface ShareLink {
  id: string;
  token: string;
  url: string;
  status: 'active' | 'suspended';
  joinCount: number;
  createdAt: string;
  suspendedAt: string | null;
}

/** An idea in the guest view: the idea itself, nothing about its state or interactions. */
export interface GuestIdea {
  id: string;
  view: 'guest';
  title: string;
  url: string | null;
  priceAmount: string | null;
  priceCurrency: string | null;
  imageUrl: string | null;
  thumbnailUrl: string | null;
  note: string | null;
  occasion: string | null;
}

export interface GuestView {
  owner: { displayName: string | null; avatarUrl: string | null };
  occasions: string[];
  member: GuestIdea[];
  totalItems: number;
  page: number;
  itemsPerPage: number;
}

/** What opening someone's link means for me: my own, my child's, already friends, or to confirm. */
export type ShareLinkRelation = 'self' | 'manager' | 'friend' | 'none';

export interface ShareLinkInvitation {
  relation: ShareLinkRelation;
  owner: {
    id: string;
    displayName: string | null;
    avatarUrl: string | null;
    isManaged: boolean;
    managedBy: { displayName: string | null } | null;
  };
  friendshipId?: string | null;
}
