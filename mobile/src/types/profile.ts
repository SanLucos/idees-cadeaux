import type { SizeUnit } from '../utils/sizeUnits';

export type ProfilePreferenceCategory = 'gout' | 'marque' | 'autre';

/** A value a size has had, since that date. */
export interface SizeHistoryEntry {
  /** Absent until the server has recorded the entry (a change made offline). */
  id?: string;
  value: string;
  /** Absent from sizes written before units (spec §11 décision 57): no unit. */
  unit?: SizeUnit | null;
  since: string;
}

export interface ProfileSize {
  '@id': string;
  label: string;
  value: string;
  /** null or absent: no unit (spec §11 décision 57). */
  unit?: SizeUnit | null;
  note: string | null;
  sortOrder: number;
  /**
   * Oldest first, the current value last. Only on my own sizes and my
   * children's: the server never sends a friend's (spec §11 décision 51).
   */
  history?: SizeHistoryEntry[];
}

export interface ProfilePreference {
  '@id': string;
  category: ProfilePreferenceCategory;
  label: string;
  value: string;
}

export interface HydraCollection<T> {
  member: T[];
  totalItems: number;
}
