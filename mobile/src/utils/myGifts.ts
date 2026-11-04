import { daysUntilBirthday } from './birthday';
import type { MyGift, MyGiftKind } from '../types/idea';

export type MyGiftFilter = 'all' | MyGiftKind;

export interface MyGiftGroup {
  recipient: MyGift['recipient'];
  /** Days to the next birthday, or null when unknown. */
  days: number | null;
  gifts: MyGift[];
}

const ORDER: Record<MyGiftKind, number> = { reserved: 0, contribution: 1, given: 2 };

/**
 * « Mes cadeaux pour mes amis »: filtered, then grouped by recipient —
 * nearest birthday first (unknown last, then by name). Within a group,
 * gifts still to offer before those already given; `gifts` arrive
 * newest first (repo.myGifts) and keep that order inside each kind.
 */
export function groupMyGifts(
  gifts: MyGift[],
  filter: { kind: MyGiftFilter; person: string | null },
  today: Date = new Date(),
): MyGiftGroup[] {
  const groups = new Map<string, MyGiftGroup>();
  for (const gift of gifts) {
    if ('all' !== filter.kind && gift.kind !== filter.kind) continue;
    if (null !== filter.person && gift.recipient.id !== filter.person) continue;

    let group = groups.get(gift.recipient.id);
    if (!group) {
      const { birthDay, birthMonth } = gift.recipient;
      group = { recipient: gift.recipient, days: birthDay && birthMonth ? daysUntilBirthday(birthDay, birthMonth, today) : null, gifts: [] };
      groups.set(gift.recipient.id, group);
    }
    group.gifts.push(gift);
  }

  for (const group of groups.values()) group.gifts.sort((a, b) => ORDER[a.kind] - ORDER[b.kind]);

  return [...groups.values()].sort(
    (a, b) =>
      (a.days ?? Infinity) - (b.days ?? Infinity) || (a.recipient.displayName ?? '').localeCompare(b.recipient.displayName ?? ''),
  );
}

/** Gifts still to offer: the count on the entry links. */
export function giftsToOffer(gifts: MyGift[]): number {
  return gifts.filter((g) => 'given' !== g.kind).length;
}
