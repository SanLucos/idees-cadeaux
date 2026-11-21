import { api } from './api';
import { notificationsApi } from './notifications';

/**
 * Web Push (spec §11 décision 70): the web app installed from Chrome (on
 * Android first) receives notifications like the native app. The browser
 * is subscribed with the server's VAPID public key, the subscription
 * (endpoint + keys) is registered as a `web` device token, and sw.js
 * shows what arrives. Needs the service worker, hence production builds
 * only (main.ts registers it there).
 */

/** What the browser offers; injected by the tests. */
export interface WebPushEnv {
  serviceWorker?: Pick<ServiceWorkerContainer, 'ready' | 'getRegistration' | 'addEventListener' | 'removeEventListener'>;
  notification?: Pick<typeof Notification, 'permission' | 'requestPermission'>;
  hasPushManager: boolean;
  serviceWorkerEnabled: boolean;
}

function browserEnv(): WebPushEnv {
  return {
    serviceWorker: 'serviceWorker' in navigator ? navigator.serviceWorker : undefined,
    notification: 'Notification' in window ? Notification : undefined,
    hasPushManager: 'PushManager' in window,
    serviceWorkerEnabled: import.meta.env.PROD,
  };
}

/** How long subscribing waits for the service worker to be active. */
const READY_TIMEOUT_MS = 10_000;

let env: WebPushEnv | null = null;
let publicKey: Promise<string> | null = null;
let openListener: ((event: MessageEvent) => void) | null = null;

function current(): WebPushEnv {
  return (env ??= browserEnv());
}

/** The server's key; empty while Web Push is not configured there. */
function serverKey(): Promise<string> {
  publicKey ??= api
    .get<{ publicKey: string }>('/web-push/public-key', { acting: false })
    .then(({ publicKey }) => publicKey)
    .catch(() => {
      publicKey = null;

      return '';
    });

  return publicKey;
}

export function base64UrlToBytes(value: string): Uint8Array<ArrayBuffer> {
  const base64 = (value + '='.repeat((4 - (value.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
  const binary = atob(base64);
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);

  return bytes;
}

function sameKey(current: ArrayBuffer | null | undefined, expected: Uint8Array): boolean {
  if (!current) return false;
  const bytes = new Uint8Array(current);

  return bytes.length === expected.length && bytes.every((byte, i) => byte === expected[i]);
}

/**
 * The service worker's registration. Never waits on `ready` alone: it
 * never resolves when the worker could not be registered (blocked, failed
 * install, Cypress), and sign-out must not hang on it.
 */
async function registration(wait: boolean): Promise<ServiceWorkerRegistration | undefined> {
  const sw = current().serviceWorker;
  if (!sw) return undefined;
  if (!wait) return sw.getRegistration();

  let timer: ReturnType<typeof setTimeout> | undefined;
  const timeout = new Promise<undefined>((resolve) => {
    timer = setTimeout(() => resolve(undefined), READY_TIMEOUT_MS);
  });

  return Promise.race([sw.ready, timeout]).finally(() => clearTimeout(timer));
}

async function subscription(create: boolean): Promise<PushSubscription | null> {
  const worker = await registration(create);
  if (!worker) return null;
  const existing = await worker.pushManager.getSubscription();
  if (!create) return existing;

  const key = await serverKey();
  if (!key) return null;
  const applicationServerKey = base64UrlToBytes(key);
  // Subscribed with an older key pair: the push services would refuse ours.
  if (existing && sameKey(existing.options.applicationServerKey, applicationServerKey)) return existing;
  await existing?.unsubscribe();

  return worker.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey });
}

export const webPush = {
  /** Test hook: a fake browser (null: the real one again). */
  useEnv(fake: WebPushEnv | null): void {
    env = fake;
    publicKey = null;
  },

  isSupported(): boolean {
    const { serviceWorker, notification, hasPushManager, serviceWorkerEnabled } = current();

    return serviceWorkerEnabled && !!serviceWorker && !!notification && hasPushManager;
  },

  isGranted(): boolean {
    return 'granted' === current().notification?.permission;
  },

  /** The browser's own prompt, after our explanation (spec §5.11). */
  async requestPermission(): Promise<boolean> {
    const notification = current().notification;
    if (!notification || 'denied' === notification.permission) return false;

    return 'granted' === (await notification.requestPermission());
  },

  /** Subscribes this browser (or renews its subscription) and registers it. */
  async register(onOpen: (data: Record<string, string>) => void): Promise<void> {
    if (!this.isSupported() || !this.isGranted()) return;

    const sw = current().serviceWorker!;
    if (openListener) sw.removeEventListener('message', openListener as EventListener);
    openListener = (event: MessageEvent) => {
      if ('push-open' === event.data?.type) onOpen((event.data.data ?? {}) as Record<string, string>);
    };
    sw.addEventListener('message', openListener as EventListener);

    const sub = await subscription(true);
    if (!sub) return;
    const { keys } = sub.toJSON();
    if (!keys?.p256dh || !keys.auth) return;
    await notificationsApi.registerDevice(sub.endpoint, 'web', { p256dh: keys.p256dh, auth: keys.auth });
  },

  /** At sign-out or when push is turned off: this browser stops receiving. */
  async unregister(): Promise<void> {
    if (!this.isSupported()) return;
    const sub = await subscription(false).catch(() => null);
    if (sub) {
      await notificationsApi.unregisterDevice(sub.endpoint).catch(() => undefined);
      await sub.unsubscribe().catch(() => undefined);
    }
    if (openListener) {
      current().serviceWorker?.removeEventListener('message', openListener as EventListener);
      openListener = null;
    }
  },
};
