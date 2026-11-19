import type { Friendship } from '../types/friendship';
import { compareNames } from './names';

/** How the friends list is ordered: by pseudo, or newest friend first. */
export type FriendSort = 'name' | 'added';

const STORAGE_KEY = 'ic.friendSort';

/** When the friendship began: accepted, or created when there was no request (share link, family). */
function addedAt(f: Friendship): string {
  return f.respondedAt ?? f.createdAt;
}

export function sortFriends(friends: Friendship[], sort: FriendSort, locale: string): Friendship[] {
  return [...friends].sort((a, b) =>
    'added' === sort
      ? addedAt(b).localeCompare(addedAt(a)) || compareNames(a.user.displayName, b.user.displayName, locale)
      : compareNames(a.user.displayName, b.user.displayName, locale) || addedAt(b).localeCompare(addedAt(a)),
  );
}

/** A per-device convenience: alphabetical when nothing (or nothing readable) is stored. */
export function readFriendSort(): FriendSort {
  try {
    return 'added' === localStorage.getItem(STORAGE_KEY) ? 'added' : 'name';
  } catch {
    return 'name';
  }
}

export function storeFriendSort(sort: FriendSort): void {
  try {
    localStorage.setItem(STORAGE_KEY, sort);
  } catch {
    // Not remembered: the list opens alphabetical next time.
  }
}
