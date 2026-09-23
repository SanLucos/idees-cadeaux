import { defineStore } from 'pinia';
import { api } from '../services/api';
import type { Occasion } from '../types/idea';

/** Reference list (spec §5.4): fetched once per session. */
export const useOccasionsStore = defineStore('occasions', {
  state: () => ({
    occasions: [] as Occasion[],
    loaded: false,
  }),
  actions: {
    async ensureLoaded(): Promise<void> {
      if (this.loaded) return;
      this.occasions = await api.get<Occasion[]>('/occasions');
      this.loaded = true;
    },
  },
});
