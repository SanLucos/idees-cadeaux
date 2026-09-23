import { defineStore } from 'pinia';
import { api } from '../services/api';
import { repo } from '../offline/repo';
import { profileMutations } from '../offline/mutations';
import { useAuthStore } from './auth';
import { useActiveProfileStore } from './activeProfile';
import type { HydraCollection, ProfilePreference, ProfilePreferenceCategory, ProfileSize } from '../types/profile';

type Local<T> = T & { id: string };

function selfId(): string {
  return useActiveProfileStore().activeId ?? useAuthStore().user?.id ?? '';
}

/** Sizes and preferences of me (or the active child), offline-capable (spec §8). */
export const useProfileDetailsStore = defineStore('profileDetails', {
  state: () => ({
    sizes: [] as Local<ProfileSize>[],
    preferences: [] as Local<ProfilePreference>[],
  }),
  actions: {
    async fetchSizes(): Promise<void> {
      this.sizes = repo.sizes(selfId()) as Local<ProfileSize>[];
    },

    async addSize(label: string, value: string, note: string | null): Promise<void> {
      await profileMutations.addSize(label, value, note, this.sizes.length);
      await this.fetchSizes();
    },

    /** Drag-and-drop: persist the new sortOrder of every entry that moved. */
    async moveSize(from: number, to: number): Promise<void> {
      const sizes = [...this.sizes];
      const [moved] = sizes.splice(from, 1);
      sizes.splice(to, 0, moved);
      this.sizes = sizes;
      for (const [index, size] of sizes.entries()) {
        if (size.sortOrder !== index) await profileMutations.updateSize(size.id, { sortOrder: index });
      }
      await this.fetchSizes();
    },

    async removeSize(size: ProfileSize): Promise<void> {
      await profileMutations.removeSize((size as Local<ProfileSize>).id);
      await this.fetchSizes();
    },

    async fetchPreferences(): Promise<void> {
      this.preferences = repo.preferences(selfId()) as Local<ProfilePreference>[];
    },

    async addPreference(category: ProfilePreferenceCategory, label: string, value: string): Promise<void> {
      await profileMutations.addPreference(category, label, value);
      await this.fetchPreferences();
    },

    async removePreference(preference: ProfilePreference): Promise<void> {
      await profileMutations.removePreference((preference as Local<ProfilePreference>).id);
      await this.fetchPreferences();
    },

    /** Read-only: a friend's (or child's) entries — from the device when synced, else online. */
    async fetchSizesFor(userId: string): Promise<ProfileSize[]> {
      if (repo.user(userId)) return repo.sizes(userId);

      return (await api.get<HydraCollection<ProfileSize>>(`/profile_sizes?userId=${userId}`)).member;
    },

    async fetchPreferencesFor(userId: string): Promise<ProfilePreference[]> {
      if (repo.user(userId)) return repo.preferences(userId);

      return (await api.get<HydraCollection<ProfilePreference>>(`/profile_preferences?userId=${userId}`)).member;
    },
  },
});
