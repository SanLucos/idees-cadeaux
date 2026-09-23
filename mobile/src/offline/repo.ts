import { useLocalDb } from './db';
import type { Comment, Contribution, Idea, IdeaListQuery, IdeaPage, Occasion, PrivateIdea } from '../types/idea';
import type { Friendship } from '../types/friendship';
import type { ManagedProfile, User } from '../types/user';
import type { ProfilePreference, ProfileSize } from '../types/profile';
import type { AppNotification } from '../types/notification';

/**
 * Reads for the screens, from the device's synced documents (spec §8:
 * the local base is the UI's source). Same semantics as the API lists
 * they replace (filters, sort, 20 per page, Ma liste counts). Documents
 * already are the API's views: nothing here decides visibility.
 */
export const repo = {
  /** Ideas on `ownerId`'s list as synced — the owner, friend or manager view the server chose. */
  ideas(ownerId: string, query: IdeaListQuery): IdeaPage {
    const all = useLocalDb().all<Idea>('idea').filter((i) => i.ownerId === ownerId);
    const status = query.status ?? 'active';

    let list = all.filter((i) => i.status === status);
    if (query.visibility) list = list.filter((i) => i.visibility === query.visibility);
    if ('personal' === query.kind) list = list.filter((i) => !i.isSuggestion);
    if ('suggestion' === query.kind) list = list.filter((i) => !!i.isSuggestion);
    if (query.occasion) list = list.filter((i) => i.occasion === query.occasion);
    if (query.q) {
      const needle = query.q.toLowerCase();
      list = list.filter((i) => i.title.toLowerCase().includes(needle) || (i.note ?? '').toLowerCase().includes(needle));
    }
    list.sort(comparator(query.sort ?? 'recent'));

    const page = query.page ?? 1;
    const perPage = query.itemsPerPage ?? 20;
    const personal = all.filter((i) => !i.isSuggestion && 'friend' !== i.view);

    return {
      member: list.slice((page - 1) * perPage, page * perPage),
      totalItems: list.length,
      page,
      itemsPerPage: perPage,
      counts: {
        published: personal.filter((i) => 'active' === i.status && 'published' === i.visibility).length,
        drafts: personal.filter((i) => 'active' === i.status && 'private' === i.visibility).length,
        archived: personal.filter((i) => 'archived' === i.status).length,
      },
    };
  },

  /** Whether this list is on the device at all (a child's friends' lists aren't: spec §11 décision 33). */
  hasList(ownerId: string, myId: string | undefined): boolean {
    const db = useLocalDb();

    return ownerId === myId || !!db.get('user', ownerId);
  },

  idea(id: string): Idea | null {
    return useLocalDb().get<Idea>('idea', id);
  },

  /**
   * « Privées »: every draft I wrote, with its recipient. While managing
   * a child (`childId`), that child's own drafts.
   */
  privateIdeas(myId: string, childId: string | null = null): PrivateIdea[] {
    const db = useLocalDb();
    const drafts = db.all<Idea>('idea').filter((i) => 'private' === i.visibility && 'active' === i.status);
    const mine = null !== childId
      ? drafts.filter((i) => i.ownerId === childId && i.isMine)
      : drafts.filter((i) => ('owner' === i.view && i.ownerId === myId) || ('friend' === i.view && i.isMine));

    return mine
      .sort(comparator('recent'))
      .map((i) => ({ ...i, recipient: { id: i.ownerId, displayName: db.get<User>('user', i.ownerId)?.displayName ?? null } }));
  },

  comments(ideaId: string): Comment[] {
    return useLocalDb()
      .all<Comment>('comment')
      .filter((c) => c.ideaId === ideaId)
      .sort((a, b) => a.createdAt.localeCompare(b.createdAt) || a.id.localeCompare(b.id));
  },

  /** A contribution lives inside its idea's document. */
  contribution(id: string): Contribution | null {
    const idea = useLocalDb().all<Idea>('idea').find((i) => i.contribution?.id === id);

    return idea?.contribution ? { ...idea.contribution, idea } : null;
  },

  user(id: string): User | null {
    return useLocalDb().get<User>('user', id);
  },

  /** Friendships of me, or of the child profile being managed (`profileId`). */
  friendships(profileId: string): { friends: Friendship[]; incoming: Friendship[]; outgoing: Friendship[] } {
    const db = useLocalDb();
    const mine = db.all<Friendship & { profileId: string }>('friendship').filter((f) => f.profileId === profileId);
    const ideaCounts = new Map<string, number>();
    for (const idea of db.all<Idea>('idea')) {
      if ('published' === idea.visibility && 'active' === idea.status) ideaCounts.set(idea.ownerId, (ideaCounts.get(idea.ownerId) ?? 0) + 1);
    }
    const byDate = (a: Friendship, b: Friendship) => (b.respondedAt ?? b.createdAt).localeCompare(a.respondedAt ?? a.createdAt);

    return {
      friends: mine.filter((f) => 'accepted' === f.status).map((f) => ({ ...f, ideaCount: ideaCounts.get(f.user.id) ?? 0 })).sort(byDate),
      incoming: mine.filter((f) => 'pending' === f.status && 'incoming' === f.direction).sort(byDate),
      outgoing: mine.filter((f) => 'outgoing' === f.direction && 'accepted' !== f.status).sort(byDate),
    };
  },

  sizes(userId: string): ProfileSize[] {
    return useLocalDb()
      .all<ProfileSize & { userId: string; id: string }>('profile_size')
      .filter((s) => s.userId === userId)
      .sort((a, b) => a.sortOrder - b.sortOrder || a.id.localeCompare(b.id));
  },

  preferences(userId: string): ProfilePreference[] {
    return useLocalDb()
      .all<ProfilePreference & { userId: string; id: string }>('profile_preference')
      .filter((p) => p.userId === userId)
      .sort((a, b) => a.id.localeCompare(b.id));
  },

  notifications(): AppNotification[] {
    return useLocalDb()
      .all<AppNotification>('notification')
      .sort((a, b) => b.createdAt.localeCompare(a.createdAt) || b.id.localeCompare(a.id));
  },

  unreadCount(): number {
    return useLocalDb().all<AppNotification>('notification').filter((n) => !n.readAt).length;
  },

  children(): ManagedProfile[] {
    return useLocalDb()
      .all<ManagedProfile>('managed_profile')
      .sort((a, b) => (a.displayName ?? '').localeCompare(b.displayName ?? ''));
  },

  occasions(): Occasion[] {
    return useLocalDb().all<Occasion & { id: string }>('occasion').sort((a, b) => a.sortOrder - b.sortOrder);
  },
};

function comparator(sort: NonNullable<IdeaListQuery['sort']>): (a: Idea, b: Idea) => number {
  const recent = (a: Idea, b: Idea) => b.createdAt.localeCompare(a.createdAt) || b.id.localeCompare(a.id);
  if ('recent' === sort) return recent;

  const direction = 'price_asc' === sort ? 1 : -1;

  return (a, b) => {
    // No price last, whichever the direction (as the API).
    if (null === a.priceAmount || null === b.priceAmount) {
      return (null === a.priceAmount ? 1 : 0) - (null === b.priceAmount ? 1 : 0) || recent(a, b);
    }

    return direction * (Number(a.priceAmount) - Number(b.priceAmount)) || recent(a, b);
  };
}
