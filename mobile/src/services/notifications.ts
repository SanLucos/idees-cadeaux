import { api } from './api';
import { repo } from '../offline/repo';
import { notificationMutations } from '../offline/mutations';
import type { NotificationPage, NotificationSettings } from '../types/notification';

const PER_PAGE = 30;

/** Always the adult's own centre and settings, never a child's (acting: false). */
const OWN = { acting: false } as const;

export const notificationsApi = {
  /** From the device: the synced centre works offline (spec §8). */
  async list(page = 1): Promise<NotificationPage> {
    const all = repo.notifications();

    return { member: all.slice((page - 1) * PER_PAGE, page * PER_PAGE), totalItems: all.length, unreadCount: repo.unreadCount(), page };
  },

  async unreadCount(): Promise<number> {
    return repo.unreadCount();
  },

  markRead(id: string): Promise<void> {
    return notificationMutations.markRead(id);
  },

  markAllRead(): Promise<void> {
    return notificationMutations.markAllRead();
  },

  settings(): Promise<NotificationSettings> {
    return api.get<NotificationSettings>('/notification-settings', OWN);
  },

  updateSettings(patch: {
    consents?: Partial<Record<'email' | 'push', boolean>>;
    preferences?: Record<string, Record<string, boolean>>;
    birthdayReminderDays?: number[];
  }): Promise<NotificationSettings> {
    return api.patch<NotificationSettings>('/notification-settings', { json: patch, ...OWN });
  },

  registerDevice(token: string, platform: string): Promise<unknown> {
    return api.post('/device-tokens', { json: { token, platform }, ...OWN });
  },

  unregisterDevice(token: string): Promise<unknown> {
    return api.delete('/device-tokens', { json: { token }, ...OWN });
  },
};
