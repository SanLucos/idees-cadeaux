export type IdeaVisibility = 'private' | 'published';
export type IdeaStatus = 'active' | 'archived';
export type IdeaArchiveKind = 'received' | 'gifted';

export interface IdeaAuthor {
  id: string;
  displayName: string | null;
  avatarUrl: string | null;
}

/**
 * Both server views (spec §4). The owner view never carries `author`,
 * `isSuggestion` or `isMine`, and from lot 4 never any hidden
 * interaction: the client only displays what the API sends.
 */
export interface Idea {
  id: string;
  ownerId: string;
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
  view: 'owner' | 'friend';
  canEdit: boolean;
  canUnarchive: boolean;
  isSuggestion?: boolean;
  isMine?: boolean;
  author?: IdeaAuthor;
}

export interface PrivateIdea extends Idea {
  recipient: { id: string; displayName: string | null };
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
  ownerId?: string;
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
