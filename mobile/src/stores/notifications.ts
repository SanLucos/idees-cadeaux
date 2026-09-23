import { defineStore } from 'pinia';
import { repo } from '../offline/repo';
import { useLocalDb } from '../offline/db';

/** The Activité tab badge, from the synced centre (spec §8: works offline too). */
export const useNotificationsStore = defineStore('notifications', {
  getters: {
    unreadCount(): number {
      void useLocalDb().revision;

      return repo.unreadCount();
    },
  },
  actions: {
    /** Kept for callers: the count follows the local data by itself. */
    startPolling(): void {},
    stopPolling(): void {},
    async refreshCount(): Promise<void> {},
  },
});
