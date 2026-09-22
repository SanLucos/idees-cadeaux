export type ProfilePreferenceCategory = 'gout' | 'marque' | 'autre';

export interface ProfileSize {
  '@id': string;
  label: string;
  value: string;
  note: string | null;
  sortOrder: number;
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
