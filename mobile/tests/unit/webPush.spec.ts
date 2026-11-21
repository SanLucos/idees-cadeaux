import { beforeEach, describe, expect, test, vi } from 'vitest'

const { api, notificationsApi } = vi.hoisted(() => ({
  api: { get: vi.fn() },
  notificationsApi: { registerDevice: vi.fn(() => Promise.resolve()), unregisterDevice: vi.fn(() => Promise.resolve()) },
}))
vi.mock('@/services/api', () => ({ api }))
vi.mock('@/services/notifications', () => ({ notificationsApi }))

import { base64UrlToBytes, webPush, type WebPushEnv } from '@/services/webPush'

// A VAPID public key: any base64url string decodes for these tests.
const KEY = 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U'

function fakeSubscription(endpoint: string, key: Uint8Array) {
  return {
    endpoint,
    options: { applicationServerKey: key.buffer },
    toJSON: () => ({ endpoint, keys: { p256dh: 'p256', auth: 'auth' } }),
    unsubscribe: vi.fn(() => Promise.resolve(true)),
  }
}

function setup(permission: NotificationPermission, existing: ReturnType<typeof fakeSubscription> | null = null) {
  const sw = new EventTarget() as EventTarget & { ready: Promise<unknown>; getRegistration: () => Promise<unknown> }
  const pushManager = {
    getSubscription: vi.fn(() => Promise.resolve(existing)),
    subscribe: vi.fn((options: { applicationServerKey: Uint8Array }) => Promise.resolve(fakeSubscription('https://push.example/new', options.applicationServerKey))),
  }
  sw.ready = Promise.resolve({ pushManager })
  sw.getRegistration = () => Promise.resolve({ pushManager })
  const notification = { permission, requestPermission: vi.fn(() => Promise.resolve('granted' as NotificationPermission)) }
  webPush.useEnv({ serviceWorker: sw as never, notification: notification as never, hasPushManager: true, serviceWorkerEnabled: true } satisfies WebPushEnv)

  return { sw, pushManager, notification }
}

describe('Web Push (spec §11 décision 70)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    api.get.mockResolvedValue({ publicKey: KEY })
  })

  test('needs the service worker, notifications and the push manager', () => {
    webPush.useEnv({ serviceWorker: {} as never, notification: {} as never, hasPushManager: true, serviceWorkerEnabled: false })
    expect(webPush.isSupported()).toBe(false)
    webPush.useEnv({ serviceWorker: undefined, notification: {} as never, hasPushManager: true, serviceWorkerEnabled: true })
    expect(webPush.isSupported()).toBe(false)
    setup('default')
    expect(webPush.isSupported()).toBe(true)
  })

  test('nothing is subscribed before the browser permission', async () => {
    const { pushManager } = setup('default')
    await webPush.register(() => undefined)
    expect(pushManager.subscribe).not.toHaveBeenCalled()
    expect(notificationsApi.registerDevice).not.toHaveBeenCalled()
  })

  test('a denied permission is not asked again', async () => {
    const { notification } = setup('denied')
    expect(await webPush.requestPermission()).toBe(false)
    expect(notification.requestPermission).not.toHaveBeenCalled()
  })

  test('subscribes with the server key and registers the subscription', async () => {
    const { pushManager } = setup('granted')
    await webPush.register(() => undefined)
    expect(pushManager.subscribe).toHaveBeenCalledWith({ userVisibleOnly: true, applicationServerKey: base64UrlToBytes(KEY) })
    expect(notificationsApi.registerDevice).toHaveBeenCalledWith('https://push.example/new', 'web', { p256dh: 'p256', auth: 'auth' })
  })

  test('keeps a subscription made with the same key, renews one made with another', async () => {
    const same = fakeSubscription('https://push.example/same', base64UrlToBytes(KEY))
    let { pushManager } = setup('granted', same)
    await webPush.register(() => undefined)
    expect(pushManager.subscribe).not.toHaveBeenCalled()
    expect(notificationsApi.registerDevice).toHaveBeenCalledWith('https://push.example/same', 'web', expect.anything())

    const stale = fakeSubscription('https://push.example/stale', new Uint8Array([1, 2, 3]))
    ;({ pushManager } = setup('granted', stale))
    await webPush.register(() => undefined)
    expect(stale.unsubscribe).toHaveBeenCalled()
    expect(pushManager.subscribe).toHaveBeenCalled()
  })

  test('the server without a key: no subscription', async () => {
    api.get.mockResolvedValue({ publicKey: '' })
    const { pushManager } = setup('granted')
    await webPush.register(() => undefined)
    expect(pushManager.subscribe).not.toHaveBeenCalled()
  })

  test('a tapped notification opens the app where it is (sw.js posts push-open)', async () => {
    const { sw } = setup('granted')
    const onOpen = vi.fn()
    await webPush.register(onOpen)
    sw.dispatchEvent(new MessageEvent('message', { data: { type: 'push-open', data: { type: 'friend_request_received' } } }))
    sw.dispatchEvent(new MessageEvent('message', { data: { type: 'other' } }))
    expect(onOpen).toHaveBeenCalledTimes(1)
    expect(onOpen).toHaveBeenCalledWith({ type: 'friend_request_received' })
  })

  test('signing out removes and drops this browser subscription', async () => {
    const existing = fakeSubscription('https://push.example/mine', base64UrlToBytes(KEY))
    setup('granted', existing)
    await webPush.unregister()
    expect(notificationsApi.unregisterDevice).toHaveBeenCalledWith('https://push.example/mine')
    expect(existing.unsubscribe).toHaveBeenCalled()
  })

  test('without a service worker, sign-out does not wait for one (Cypress blocks it)', async () => {
    const { sw } = setup('granted')
    sw.ready = new Promise(() => undefined)
    sw.getRegistration = () => Promise.resolve(undefined)
    await webPush.unregister()
    expect(notificationsApi.unregisterDevice).not.toHaveBeenCalled()
  })

  test('subscribing gives up when the service worker never becomes active', async () => {
    vi.useFakeTimers()
    try {
      const { sw } = setup('granted')
      sw.ready = new Promise(() => undefined)
      const done = webPush.register(() => undefined)
      await vi.advanceTimersByTimeAsync(10_000)
      await done
      expect(notificationsApi.registerDevice).not.toHaveBeenCalled()
    } finally {
      vi.useRealTimers()
    }
  })
})
