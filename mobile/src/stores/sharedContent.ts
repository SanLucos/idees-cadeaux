import { defineStore } from 'pinia';
import type { SharedContent } from '../utils/sharedContent';

/**
 * A share received from another app (spec §5.6), waiting for the idea
 * form. Kept here, not in the URL, so it survives the detour through
 * sign-in or onboarding.
 */
export const useSharedContentStore = defineStore('sharedContent', {
  state: () => ({
    pending: null as SharedContent | null,
  }),
  actions: {
    receive(content: SharedContent): void {
      this.pending = content;
    },
    consume(): SharedContent | null {
      const content = this.pending;
      this.pending = null;

      return content;
    },
  },
});
