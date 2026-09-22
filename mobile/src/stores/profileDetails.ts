import { defineStore } from 'pinia';
import { api } from '../services/api';
import type { HydraCollection, ProfilePreference, ProfilePreferenceCategory, ProfileSize } from '../types/profile';

// API Platform resource IRIs already include the /api prefix that
// services/api.ts's base URL also carries — strip it before reusing
// an @id as a request path.
function toApiPath(iri: string): string {
  return iri.replace(/^\/api/, '');
}

export const useProfileDetailsStore = defineStore('profileDetails', {
  state: () => ({
    sizes: [] as ProfileSize[],
    preferences: [] as ProfilePreference[],
  }),
  actions: {
    async fetchSizes(): Promise<void> {
      const response = await api.get<HydraCollection<ProfileSize>>('/profile_sizes');
      this.sizes = response.member;
    },

    async addSize(label: string, value: string, note: string | null): Promise<void> {
      const created = await api.post<ProfileSize>('/profile_sizes', { json: { label, value, note } });
      this.sizes.push(created);
    },

    async removeSize(size: ProfileSize): Promise<void> {
      await api.delete(toApiPath(size['@id']));
      this.sizes = this.sizes.filter((s) => s['@id'] !== size['@id']);
    },

    async fetchPreferences(): Promise<void> {
      const response = await api.get<HydraCollection<ProfilePreference>>('/profile_preferences');
      this.preferences = response.member;
    },

    async addPreference(category: ProfilePreferenceCategory, label: string, value: string): Promise<void> {
      const created = await api.post<ProfilePreference>('/profile_preferences', { json: { category, label, value } });
      this.preferences.push(created);
    },

    async removePreference(preference: ProfilePreference): Promise<void> {
      await api.delete(toApiPath(preference['@id']));
      this.preferences = this.preferences.filter((p) => p['@id'] !== preference['@id']);
    },

    /** Read-only: a friend's sizes/preferences (spec §4), never mutates this store's own `sizes`/`preferences`. */
    async fetchSizesFor(userId: string): Promise<ProfileSize[]> {
      return (await api.get<HydraCollection<ProfileSize>>(`/profile_sizes?userId=${userId}`)).member;
    },

    async fetchPreferencesFor(userId: string): Promise<ProfilePreference[]> {
      return (await api.get<HydraCollection<ProfilePreference>>(`/profile_preferences?userId=${userId}`)).member;
    },
  },
});
