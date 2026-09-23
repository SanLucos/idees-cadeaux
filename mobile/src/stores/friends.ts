import { defineStore } from 'pinia';
import { api } from '../services/api';
import { collectHashedContactEmails } from '../services/contactsMatch';
import { repo } from '../offline/repo';
import { useSync } from '../offline/sync';
import { useAuthStore } from './auth';
import { useActiveProfileStore } from './activeProfile';
import type { ContactMatch, Friendship } from '../types/friendship';

/**
 * Friends of me or of the active child profile: read from the device
 * (spec §8); writes stay online-only (spec §11 décision 35) and are
 * followed by a sync so the device catches up.
 */
export const useFriendsStore = defineStore('friends', {
  state: () => ({
    friends: [] as Friendship[],
    incoming: [] as Friendship[],
    outgoing: [] as Friendship[],
    contactMatches: [] as ContactMatch[],
  }),
  actions: {
    /** Re-read the local copy (cheap: call it whenever local data moved). */
    refresh(): void {
      const scope = useActiveProfileStore().activeId ?? useAuthStore().user?.id ?? '';
      const { friends, incoming, outgoing } = repo.friendships(scope);
      this.friends = friends;
      this.incoming = incoming;
      this.outgoing = outgoing;
    },

    async fetchFriends(): Promise<void> {
      this.refresh();
    },

    async fetchAll(): Promise<void> {
      this.refresh();
      void useSync().run();
    },

    async afterWrite(): Promise<void> {
      await useSync().run();
      this.refresh();
    },

    /** Always resolves: the backend answers identically whether or not the account exists (spec §5.3). */
    async sendRequest(email: string): Promise<void> {
      await api.post('/friendships', { json: { email } });
      await this.afterWrite();
    },

    /** From a contacts match: the device already knows this account exists. */
    async sendRequestToUser(userId: string): Promise<void> {
      await api.post('/friendships', { json: { userId } });
      await this.afterWrite();
    },

    async accept(id: string): Promise<void> {
      await api.post(`/friendships/${id}/accept`);
      await this.afterWrite();
    },

    async decline(id: string): Promise<void> {
      await api.post(`/friendships/${id}/decline`);
      await this.afterWrite();
    },

    async cancel(id: string): Promise<void> {
      await api.post(`/friendships/${id}/cancel`);
      await this.afterWrite();
    },

    async remove(id: string): Promise<void> {
      await api.delete(`/friendships/${id}`);
      await this.afterWrite();
    },

    async matchContacts(): Promise<void> {
      const hashedEmails = await collectHashedContactEmails();
      if (0 === hashedEmails.length) {
        this.contactMatches = [];

        return;
      }
      this.contactMatches = await api.post<ContactMatch[]>('/contacts/match', { json: { hashedEmails } });
    },
  },
});
