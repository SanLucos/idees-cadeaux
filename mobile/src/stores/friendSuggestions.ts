import { defineStore } from 'pinia';
import { api } from '../services/api';
import { useFriendsStore } from './friends';
import type { ReceivedFriendSuggestion, SentFriendSuggestion } from '../types/friendSuggestion';

/** Always my own suggestions, never a child's (spec §11 décision 65): no X-Acting-As. */
const OWN = { acting: false } as const;

/**
 * « Présenter à des amis » (spec §11 décision 65). Online only, like
 * every friend action (décision 35): nothing is kept on the device.
 */
export const useFriendSuggestionsStore = defineStore('friendSuggestions', {
  state: () => ({
    received: [] as ReceivedFriendSuggestion[],
    sent: [] as SentFriendSuggestion[],
  }),
  getters: {
    /** My pending introductions of one friend. */
    sentAbout: (state) => (userId: string) => state.sent.filter((s) => s.suggested.id === userId),
  },
  actions: {
    async fetchReceived(): Promise<void> {
      this.received = await api.get<ReceivedFriendSuggestion[]>('/friend-suggestions', OWN);
    },

    async fetchSent(): Promise<void> {
      this.sent = await api.get<SentFriendSuggestion[]>('/friend-suggestions?sent=1', OWN);
    },

    async suggest(suggestedId: string, recipientIds: string[]): Promise<void> {
      this.sent = await api.post<SentFriendSuggestion[]>('/friend-suggestions', {
        json: { suggested: suggestedId, recipients: recipientIds },
        ...OWN,
      });
    },

    /** An ordinary friend request from me to the person suggested. */
    async sendRequest(id: string): Promise<void> {
      await api.post(`/friend-suggestions/${id}/send-request`, OWN);
      this.received = this.received.filter((s) => s.id !== id);
      await useFriendsStore().afterWrite();
    },

    async dismiss(id: string): Promise<void> {
      await api.post(`/friend-suggestions/${id}/dismiss`, OWN);
      this.received = this.received.filter((s) => s.id !== id);
    },

    async cancel(id: string): Promise<void> {
      await api.delete(`/friend-suggestions/${id}`, OWN);
      this.sent = this.sent.filter((s) => s.id !== id);
    },
  },
});
