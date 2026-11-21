import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, test, vi } from 'vitest'

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), delete: vi.fn() }))
vi.mock('@/services/api', () => ({ api }))
const afterWrite = vi.hoisted(() => vi.fn())
vi.mock('@/stores/friends', () => ({ useFriendsStore: () => ({ afterWrite }) }))

import { useIntroductionsStore } from '@/stores/introductions'

const intro = (id: string, name: string) => ({ id, createdAt: '', user: { id: `u-${id}`, displayName: name, avatarUrl: null } })

describe('team introductions store (spec §11 décision 71)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  test('always in my own name, never on behalf of a child', async () => {
    api.get.mockResolvedValue([intro('i1', 'Bruno')])
    const store = useIntroductionsStore()

    await store.fetch()

    expect(api.get).toHaveBeenCalledWith('/introductions', { acting: false })
    expect(store.received).toHaveLength(1)
  })

  test('accepting first: gone from my list, no friend yet', async () => {
    api.post.mockResolvedValue({ status: 'accepted' })
    const store = useIntroductionsStore()
    store.received = [intro('i1', 'Bruno'), intro('i2', 'Zoé')]

    expect(await store.accept('i1')).toBe(false)

    expect(api.post).toHaveBeenCalledWith('/introductions/i1/accept', { acting: false })
    expect(store.received.map((i) => i.id)).toEqual(['i2'])
    expect(afterWrite).not.toHaveBeenCalled()
  })

  test('accepting second: friends now, the friends list is refreshed', async () => {
    api.post.mockResolvedValue({ status: 'friends' })
    const store = useIntroductionsStore()
    store.received = [intro('i1', 'Bruno')]

    expect(await store.accept('i1')).toBe(true)
    expect(afterWrite).toHaveBeenCalled()
  })

  test('ignoring removes it', async () => {
    api.post.mockResolvedValue(undefined)
    const store = useIntroductionsStore()
    store.received = [intro('i1', 'Bruno')]

    await store.ignore('i1')

    expect(api.post).toHaveBeenCalledWith('/introductions/i1/ignore', { acting: false })
    expect(store.received).toEqual([])
  })
})
