import { defineStore } from 'pinia';
import { api } from '../services/api';
import { repo } from '../offline/repo';
import type { Occasion } from '../types/idea';

/** Reference list (spec §5.4): from the device once synced, else fetched once. */
export const useOccasionsStore = defineStore('occasions', {
  state: () => ({
    occasions: [] as Occasion[],
    loaded: false,
  }),
  actions: {
    async ensureLoaded(): Promise<void> {
      if (this.loaded) return;
      const local = repo.occasions();
      this.occasions = local.length ? local : await api.get<Occasion[]>('/occasions');
      this.loaded = true;
    },
  },
});
