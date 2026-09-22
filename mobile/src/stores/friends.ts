import { defineStore } from 'pinia';
import { api } from '../services/api';
import { collectHashedContactEmails } from '../services/contactsMatch';
import type { ContactMatch, Friendship } from '../types/friendship';

export const useFriendsStore = defineStore('friends', {
  state: () => ({
    friends: [] as Friendship[],
    incoming: [] as Friendship[],
    outgoing: [] as Friendship[],
    contactMatches: [] as ContactMatch[],
  }),
  actions: {
    async fetchFriends(): Promise<void> {
      this.friends = await api.get<Friendship[]>('/friendships');
    },

    async fetchIncoming(): Promise<void> {
      this.incoming = await api.get<Friendship[]>('/friendships/incoming');
    },

    async fetchOutgoing(): Promise<void> {
      this.outgoing = await api.get<Friendship[]>('/friendships/outgoing');
    },

    async fetchAll(): Promise<void> {
      await Promise.all([this.fetchFriends(), this.fetchIncoming(), this.fetchOutgoing()]);
    },

    /** Always resolves: the backend answers identically whether or not the account exists (spec §5.3). */
    async sendRequest(email: string): Promise<void> {
      await api.post('/friendships', { json: { email } });
      await this.fetchOutgoing();
    },

    /** From a contacts match: the device already knows this account exists. */
    async sendRequestToUser(userId: string): Promise<void> {
      await api.post('/friendships', { json: { userId } });
      await this.fetchOutgoing();
    },

    async accept(id: string): Promise<void> {
      await api.post(`/friendships/${id}/accept`);
      await Promise.all([this.fetchIncoming(), this.fetchFriends()]);
    },

    async decline(id: string): Promise<void> {
      await api.post(`/friendships/${id}/decline`);
      await this.fetchIncoming();
    },

    async cancel(id: string): Promise<void> {
      await api.post(`/friendships/${id}/cancel`);
      await this.fetchOutgoing();
    },

    async remove(id: string): Promise<void> {
      await api.delete(`/friendships/${id}`);
      await this.fetchFriends();
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
