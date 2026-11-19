import { daysUntilBirthday } from './birthday';
import { recipientsKey } from './recipients';
import type { MyGift, MyGiftKind, MyGiftPerson } from '../types/idea';

export type MyGiftFilter = 'all' | MyGiftKind;

export interface MyGiftGroup {
  /** The same people, whatever the order: one group per person, or per set of people (décision 69). */
  key: string;
  recipients: MyGiftPerson[];
  /** Days to the next birthday (the nearest one among them), or null when unknown. */
  days: number | null;
  gifts: MyGift[];
}

const ORDER: Record<MyGiftKind, number> = { reserved: 0, contribution: 1, given: 2 };

/**
 * « Mes cadeaux pour mes amis »: filtered, then grouped by recipients —
 * an idea for several people under all of them together (décision 69),
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
    if (null !== filter.person && !gift.recipients.some((r) => r.id === filter.person)) continue;

    const key = recipientsKey(gift.recipients.map((r) => r.id));
    let group = groups.get(key);
    if (!group) {
      const days = gift.recipients
        .filter((r) => r.birthDay && r.birthMonth)
        .map((r) => daysUntilBirthday(r.birthDay!, r.birthMonth!, today));
      group = { key, recipients: gift.recipients, days: days.length ? Math.min(...days) : null, gifts: [] };
      groups.set(key, group);
    }
    group.gifts.push(gift);
  }

  for (const group of groups.values()) group.gifts.sort((a, b) => ORDER[a.kind] - ORDER[b.kind]);

  return [...groups.values()].sort(
    (a, b) =>
      (a.days ?? Infinity) - (b.days ?? Infinity) || groupName(a).localeCompare(groupName(b)) || a.recipients.length - b.recipients.length,
  );
}

function groupName(group: MyGiftGroup): string {
  return group.recipients.map((r) => r.displayName ?? '').join(' ');
}

/** Gifts still to offer: the count on the entry links. */
export function giftsToOffer(gifts: MyGift[]): number {
  return gifts.filter((g) => 'given' !== g.kind).length;
}
