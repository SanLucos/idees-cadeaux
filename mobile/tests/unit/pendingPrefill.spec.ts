import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, test, vi } from 'vitest'
import { useLocalDb } from '@/offline/db'
import { MemoryStore } from '@/offline/memoryStore'
import { addPendingPrefill, completePendingPrefills } from '@/offline/pendingPrefill'
import { useSync } from '@/offline/sync'
import { repo } from '@/offline/repo'
import { useAuthStore } from '@/stores/auth'
import type { Idea } from '@/types/idea'

vi.mock('@aparajita/capacitor-secure-storage', () => ({
  SecureStorage: { getItem: vi.fn(async () => 'token'), setItem: vi.fn(), removeItem: vi.fn() },
}))

const PIXEL = 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q=='

type Handler = (path: string) => { status: number; body?: unknown }
let handler: Handler
const previews: string[] = []

const idea = (over: Partial<Idea> = {}): Idea =>
  ({ id: 'i1', ownerId: 'me', authorId: 'me', title: 'shop.example', url: 'https://shop.example/carnet', view: 'owner', status: 'active',
    visibility: 'private', isSuggestion: false, priceAmount: null, priceCurrency: 'EUR', imageUrl: null, thumbnailUrl: null,
    createdAt: '2026-09-20T10:00:00Z', _version: '2026-09-20T10:00:00Z', ...over }) as unknown as Idea

describe('pre-fill completed back online (spec §5.6)', () => {
  beforeEach(async () => {
    setActivePinia(createPinia())
    previews.length = 0
    handler = () => ({ status: 200, body: { title: 'Carnet de voyage', priceAmount: '38.00', priceCurrency: 'EUR', imageDataUrl: PIXEL } })
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
      if (url.startsWith('data:')) return { blob: async () => new Blob(['x'], { type: 'image/jpeg' }) } as Response
      const path = url.replace(/^.*\/api/, '')
      if ('/link-previews' === path) {
        previews.push(JSON.parse(String(init!.body)).url)
        const { status, body } = handler(path)

        return { ok: status < 400, status, json: async () => body } as Response
      }

      throw new TypeError('Failed to fetch')
    }))
    await useLocalDb().open(new MemoryStore())
    useAuthStore().user = { id: 'me', displayName: 'Luc', avatarUrl: null } as never
  })

  test('fills only what was left as is, then forgets the entry', async () => {
    await useLocalDb().put('idea', [idea({ note: 'Bleu' } as Partial<Idea>)])
    await addPendingPrefill({ ideaId: 'i1', url: 'https://shop.example/carnet', fallbackTitle: 'shop.example', actingAs: null })

    await completePendingPrefills()

    const updated = repo.idea('i1')!
    expect(updated.title).toBe('Carnet de voyage')
    expect(updated.priceAmount).toBe('38.00')
    expect(updated.imageUrl).toMatch(/^data:/)
    expect(useSync().pending.map((op) => op.path)).toEqual(['/ideas/i1', '/ideas/i1/image'])

    await completePendingPrefills()
    expect(previews).toHaveLength(1)
  })

  test("never overwrites what the user typed", async () => {
    await useLocalDb().put('idea', [idea({ title: 'Mon carnet', priceAmount: '20.00' })])
    await addPendingPrefill({ ideaId: 'i1', url: 'https://shop.example/carnet', fallbackTitle: 'shop.example', actingAs: null })

    await completePendingPrefills()

    expect(repo.idea('i1')!.title).toBe('Mon carnet')
    expect(repo.idea('i1')!.priceAmount).toBe('20.00')
  })

  test('still offline: kept for next time', async () => {
    await useLocalDb().put('idea', [idea()])
    await addPendingPrefill({ ideaId: 'i1', url: 'https://shop.example/carnet', fallbackTitle: 'shop.example', actingAs: null })
    handler = () => { throw new TypeError('Failed to fetch') }

    await completePendingPrefills()
    expect(repo.idea('i1')!.title).toBe('shop.example')

    handler = () => ({ status: 200, body: { title: 'Carnet', priceAmount: null, priceCurrency: null, imageDataUrl: null } })
    await completePendingPrefills()
    expect(repo.idea('i1')!.title).toBe('Carnet')
  })

  test('an unreadable page, a deleted idea or a changed link just drop the entry', async () => {
    await useLocalDb().put('idea', [idea(), idea({ id: 'i2', url: 'https://other.example/' })])
    await addPendingPrefill({ ideaId: 'i1', url: 'https://shop.example/carnet', fallbackTitle: 'shop.example', actingAs: null })
    await addPendingPrefill({ ideaId: 'i2', url: 'https://shop.example/carnet', fallbackTitle: 'shop.example', actingAs: null })
    await addPendingPrefill({ ideaId: 'gone', url: 'https://shop.example/carnet', fallbackTitle: 'shop.example', actingAs: null })
    handler = () => ({ status: 422, body: { code: 'link_preview.unavailable' } })

    await completePendingPrefills()

    expect(previews).toEqual(['https://shop.example/carnet'])
    expect(useLocalDb().meta['prefill.pending']).toBeUndefined()
    expect(repo.idea('i1')!.title).toBe('shop.example')
  })
})
