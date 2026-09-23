import { api } from './api';
import type { NotificationPage, NotificationSettings } from '../types/notification';

/** Always the adult's own centre and settings, never a child's (acting: false). */
const OWN = { acting: false } as const;

export const notificationsApi = {
  list(page = 1): Promise<NotificationPage> {
    return api.get<NotificationPage>(`/notifications?page=${page}`, OWN);
  },

  async unreadCount(): Promise<number> {
    return (await api.get<{ unreadCount: number }>('/notifications/unread-count', OWN)).unreadCount;
  },

  markRead(id: string): Promise<unknown> {
    return api.post(`/notifications/${id}/read`, OWN);
  },

  markAllRead(): Promise<unknown> {
    return api.post('/notifications/read-all', OWN);
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
