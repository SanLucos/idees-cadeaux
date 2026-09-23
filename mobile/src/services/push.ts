import { Capacitor, type PluginListenerHandle } from '@capacitor/core';
import { PushNotifications } from '@capacitor/push-notifications';
import { notificationsApi } from './notifications';

/**
 * Push on iOS/Android (spec §5.11): the OS permission is only asked
 * after our own explanation screen (consent first, then the system
 * prompt), the token is registered at sign-in and removed at sign-out.
 * In the browser there is no push: these calls do nothing.
 */
let currentToken: string | null = null;
let listeners: PluginListenerHandle[] = [];

export const push = {
  isAvailable(): boolean {
    return Capacitor.isNativePlatform();
  },

  /** Asks the OS; true when granted (and the device is then registered). */
  async requestPermissionAndRegister(onOpen: (data: Record<string, string>) => void): Promise<boolean> {
    if (!this.isAvailable()) return false;

    let status = await PushNotifications.checkPermissions();
    if ('prompt' === status.receive || 'prompt-with-rationale' === status.receive) {
      status = await PushNotifications.requestPermissions();
    }
    if ('granted' !== status.receive) return false;

    await this.register(onOpen);

    return true;
  },

  /** At sign-in or app start, when permission was already granted. */
  async register(onOpen: (data: Record<string, string>) => void): Promise<void> {
    if (!this.isAvailable() || 'granted' !== (await PushNotifications.checkPermissions()).receive) return;

    await Promise.all(listeners.map((l) => l.remove()));
    listeners = [
      await PushNotifications.addListener('registration', async ({ value }) => {
        currentToken = value;
        await notificationsApi.registerDevice(value, Capacitor.getPlatform());
      }),
      await PushNotifications.addListener('pushNotificationActionPerformed', ({ notification }) => {
        onOpen((notification.data ?? {}) as Record<string, string>);
      }),
    ];
    await PushNotifications.register();
  },

  /** At sign-out: this device must stop receiving the account's pushes. */
  async unregister(): Promise<void> {
    if (!this.isAvailable()) return;
    if (currentToken) {
      await notificationsApi.unregisterDevice(currentToken).catch(() => undefined);
      currentToken = null;
    }
    await Promise.all(listeners.map((l) => l.remove()));
    listeners = [];
  },
};
