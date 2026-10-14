import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, test, vi } from 'vitest'
import { api } from '@/services/api'
import { tokenStorage } from '@/services/tokenStorage'
import { useLocalDb } from '@/offline/db'
import { MemoryStore } from '@/offline/memoryStore'
import { interactionMutations } from '@/offline/mutations'
import { WebOfflineError } from '@/offline/onlineOnly'
import { useSync } from '@/offline/sync'
import { useAuthStore } from '@/stores/auth'
import type { Idea } from '@/types/idea'

/**
 * The web app (spec §11 décision 56) — jsdom runs as a browser: session in
 * an httpOnly cookie (never in the browser's storage), online only, writes
 * sent at once.
 */
const secureStorage = vi.hoisted(() => ({ getItem: vi.fn(), setItem: vi.fn(), removeItem: vi.fn() }))
vi.mock('@aparajita/capacitor-secure-storage', () => ({ SecureStorage: secureStorage }))

type Call = { url: string; init: RequestInit }
let calls: Call[]
let respond: (url: string, init: RequestInit) => { status: number; body?: unknown }

const idea = { id: 'i1', ownerId: 'camille', title: 'Casque', view: 'friend', status: 'active', visibility: 'published', isSuggestion: false,
  priceAmount: '100.00', priceCurrency: 'EUR', createdAt: '2026-09-20T10:00:00Z', reservation: null, contribution: null,
  reactions: { count: 0, likedByMe: false }, commentCount: 0 } as unknown as Idea

describe('web app', () => {
  beforeEach(async () => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    calls = []
    respond = () => ({ status: 200, body: {} })
    vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit) => {
      calls.push({ url, init })
      const { status, body } = respond(url, init)

      return { ok: status < 400, status, json: async () => body ?? {} } as Response
    }))
    await tokenStorage.clear()
    await useLocalDb().open(new MemoryStore())
  })

  test('tokens never reach the browser storage; the refresh uses the cookie', async () => {
    await tokenStorage.setTokens('access-1')
    expect(await tokenStorage.getAccessToken()).toBe('access-1')
    expect(await tokenStorage.getRefreshToken()).toBeNull()
    expect(secureStorage.setItem).not.toHaveBeenCalled()
    expect(localStorage.length).toBe(0)

    respond = (url) => (url.endsWith('/auth/refresh') ? { status: 200, body: { token: 'access-2' } } : { status: 200 })
    expect(await api.resumeWebSession()).toBe(true)
    const refresh = calls.find((c) => c.url.endsWith('/auth/refresh'))!
    expect(refresh.init.body).toBeUndefined()
    expect((refresh.init.headers as Record<string, string>)['X-Client']).toBe('web')
    expect(refresh.init.credentials).toBe('include')
    expect(await tokenStorage.getAccessToken()).toBe('access-2')
  })

  test('without a session cookie, the app starts signed out', async () => {
    respond = () => ({ status: 401, body: { code: 'auth.refresh_token_invalid' } })
    const auth = useAuthStore()
    await auth.bootstrap()
    expect(auth.isAuthenticated).toBe(false)
  })

  test('every request says it comes from the web app', async () => {
    await api.get('/users/me')
    expect((calls[0].init.headers as Record<string, string>)['X-Client']).toBe('web')
  })

  test('a write is sent at once and the action waits for the server', async () => {
    useAuthStore().user = { id: 'me', displayName: 'Luc', avatarUrl: null } as never
    await useLocalDb().put('idea', [idea])
    respond = (url) => (url.endsWith('/reservations') ? { status: 201 } : { status: 200, body: { cursor: 'c', changes: {}, hashes: {}, manifests: {} } })

    await interactionMutations.reserve('i1')

    expect(calls.some((c) => c.url.endsWith('/reservations'))).toBe(true)
    expect(useSync().pending).toHaveLength(0)
  })

  test('offline, a write is refused before anything changes', async () => {
    useAuthStore().user = { id: 'me', displayName: 'Luc', avatarUrl: null } as never
    await useLocalDb().put('idea', [idea])
    useSync().online = false

    await expect(interactionMutations.reserve('i1')).rejects.toBeInstanceOf(WebOfflineError)
    expect(useLocalDb().get<Idea>('idea', 'i1')!.reservation).toBeNull()
    expect(calls).toHaveLength(0)
  })
})
