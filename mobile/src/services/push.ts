import { Capacitor, type PluginListenerHandle } from '@capacitor/core';
import { PushNotifications } from '@capacitor/push-notifications';
import { notificationsApi } from './notifications';
import { webPush } from './webPush';

/**
 * Push (spec §5.11): the OS permission is only asked after our own
 * explanation screen (consent first, then the system prompt), the token
 * is registered at sign-in and removed at sign-out. iOS/Android: FCM via
 * the Capacitor plugin. In the browser: Web Push (spec §11 décision 70),
 * where the browser supports it — the app installed from Chrome on
 * Android first; elsewhere these calls do nothing.
 */
export type PushEnableResult = 'granted' | 'denied' | 'failed';

let currentToken: string | null = null;
let listeners: PluginListenerHandle[] = [];

export const push = {
  isAvailable(): boolean {
    return Capacitor.isNativePlatform() || webPush.isSupported();
  },

  /** In the browser rather than the native app. */
  isWebPush(): boolean {
    return !Capacitor.isNativePlatform() && webPush.isSupported();
  },

  /**
   * Whether this device would show pushes now: the consent recorded on
   * the server is not enough (another device, a browser that never asked,
   * or one whose subscription failed). True where push is not available:
   * the consent alone counts.
   */
  async isActiveHere(): Promise<boolean> {
    if (!this.isAvailable()) return true;
    if (this.isWebPush()) return webPush.isSubscribed();

    return 'granted' === (await PushNotifications.checkPermissions()).receive;
  },

  /** Whether this device can be offered push: the web needs the server's VAPID key. */
  async canBeTurnedOn(): Promise<boolean> {
    return this.isWebPush() ? webPush.isConfigured() : true;
  },

  /**
   * Asks the OS, then registers the device. `denied`: the user (or the
   * browser) said no; `failed`: allowed, but the device could not be
   * subscribed — never reported as working.
   */
  async requestPermissionAndRegister(onOpen: (data: Record<string, string>) => void): Promise<PushEnableResult> {
    if (!this.isAvailable()) return 'failed';
    if (this.isWebPush()) {
      if (!(await webPush.requestPermission())) return 'denied';

      return (await webPush.register(onOpen).catch(() => false)) ? 'granted' : 'failed';
    }

    let status = await PushNotifications.checkPermissions();
    if ('prompt' === status.receive || 'prompt-with-rationale' === status.receive) {
      status = await PushNotifications.requestPermissions();
    }
    if ('granted' !== status.receive) return 'denied';

    await this.register(onOpen);

    return 'granted';
  },

  /** At sign-in or app start, when permission was already granted. */
  async register(onOpen: (data: Record<string, string>) => void): Promise<void> {
    if (this.isWebPush()) {
      await webPush.register(onOpen).catch(() => false);

      return;
    }
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
    if (this.isWebPush()) return webPush.unregister();
    if (!this.isAvailable()) return;
    if (currentToken) {
      await notificationsApi.unregisterDevice(currentToken).catch(() => undefined);
      currentToken = null;
    }
    await Promise.all(listeners.map((l) => l.remove()));
    listeners = [];
  },
};
