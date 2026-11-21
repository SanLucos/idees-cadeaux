import { defineStore } from 'pinia';
import { api } from '../services/api';
import { useFriendsStore } from './friends';
import type { Introduction } from '../types/introduction';

/** Always my own, never a child's (spec §11 décision 71): no X-Acting-As. */
const OWN = { acting: false } as const;

/**
 * Team introductions (spec §5.17, §11 décision 71). Online only, like
 * every friend action (décision 35): nothing is kept on the device.
 */
export const useIntroductionsStore = defineStore('introductions', {
  state: () => ({
    received: [] as Introduction[],
  }),
  actions: {
    async fetch(): Promise<void> {
      this.received = await api.get<Introduction[]>('/introductions', OWN);
    },

    /** @returns whether we're friends now (the other had accepted already) */
    async accept(id: string): Promise<boolean> {
      const { status } = await api.post<{ status: 'accepted' | 'friends' }>(`/introductions/${id}/accept`, OWN);
      this.received = this.received.filter((i) => i.id !== id);
      if ('friends' === status) await useFriendsStore().afterWrite();

      return 'friends' === status;
    },

    async ignore(id: string): Promise<void> {
      await api.post(`/introductions/${id}/ignore`, OWN);
      this.received = this.received.filter((i) => i.id !== id);
    },
  },
});
