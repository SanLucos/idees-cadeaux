import { defineStore } from 'pinia';
import { notificationsApi } from '../services/notifications';

/** The Activité tab badge: refreshed on demand and every minute while the app is open. */
export const useNotificationsStore = defineStore('notifications', {
  state: () => ({
    unreadCount: 0,
    timer: null as ReturnType<typeof setInterval> | null,
  }),
  actions: {
    async refreshCount(): Promise<void> {
      try {
        this.unreadCount = await notificationsApi.unreadCount();
      } catch {
        // Offline or signed out: keep the last known count.
      }
    },

    startPolling(): void {
      if (this.timer) return;
      void this.refreshCount();
      this.timer = setInterval(() => void this.refreshCount(), 60_000);
    },

    stopPolling(): void {
      if (this.timer) clearInterval(this.timer);
      this.timer = null;
      this.unreadCount = 0;
    },
  },
});
