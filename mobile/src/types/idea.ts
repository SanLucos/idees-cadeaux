export type IdeaVisibility = 'private' | 'published';
export type IdeaStatus = 'active' | 'archived';
export type IdeaArchiveKind = 'received' | 'gifted';

export interface IdeaAuthor {
  id: string;
  displayName: string | null;
  avatarUrl: string | null;
}

export interface PersonRef {
  id: string;
  displayName: string | null;
  avatarUrl: string | null;
}

export interface Reservation {
  id: string;
  user: PersonRef;
  isMine: boolean;
  createdAt: string;
}

/** `amount` is only present for its author and the initiator (CLAUDE.md règle 3). */
export interface ContributionParticipant {
  user: PersonRef;
  isMe: boolean;
  isInitiator: boolean;
  amount?: string;
}

export interface Contribution {
  id: string;
  ideaId: string;
  status: 'open' | 'closed';
  /** Null once the initiator's account was deleted (spec §5.13): the contribution is then closed. */
  initiator: PersonRef | null;
  isInitiator: boolean;
  targetAmount: string | null;
  currency: string;
  totalAmount: string;
  remainingAmount: string | null;
  goalReached: boolean;
  participantCount: number;
  participants: ContributionParticipant[];
  myPledge: string | null;
  closedAt: string | null;
  /** Present on /contributions responses. */
  idea?: Idea;
}

export interface Comment {
  id: string;
  ideaId: string;
  author: PersonRef;
  isMine: boolean;
  body: string;
  createdAt: string;
  editedAt: string | null;
}

/**
 * Both server views (spec §4). The owner view never carries `author`,
 * `isSuggestion` or `isMine`, and from lot 4 never any hidden
 * interaction: the client only displays what the API sends.
 */
export interface Idea {
  id: string;
  /**
   * Who the idea is for (spec §11 décision 69): one person, or several
   * for a shared idea. Each of them is its owner. Read it through
   * utils/recipients (documents synced before lot 15 had `ownerId`).
   */
  recipients: PersonRef[];
  title: string;
  url: string | null;
  priceAmount: string | null;
  priceCurrency: string;
  imageUrl: string | null;
  thumbnailUrl: string | null;
  note: string | null;
  occasion: string | null;
  visibility: IdeaVisibility;
  publishedAt: string | null;
  status: IdeaStatus;
  archivedAt: string | null;
  archiveKind: IdeaArchiveKind | null;
  createdAt: string;
  updatedAt: string;
  /** 'manager': a child profile's list read by its manager (spec §5.15). */
  view: 'owner' | 'friend' | 'manager';
  canEdit: boolean;
  canUnarchive: boolean;
  isSuggestion?: boolean;
  /** I wrote it: in the owner view, whether I may publish it or change its recipients. */
  isMine?: boolean;
  author?: IdeaAuthor;
  canMarkGifted?: boolean;
  /**
   * Hidden interactions (lot 4): only in the friend view of a published
   * idea. Absent keys mean "nothing to show" — never "hide it".
   */
  reservation?: Reservation | null;
  contribution?: Contribution | null;
  reactions?: { count: number; likedByMe: boolean };
  commentCount?: number;
  canReact?: boolean;
}

/** A draft of mine, on the « Privées » screen, grouped by its recipients. */
export type PrivateIdea = Idea;

/**
 * « Mes cadeaux pour mes amis » (spec §11 décision 62): an idea of a
 * friend's (or of my child's) that I reserved or contributed to.
 * `given`: it has since been archived (received, or marked « offert »).
 */
export type MyGiftKind = 'reserved' | 'contribution' | 'given';

export interface MyGiftPerson {
  id: string;
  displayName: string | null;
  avatarUrl: string | null;
  birthDay: number | null;
  birthMonth: number | null;
}

export interface MyGift {
  idea: Idea;
  kind: MyGiftKind;
  /** How I took part: kept for a given gift, to say how it was offered. */
  via: 'reservation' | 'contribution';
  /** Who it's for: several people for a shared idea (décision 69). */
  recipients: MyGiftPerson[];
}

export interface IdeaPage {
  member: Idea[];
  totalItems: number;
  page: number;
  itemsPerPage: number;
  counts?: { published: number; drafts: number; archived: number };
}

export type IdeaSort = 'recent' | 'price_asc' | 'price_desc';

export interface IdeaListQuery {
  status?: IdeaStatus;
  visibility?: IdeaVisibility;
  kind?: 'personal' | 'suggestion';
  occasion?: string | null;
  q?: string;
  sort?: IdeaSort;
  page?: number;
  itemsPerPage?: number;
}

export interface IdeaInput {
  id?: string;
  /** Who it's for (décision 69); me when absent. Only changes while the idea is private. */
  recipientIds?: string[];
  title: string;
  url: string | null;
  priceAmount: string | null;
  priceCurrency: string;
  note: string | null;
  occasion: string | null;
  visibility?: IdeaVisibility;
}

export interface Occasion {
  code: string;
  translationKey: string;
  sortOrder: number;
}
