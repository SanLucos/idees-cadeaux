import { afterEach, describe, expect, it } from 'vitest';
import { readFriendSort, sortFriends, storeFriendSort } from '../../src/utils/friendSort';
import type { Friendship } from '../../src/types/friendship';

function friend(id: string, displayName: string, createdAt: string, respondedAt: string | null = null): Friendship {
  return {
    id,
    status: 'accepted',
    origin: 'request',
    direction: 'outgoing',
    createdAt,
    respondedAt,
    user: { id: `u-${id}`, displayName, avatarUrl: null },
  };
}

const friends = [
  friend('1', 'Zoé', '2026-01-01T00:00:00Z', '2026-03-01T00:00:00Z'),
  friend('2', 'élodie', '2026-02-01T00:00:00Z'),
  friend('3', 'Bruno', '2026-01-15T00:00:00Z', '2026-01-20T00:00:00Z'),
];

describe('sortFriends', () => {
  it('sorts by pseudo, accents and case aside', () => {
    expect(sortFriends(friends, 'name', 'fr').map((f) => f.user.displayName)).toEqual(['Bruno', 'élodie', 'Zoé']);
  });

  it('puts the newest friendship first, by acceptance date when there is one', () => {
    expect(sortFriends(friends, 'added', 'fr').map((f) => f.user.displayName)).toEqual(['Zoé', 'élodie', 'Bruno']);
  });

  it('leaves the given list untouched', () => {
    sortFriends(friends, 'name', 'fr');
    expect(friends[0].user.displayName).toBe('Zoé');
  });
});

describe('friend sort preference', () => {
  afterEach(() => localStorage.clear());

  it('is alphabetical by default and remembers the choice', () => {
    expect(readFriendSort()).toBe('name');
    storeFriendSort('added');
    expect(readFriendSort()).toBe('added');
  });
});
