import { useLocalDb } from './db';
import { useSync, type NewOp } from './sync';
import { useAuthStore } from '../stores/auth';
import { useActiveProfileStore } from '../stores/activeProfile';
import { uuidv7 } from '../utils/uuidv7';
import type { Comment, Contribution, ContributionParticipant, Idea, IdeaArchiveKind, IdeaInput, PersonRef } from '../types/idea';
import type { ProfilePreference, ProfilePreferenceCategory, ProfileSize } from '../types/profile';
import type { AppNotification } from '../types/notification';

/**
 * Offline writes (spec §8, §11 décision 35): each one updates the local
 * documents at once — the screen shows the result immediately, online
 * or not — then queues the request (offline/sync.ts). When it goes
 * through, the next pull replaces the local guess with the server's
 * truth; when it's refused, the refusal is shown and the pull restores
 * the server's state.
 *
 * Interactions are always the adult's own; list and profile writes
 * follow the active profile (X-Acting-As), as the API requires.
 */

const cents = (amount: string | null | undefined) => Math.round(Number(amount ?? 0) * 100);
const money = (value: number) => (value / 100).toFixed(2);
const now = () => new Date().toISOString();

function context() {
  const auth = useAuthStore();
  const active = useActiveProfileStore();
  const user = auth.user!;
  const me: PersonRef = { id: user.id, displayName: user.displayName, avatarUrl: user.avatarUrl };
  // activeId is the source of truth for X-Acting-As: the actor must match it
  // even before the child's full profile is loaded.
  const child = active.active;
  const actor: PersonRef = active.activeId
    ? { id: active.activeId, displayName: child?.displayName ?? null, avatarUrl: child?.avatarUrl ?? null }
    : me;

  return { me, actor, actingAs: active.activeId };
}

async function queue(op: NewOp): Promise<void> {
  await useSync().enqueue(op);
}

async function patchIdea(id: string, patch: Partial<Idea>): Promise<Idea> {
  const next = await useLocalDb().patch<Idea>('idea', id, { ...patch, updatedAt: now() });
  if (!next) throw new Error('idea.not_local');

  return next;
}

// ---------------------------------------------------------------- ideas

export const ideaMutations = {
  async create(input: IdeaInput, image?: { dataUrl: string; filename: string }): Promise<Idea> {
    const { me, actor, actingAs } = context();
    const id = uuidv7();
    const ownerId = input.ownerId ?? actor.id;
    const isSuggestion = ownerId !== actor.id;
    const visibility = input.visibility ?? 'published';

    const idea: Idea = {
      id,
      ownerId,
      title: input.title,
      url: input.url,
      priceAmount: input.priceAmount ? Number(input.priceAmount.replace(',', '.')).toFixed(2) : null,
      priceCurrency: input.priceCurrency,
      imageUrl: image?.dataUrl ?? null,
      thumbnailUrl: image?.dataUrl ?? null,
      note: input.note,
      occasion: input.occasion,
      visibility,
      publishedAt: 'published' === visibility ? now() : null,
      status: 'active',
      archivedAt: null,
      archiveKind: null,
      createdAt: now(),
      updatedAt: now(),
      canEdit: true,
      canUnarchive: false,
      ...(actingAs
        ? { view: 'manager' as const, isSuggestion: false, isMine: true, author: actor, reservation: null, contribution: null, reactions: { count: 0, likedByMe: false }, commentCount: 0, canReact: false, canMarkGifted: false }
        : isSuggestion
          ? { view: 'friend' as const, isSuggestion: true, isMine: true, author: me, canMarkGifted: true,
              ...('published' === visibility ? { reservation: null, contribution: null, reactions: { count: 0, likedByMe: false }, commentCount: 0, canReact: false } : {}) }
          : { view: 'owner' as const }),
    };
    await useLocalDb().put('idea', [idea]);

    await queue({
      method: 'POST',
      path: '/ideas',
      body: { id, ...input, ownerId },
      actingAs,
      entities: [{ type: 'idea', id }],
      label: { kind: 'idea.create', title: input.title },
    });
    if (image) {
      await queue({
        method: 'POST',
        path: `/ideas/${id}/image`,
        upload: { field: 'image', ...image },
        actingAs,
        entities: [{ type: 'idea', id }],
        label: { kind: 'idea.image', title: input.title },
      });
    }

    return idea;
  },

  async update(id: string, patch: Partial<IdeaInput>): Promise<Idea> {
    const { actingAs } = context();
    const local: Partial<Idea> = { ...patch } as Partial<Idea>;
    if ('priceAmount' in patch) local.priceAmount = patch.priceAmount ? Number(patch.priceAmount.replace(',', '.')).toFixed(2) : null;
    const idea = await patchIdea(id, local);
    await queue({ method: 'PATCH', path: `/ideas/${id}`, body: patch, actingAs, entities: [{ type: 'idea', id }], label: { kind: 'idea.update', title: idea.title } });

    return idea;
  },

  async remove(id: string): Promise<void> {
    const { actingAs } = context();
    const db = useLocalDb();
    const title = db.get<Idea>('idea', id)?.title;
    await db.remove('idea', [id]);
    await db.remove('comment', db.all<Comment>('comment').filter((c) => c.ideaId === id).map((c) => c.id));
    await queue({ method: 'DELETE', path: `/ideas/${id}`, actingAs, entities: [{ type: 'idea', id }], label: { kind: 'idea.delete', title } });
  },

  async publish(id: string): Promise<Idea> {
    const { actingAs } = context();
    const idea = await patchIdea(id, { visibility: 'published', publishedAt: now() });
    await queue({ method: 'POST', path: `/ideas/${id}/publish`, actingAs, entities: [{ type: 'idea', id }], label: { kind: 'idea.publish', title: idea.title } });

    return idea;
  },

  /** Spec §5.4: going private deletes the idea's interactions — locally too. */
  async unpublish(id: string): Promise<Idea> {
    const { actingAs } = context();
    const db = useLocalDb();
    const cleared: Partial<Idea> = { visibility: 'private', publishedAt: null };
    const current = db.get<Idea>('idea', id);
    if (current && 'reservation' in current) {
      Object.assign(cleared, { reservation: null, contribution: null, reactions: { count: 0, likedByMe: false }, commentCount: 0 });
    }
    const idea = await patchIdea(id, cleared);
    await db.remove('comment', db.all<Comment>('comment').filter((c) => c.ideaId === id).map((c) => c.id));
    await queue({ method: 'POST', path: `/ideas/${id}/unpublish`, actingAs, entities: [{ type: 'idea', id }], label: { kind: 'idea.unpublish', title: idea.title } });

    return idea;
  },

  async archive(id: string, kind: IdeaArchiveKind): Promise<Idea> {
    const { actingAs } = context();
    const current = useLocalDb().get<Idea>('idea', id);
    const contribution = current?.contribution && 'open' === current.contribution.status
      ? { ...current.contribution, status: 'closed' as const, closedAt: now() }
      : current?.contribution;
    const idea = await patchIdea(id, { status: 'archived', archiveKind: kind, archivedAt: now(), canUnarchive: true, canMarkGifted: false, ...(contribution !== undefined ? { contribution } : {}) });
    // « Offert » is the adult's own act; « reçue » follows the active profile.
    await queue({ method: 'POST', path: `/ideas/${id}/archive`, body: { kind }, actingAs: 'gifted' === kind ? null : actingAs, entities: [{ type: 'idea', id }], label: { kind: `idea.archive.${kind}`, title: idea.title } });

    return idea;
  },

  async unarchive(id: string): Promise<Idea> {
    const { actingAs } = context();
    const current = useLocalDb().get<Idea>('idea', id);
    const idea = await patchIdea(id, { status: 'active', archiveKind: null, archivedAt: null, canUnarchive: false });
    await queue({
      method: 'POST',
      path: `/ideas/${id}/unarchive`,
      actingAs: 'gifted' === current?.archiveKind ? null : actingAs,
      entities: [{ type: 'idea', id }],
      label: { kind: 'idea.unarchive', title: idea.title },
    });

    return idea;
  },

  /** A picture chosen offline travels later (spec §11 décision 34). */
  async setImage(id: string, image: { dataUrl: string; filename: string }): Promise<Idea> {
    const { actingAs } = context();
    const idea = await patchIdea(id, { imageUrl: image.dataUrl, thumbnailUrl: image.dataUrl });
    await queue({ method: 'POST', path: `/ideas/${id}/image`, upload: { field: 'image', ...image }, actingAs, entities: [{ type: 'idea', id }], label: { kind: 'idea.image', title: idea.title } });

    return idea;
  },

  async removeImage(id: string): Promise<Idea> {
    const { actingAs } = context();
    const idea = await patchIdea(id, { imageUrl: null, thumbnailUrl: null });
    await queue({ method: 'DELETE', path: `/ideas/${id}/image`, actingAs, entities: [{ type: 'idea', id }], label: { kind: 'idea.image', title: idea.title } });

    return idea;
  },
};

// --------------------------------------------------------- interactions

function recompute(contribution: Contribution, participants: ContributionParticipant[], myPledge: string | null): Contribution {
  // The total is shown to everyone: recompute it from what changed locally.
  const total = cents(contribution.totalAmount) - cents(contribution.myPledge) + cents(myPledge);
  const target = contribution.targetAmount ? cents(contribution.targetAmount) : null;

  return {
    ...contribution,
    participants,
    participantCount: participants.length,
    myPledge,
    totalAmount: money(total),
    remainingAmount: null !== target ? money(Math.max(0, target - total)) : null,
    goalReached: null !== target && total >= target,
  };
}

function newContribution(idea: Idea, me: PersonRef): Contribution {
  const target = idea.priceAmount;

  return {
    id: uuidv7(),
    ideaId: idea.id,
    status: 'open',
    initiator: me,
    isInitiator: true,
    targetAmount: target,
    currency: idea.priceCurrency,
    totalAmount: '0.00',
    remainingAmount: target,
    goalReached: false,
    participantCount: 0,
    participants: [],
    myPledge: null,
    closedAt: null,
  };
}

export const interactionMutations = {
  async reserve(ideaId: string): Promise<Idea> {
    const { me } = context();
    const id = uuidv7();
    const current = useLocalDb().get<Idea>('idea', ideaId)!;
    const idea = await patchIdea(ideaId, {
      reservation: { id, user: me, isMine: true, createdAt: now() },
      canMarkGifted: !!current.isSuggestion,
    });
    await queue({ method: 'POST', path: '/reservations', body: { id, ideaId }, entities: [{ type: 'idea', id: ideaId }], label: { kind: 'reservation.create', title: idea.title } });

    return idea;
  },

  async cancelReservation(reservationId: string): Promise<Idea> {
    const current = useLocalDb().all<Idea>('idea').find((i) => i.reservation?.id === reservationId)!;
    const idea = await patchIdea(current.id, { reservation: null, canMarkGifted: !!current.isSuggestion && !!current.isMine });
    await queue({ method: 'DELETE', path: `/reservations/${reservationId}`, entities: [{ type: 'idea', id: idea.id }], label: { kind: 'reservation.cancel', title: idea.title } });

    return idea;
  },

  async convertToContribution(reservationId: string): Promise<Contribution> {
    const { me } = context();
    const current = useLocalDb().all<Idea>('idea').find((i) => i.reservation?.id === reservationId)!;
    const contribution = newContribution(current, me);
    const idea = await patchIdea(current.id, { reservation: null, contribution });
    await queue({
      method: 'POST',
      path: `/reservations/${reservationId}/convert-to-contribution`,
      body: { id: contribution.id },
      entities: [{ type: 'idea', id: idea.id }],
      label: { kind: 'contribution.open', title: idea.title },
    });

    return { ...contribution, idea };
  },

  async openContribution(ideaId: string): Promise<Contribution> {
    const { me } = context();
    const current = useLocalDb().get<Idea>('idea', ideaId)!;
    const contribution = newContribution(current, me);
    const idea = await patchIdea(ideaId, { contribution, canMarkGifted: !!current.isSuggestion });
    await queue({ method: 'POST', path: '/contributions', body: { id: contribution.id, ideaId }, entities: [{ type: 'idea', id: ideaId }], label: { kind: 'contribution.open', title: idea.title } });

    return { ...contribution, idea };
  },

  async pledge(contributionId: string, amount: string): Promise<Contribution> {
    const { me } = context();
    const idea = ideaOfContribution(contributionId);
    const normalized = Number(amount.replace(',', '.')).toFixed(2);
    const others = idea.contribution!.participants.filter((p) => !p.isMe);
    const mine: ContributionParticipant = { user: me, isMe: true, isInitiator: !!idea.contribution!.isInitiator, amount: normalized };
    const contribution = recompute(idea.contribution!, [...others, mine], normalized);
    const updated = await patchIdea(idea.id, { contribution });
    await queue({ method: 'PUT', path: `/contributions/${contributionId}/pledge`, body: { amount: normalized }, entities: [{ type: 'idea', id: idea.id }], label: { kind: 'pledge.set', title: idea.title } });

    return { ...contribution, idea: updated };
  },

  async withdrawPledge(contributionId: string): Promise<Contribution> {
    const idea = ideaOfContribution(contributionId);
    const contribution = recompute(idea.contribution!, idea.contribution!.participants.filter((p) => !p.isMe), null);
    const updated = await patchIdea(idea.id, { contribution });
    await queue({ method: 'DELETE', path: `/contributions/${contributionId}/pledge`, entities: [{ type: 'idea', id: idea.id }], label: { kind: 'pledge.withdraw', title: idea.title } });

    return { ...contribution, idea: updated };
  },

  async setTarget(contributionId: string, targetAmount: string | null): Promise<Contribution> {
    const idea = ideaOfContribution(contributionId);
    const target = targetAmount ? Number(targetAmount.replace(',', '.')).toFixed(2) : null;
    const contribution = recompute({ ...idea.contribution!, targetAmount: target }, idea.contribution!.participants, idea.contribution!.myPledge);
    const updated = await patchIdea(idea.id, { contribution });
    await queue({ method: 'PATCH', path: `/contributions/${contributionId}`, body: { targetAmount: target }, entities: [{ type: 'idea', id: idea.id }], label: { kind: 'contribution.update', title: idea.title } });

    return { ...contribution, idea: updated };
  },

  async closeContribution(contributionId: string): Promise<Contribution> {
    const idea = ideaOfContribution(contributionId);
    const contribution: Contribution = { ...idea.contribution!, status: 'closed', closedAt: now() };
    const updated = await patchIdea(idea.id, { contribution });
    await queue({ method: 'POST', path: `/contributions/${contributionId}/close`, entities: [{ type: 'idea', id: idea.id }], label: { kind: 'contribution.close', title: idea.title } });

    return { ...contribution, idea: updated };
  },

  async addComment(ideaId: string, body: string): Promise<Comment> {
    const { me } = context();
    const db = useLocalDb();
    const comment: Comment = { id: uuidv7(), ideaId, author: me, isMine: true, body, createdAt: now(), editedAt: null };
    await db.put('comment', [comment]);
    const idea = await patchIdea(ideaId, { commentCount: (db.get<Idea>('idea', ideaId)?.commentCount ?? 0) + 1 });
    await queue({
      method: 'POST',
      path: '/comments',
      body: { id: comment.id, ideaId, body },
      entities: [{ type: 'comment', id: comment.id }, { type: 'idea', id: ideaId }],
      label: { kind: 'comment.create', title: idea.title },
    });

    return comment;
  },

  async editComment(id: string, body: string): Promise<Comment> {
    const comment = (await useLocalDb().patch<Comment>('comment', id, { body, editedAt: now() }))!;
    await queue({ method: 'PATCH', path: `/comments/${id}`, body: { body }, entities: [{ type: 'comment', id }], label: { kind: 'comment.update' } });

    return comment;
  },

  async deleteComment(id: string): Promise<void> {
    const db = useLocalDb();
    const comment = db.get<Comment>('comment', id);
    await db.remove('comment', [id]);
    if (comment) await patchIdea(comment.ideaId, { commentCount: Math.max(0, (db.get<Idea>('idea', comment.ideaId)?.commentCount ?? 1) - 1) });
    await queue({ method: 'DELETE', path: `/comments/${id}`, entities: [{ type: 'comment', id }], label: { kind: 'comment.delete' } });
  },

  async like(ideaId: string): Promise<Idea> {
    const current = useLocalDb().get<Idea>('idea', ideaId)!;
    const reactions = current.reactions?.likedByMe ? current.reactions : { count: (current.reactions?.count ?? 0) + 1, likedByMe: true };
    const idea = await patchIdea(ideaId, { reactions });
    await queue({ method: 'PUT', path: `/ideas/${ideaId}/reaction`, entities: [{ type: 'idea', id: ideaId }], label: { kind: 'reaction.like', title: idea.title } });

    return idea;
  },

  async unlike(ideaId: string): Promise<Idea> {
    const current = useLocalDb().get<Idea>('idea', ideaId)!;
    const reactions = current.reactions?.likedByMe ? { count: Math.max(0, current.reactions.count - 1), likedByMe: false } : current.reactions;
    const idea = await patchIdea(ideaId, { reactions });
    await queue({ method: 'DELETE', path: `/ideas/${ideaId}/reaction`, entities: [{ type: 'idea', id: ideaId }], label: { kind: 'reaction.unlike', title: idea.title } });

    return idea;
  },
};

function ideaOfContribution(contributionId: string): Idea {
  const idea = useLocalDb().all<Idea>('idea').find((i) => i.contribution?.id === contributionId);
  if (!idea?.contribution) throw new Error('contribution.not_local');

  return idea;
}

// -------------------------------------------------------------- profile

type LocalSize = ProfileSize & { id: string; userId: string };
type LocalPreference = ProfilePreference & { id: string; userId: string };

export const profileMutations = {
  async addSize(label: string, value: string, note: string | null, sortOrder: number): Promise<LocalSize> {
    const { actor, actingAs } = context();
    const id = uuidv7();
    const size: LocalSize = { id, '@id': `/api/profile_sizes/${id}`, userId: actor.id, label, value, note, sortOrder, history: [{ value, since: now() }] };
    await useLocalDb().put('profile_size', [size]);
    await queue({ method: 'POST', path: '/profile_sizes', body: { clientId: id, label, value, note, sortOrder }, actingAs, entities: [{ type: 'profile_size', id }], label: { kind: 'size.create', title: label } });

    return size;
  },

  async updateSize(id: string, patch: Partial<Pick<ProfileSize, 'label' | 'value' | 'note' | 'sortOrder'>>): Promise<void> {
    const { actingAs } = context();
    const current = useLocalDb().get<LocalSize>('profile_size', id);
    const valueChanged = undefined !== patch.value && patch.value !== current?.value;
    // As the server will record it (spec §11 décision 51): shown at once, even offline.
    const local: Partial<LocalSize> = valueChanged ? { ...patch, history: [...(current?.history ?? []), { value: patch.value!, since: now() }] } : patch;
    // Reordering only moves sortOrder; anything else is the size being edited.
    const edited = Object.keys(patch).some((key) => 'sortOrder' !== key);
    await useLocalDb().patch<LocalSize>('profile_size', id, local);
    await queue({ method: 'PATCH', path: `/profile_sizes/${id}`, body: patch, actingAs, entities: [{ type: 'profile_size', id }], label: edited ? { kind: 'size.edit', title: patch.label ?? current?.label } : { kind: 'size.update' } });
  },

  /** Removes a past value from a size's history — never the current one, which the server refuses. */
  async removeSizeHistoryEntry(id: string, entryId: string): Promise<void> {
    const { actingAs } = context();
    const current = useLocalDb().get<LocalSize>('profile_size', id);
    await useLocalDb().patch<LocalSize>('profile_size', id, { history: (current?.history ?? []).filter((entry) => entry.id !== entryId) });
    await queue({ method: 'DELETE', path: `/profile_sizes/${id}/history/${entryId}`, actingAs, entities: [{ type: 'profile_size', id }], label: { kind: 'size.history_delete', title: current?.label } });
  },

  async removeSize(id: string): Promise<void> {
    const { actingAs } = context();
    await useLocalDb().remove('profile_size', [id]);
    await queue({ method: 'DELETE', path: `/profile_sizes/${id}`, actingAs, entities: [{ type: 'profile_size', id }], label: { kind: 'size.delete' } });
  },

  async addPreference(category: ProfilePreferenceCategory, label: string, value: string): Promise<LocalPreference> {
    const { actor, actingAs } = context();
    const id = uuidv7();
    const preference: LocalPreference = { id, '@id': `/api/profile_preferences/${id}`, userId: actor.id, category, label, value };
    await useLocalDb().put('profile_preference', [preference]);
    await queue({ method: 'POST', path: '/profile_preferences', body: { clientId: id, category, label, value }, actingAs, entities: [{ type: 'profile_preference', id }], label: { kind: 'preference.create', title: label } });

    return preference;
  },

  async removePreference(id: string): Promise<void> {
    const { actingAs } = context();
    await useLocalDb().remove('profile_preference', [id]);
    await queue({ method: 'DELETE', path: `/profile_preferences/${id}`, actingAs, entities: [{ type: 'profile_preference', id }], label: { kind: 'preference.delete' } });
  },
};

// -------------------------------------------------------- notifications

export const notificationMutations = {
  async markRead(id: string): Promise<void> {
    await useLocalDb().patch<AppNotification>('notification', id, { readAt: now() });
    await queue({ method: 'POST', path: `/notifications/${id}/read`, entities: [{ type: 'notification', id }], label: { kind: 'notification.read' } });
  },

  async markAllRead(): Promise<void> {
    const db = useLocalDb();
    const unread = db.all<AppNotification>('notification').filter((n) => !n.readAt);
    await db.put('notification', unread.map((n) => ({ ...n, readAt: now() })));
    await queue({ method: 'POST', path: '/notifications/read-all', entities: unread.map((n) => ({ type: 'notification' as const, id: n.id })), label: { kind: 'notification.readAll' } });
  },
};
