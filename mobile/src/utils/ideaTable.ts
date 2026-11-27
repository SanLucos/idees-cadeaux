import type { Idea } from '../types/idea';

/**
 * The idea tables of the computer layout (spec §11 décision 72): every
 * idea of a list in one table, told apart by columns and filtered on the
 * device. The API still decides what each list contains (spec §4): these
 * helpers only sort out what it sent.
 */

/** Ma liste: « État » column and filter. */
export type IdeaState = 'published' | 'draft' | 'archived';
/** A friend's list: « Source » column and filter. */
export type IdeaSource = 'owner' | 'suggestion' | 'draft';
/** A friend's list: « État » filter (en cours / archivées). */
export type IdeaPhase = 'active' | 'archived';

/** The « Sans occasion » filter value. */
export const NO_OCCASION = 'none';

export type SortKey = 'title' | 'state' | 'source' | 'occasion' | 'price' | 'added';
export interface TableSort {
  key: SortKey;
  dir: 'asc' | 'desc';
}

export const DEFAULT_SORT: TableSort = { key: 'added', dir: 'desc' };

export function ideaState(idea: Idea): IdeaState {
  if ('archived' === idea.status) return 'archived';

  return 'private' === idea.visibility ? 'draft' : 'published';
}

/** On a friend's list, a private idea can only be one of my own drafts for them. */
export function ideaSource(idea: Idea): IdeaSource {
  if ('private' === idea.visibility) return 'draft';

  return idea.isSuggestion ? 'suggestion' : 'owner';
}

export function ideaPhase(idea: Idea): IdeaPhase {
  return 'archived' === idea.status ? 'archived' : 'active';
}

export function occasionKey(idea: Idea): string {
  return idea.occasion ?? NO_OCCASION;
}

export function countBy<K extends string>(ideas: Idea[], key: (idea: Idea) => K): Partial<Record<K, number>> {
  const counts: Partial<Record<K, number>> = {};
  for (const idea of ideas) {
    const k = key(idea);
    counts[k] = (counts[k] ?? 0) + 1;
  }

  return counts;
}

/** The same idea may come in two slices (a page shifting under a write): keep the first. */
export function uniqueIdeas(ideas: Idea[]): Idea[] {
  const seen = new Set<string>();

  return ideas.filter((idea) => (seen.has(idea.id) ? false : (seen.add(idea.id), true)));
}

export function matchesSearch(idea: Idea, search: string): boolean {
  const q = search.trim().toLocaleLowerCase();
  if (!q) return true;

  return [idea.title, idea.note ?? ''].some((text) => text.toLocaleLowerCase().includes(q));
}

export interface IdeaFilter {
  states?: ReadonlySet<IdeaState>;
  sources?: ReadonlySet<IdeaSource>;
  phases?: ReadonlySet<IdeaPhase>;
  /** An occasion code, NO_OCCASION, or null for all. */
  occasion?: string | null;
  search?: string;
}

export function filterIdeas(ideas: Idea[], filter: IdeaFilter): Idea[] {
  return ideas.filter(
    (idea) =>
      (!filter.states || filter.states.has(ideaState(idea)))
      && (!filter.sources || filter.sources.has(ideaSource(idea)))
      && (!filter.phases || filter.phases.has(ideaPhase(idea)))
      && (!filter.occasion || occasionKey(idea) === filter.occasion)
      && matchesSearch(idea, filter.search ?? ''),
  );
}

/** Labels the table shows, so the text columns sort as they read. */
export interface SortLabels {
  occasion: (idea: Idea) => string;
  source: (idea: Idea) => string;
}

const STATE_ORDER: Record<IdeaState, number> = { published: 0, draft: 1, archived: 2 };

function price(idea: Idea): number | null {
  const amount = null === idea.priceAmount ? NaN : Number(idea.priceAmount);

  return Number.isFinite(amount) ? amount : null;
}

/**
 * Sorts a copy. Ideas without a price always go last; ties keep the most
 * recent first, so the order never depends on how the slices arrived.
 */
export function sortIdeas(ideas: Idea[], sort: TableSort, labels: SortLabels, locale: string): Idea[] {
  const collator = new Intl.Collator(locale, { sensitivity: 'base', numeric: true });
  const sign = 'asc' === sort.dir ? 1 : -1;
  const recentFirst = (a: Idea, b: Idea) => b.createdAt.localeCompare(a.createdAt);

  const compare = (a: Idea, b: Idea): number => {
    switch (sort.key) {
      case 'title':
        return sign * collator.compare(a.title, b.title);
      case 'state':
        return sign * (STATE_ORDER[ideaState(a)] - STATE_ORDER[ideaState(b)]);
      case 'source':
        return sign * collator.compare(labels.source(a), labels.source(b));
      case 'occasion':
        return sign * collator.compare(labels.occasion(a), labels.occasion(b));
      case 'price': {
        const pa = price(a);
        const pb = price(b);
        if (null === pa || null === pb) return null === pa ? (null === pb ? 0 : 1) : -1;

        return sign * (pa - pb);
      }
      case 'added':
        return -sign * recentFirst(a, b);
    }
  };

  return [...ideas].sort((a, b) => compare(a, b) || recentFirst(a, b));
}

/** A filter kept between visits — a convenience: losing it just shows the default. */
export function readStoredSet<T extends string>(key: string, allowed: readonly T[], fallback: readonly T[]): Set<T> {
  try {
    const raw = localStorage.getItem(key);
    if (null !== raw) {
      const values = JSON.parse(raw);
      if (Array.isArray(values)) return new Set(values.filter((v): v is T => allowed.includes(v)));
    }
  } catch {
    // Unreadable storage: the default.
  }

  return new Set(fallback);
}

export function storeSet(key: string, values: ReadonlySet<string>): void {
  try {
    localStorage.setItem(key, JSON.stringify([...values]));
  } catch {
    // Ignored: see readStoredSet().
  }
}

/** Toggles a value in a copy of the set. */
export function toggled<T>(values: ReadonlySet<T>, value: T): Set<T> {
  const next = new Set(values);
  if (next.has(value)) next.delete(value);
  else next.add(value);

  return next;
}
