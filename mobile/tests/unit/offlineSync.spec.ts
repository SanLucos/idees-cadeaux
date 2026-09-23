import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, test, vi } from 'vitest'
import { useLocalDb } from '@/offline/db'
import { MemoryStore } from '@/offline/memoryStore'
import { interactionMutations } from '@/offline/mutations'
import { localHashes, useSync } from '@/offline/sync'
import { repo } from '@/offline/repo'
import { useAuthStore } from '@/stores/auth'
import type { Idea } from '@/types/idea'

vi.mock('@aparajita/capacitor-secure-storage', () => ({
  SecureStorage: { getItem: vi.fn(async () => 'token'), setItem: vi.fn(), removeItem: vi.fn() },
}))

type Handler = (method: string, path: string, init: RequestInit) => { status: number; body?: unknown }
let handler: Handler
const calls: { method: string; path: string; headers: Record<string, string>; body: unknown }[] = []

function respond(status: number, body: unknown = {}): Response {
  return { ok: status < 400, status, json: async () => body } as Response
}

const idea = (over: Partial<Idea> = {}): Idea =>
  ({ id: 'i1', ownerId: 'camille', title: 'Casque', view: 'friend', status: 'active', visibility: 'published', isSuggestion: false,
    priceAmount: '100.00', priceCurrency: 'EUR', createdAt: '2026-09-20T10:00:00Z', reservation: null, contribution: null,
    reactions: { count: 0, likedByMe: false }, commentCount: 0, _version: '2026-09-20T10:00:00Z', ...over }) as unknown as Idea

describe('offline engine', () => {
  beforeEach(async () => {
    setActivePinia(createPinia())
    calls.length = 0
    handler = () => ({ status: 200, body: {} })
    vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit) => {
      const path = url.replace(/^.*\/api/, '')
      calls.push({ method: init.method!, path, headers: init.headers as Record<string, string>, body: init.body ? JSON.parse(String(init.body)) : null })
      const { status, body } = handler(init.method!, path, init)

      return respond(status, body)
    }))
    await useLocalDb().open(new MemoryStore())
    useAuthStore().user = { id: 'me', displayName: 'Luc', avatarUrl: null } as never
  })

  test('a write shows at once, is queued, and replays with the same Idempotency-Key after a network failure', async () => {
    await useLocalDb().put('idea', [idea()])
    const sync = useSync()

    await interactionMutations.reserve('i1')
    expect(repo.idea('i1')!.reservation!.isMine).toBe(true)
    expect(sync.pending).toHaveLength(1)

    // Offline: fetch throws; nothing is lost, it waits.
    handler = () => { throw new TypeError('Failed to fetch') }
    expect(await sync.flush()).toBe(false)
    expect(sync.pending).toHaveLength(1)
    const key = sync.pending[0].idempotencyKey

    // Back online: the same key is sent (the server replays if it already got it).
    handler = () => ({ status: 201, body: {} })
    useLocalDb().outbox[0].nextAttemptAt = 0
    expect(await sync.flush()).toBe(true)
    const sent = calls.filter((c) => '/reservations' === c.path)
    expect(sent.at(-1)!.headers['Idempotency-Key']).toBe(key)
    expect(sync.pending).toHaveLength(0)
  })

  test('a refusal is kept with its reason (« déjà réservé par Hugo ») and never retried blindly', async () => {
    await useLocalDb().put('idea', [idea()])
    const sync = useSync()
    handler = (method, path) => ('/reservations' === path ? { status: 409, body: { code: 'reservation.already_reserved', reservedBy: 'Hugo' } } : { status: 200 })

    await interactionMutations.reserve('i1')
    await sync.flush()

    expect(sync.failed).toHaveLength(1)
    expect(sync.failed[0].error).toMatchObject({ code: 'reservation.already_reserved', extra: { reservedBy: 'Hugo' } })
    expect(sync.lastRefusal?.error?.code).toBe('reservation.already_reserved')
    expect(sync.state).toBe('error')

    await sync.discard(sync.failed[0].seq)
    expect(sync.failed).toHaveLength(0)
  })

  test('pull applies the delta, purges what left the inventory and fetches what is missing', async () => {
    const db = useLocalDb()
    await db.put('idea', [idea({ id: 'kept' }), idea({ id: 'gone', title: 'Made private' })])
    handler = (method, path) => {
      if ('/sync' === path) {
        return { status: 200, body: {
          cursor: '2026-09-24T08:00:00Z',
          changes: { idea: [idea({ id: 'kept', title: 'Renamed', _version: '2026-09-24T07:59:00Z' })] },
          hashes: {},
          manifests: { idea: [['kept', '2026-09-24T07:59:00Z'], ['new', '2026-09-23T00:00:00Z']] },
        } }
      }
      if ('/sync/fetch' === path) return { status: 200, body: { documents: [idea({ id: 'new', title: 'New to me', _version: '2026-09-23T00:00:00Z' })] } }

      return { status: 200 }
    }

    await useSync().pull()

    expect(repo.idea('kept')!.title).toBe('Renamed')
    expect(repo.idea('gone')).toBeNull()
    expect(repo.idea('new')!.title).toBe('New to me')
    expect(db.meta['sync.cursor']).toBe('2026-09-24T08:00:00Z')
    expect(calls.find((c) => '/sync/fetch' === c.path)!.body).toEqual({ type: 'idea', ids: ['new'] })
  })

  test('a document with a pending write is neither overwritten nor purged', async () => {
    const db = useLocalDb()
    await db.put('idea', [idea()])
    handler = (method, path) => ('/reservations' === path ? (() => { throw new TypeError('offline') })() : { status: 200 })
    await interactionMutations.reserve('i1')
    await useSync().flush()

    handler = () => ({ status: 200, body: { cursor: 'c', changes: { idea: [idea({ title: 'server' })] }, hashes: {}, manifests: { idea: [] } } })
    await useSync().pull()

    expect(repo.idea('i1')!.reservation!.isMine).toBe(true)
  })

  test('hashes match the server format: SHA-256 of sorted id:version lines', async () => {
    await useLocalDb().put('occasion', [{ id: 'christmas', _version: 'v2' }, { id: 'birthday', _version: 'v1' }])
    const expected = Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode('birthday:v1\nchristmas:v2'))), (b) => b.toString(16).padStart(2, '0')).join('')

    expect((await localHashes()).occasion).toBe(expected)
  })
})
