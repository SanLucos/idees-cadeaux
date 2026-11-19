import type { Idea, PersonRef } from '../types/idea';

/**
 * An idea's recipients (spec §11 décision 69). Documents synced before
 * lot 15 only had `ownerId`: read as a single recipient until the next
 * sync replaces them.
 */
export function recipientsOf(idea: Pick<Idea, 'recipients'>): PersonRef[] {
  if (Array.isArray(idea.recipients)) return idea.recipients;
  const legacy = (idea as { ownerId?: string }).ownerId;

  return legacy ? [{ id: legacy, displayName: null, avatarUrl: null }] : [];
}

export function recipientIds(idea: Pick<Idea, 'recipients'>): string[] {
  return recipientsOf(idea).map((r) => r.id);
}

export function isFor(idea: Pick<Idea, 'recipients'>, userId: string | null | undefined): boolean {
  return !!userId && recipientsOf(idea).some((r) => r.id === userId);
}

/** More than one recipient: an idea for several people. */
export function isShared(idea: Pick<Idea, 'recipients'>): boolean {
  return recipientsOf(idea).length > 1;
}

/** Same people, whatever the order: the key of a group of recipients. */
export function recipientsKey(ids: string[]): string {
  return [...ids].sort().join('+');
}
