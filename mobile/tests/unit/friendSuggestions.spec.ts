import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, test, vi } from 'vitest'
import { joinNames } from '@/utils/names'

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), delete: vi.fn() }))
vi.mock('@/services/api', () => ({ api }))
const afterWrite = vi.hoisted(() => vi.fn())
vi.mock('@/stores/friends', () => ({ useFriendsStore: () => ({ afterWrite }) }))

import { useFriendSuggestionsStore } from '@/stores/friendSuggestions'

const person = (id: string, displayName: string) => ({ id, displayName, avatarUrl: null })

describe('friend suggestions store (spec §11 décision 65)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  test('always in my own name, never on behalf of a child', async () => {
    api.get.mockResolvedValue([])
    api.post.mockResolvedValue([])
    const store = useFriendSuggestionsStore()

    await store.fetchReceived()
    await store.fetchSent()
    await store.suggest('b', ['a', 'c'])

    expect(api.get).toHaveBeenCalledWith('/friend-suggestions', { acting: false })
    expect(api.get).toHaveBeenCalledWith('/friend-suggestions?sent=1', { acting: false })
    expect(api.post).toHaveBeenCalledWith('/friend-suggestions', { json: { suggested: 'b', recipients: ['a', 'c'] }, acting: false })
  })

  test('sending a request or ignoring removes the suggestion; sending refreshes the friends', async () => {
    const store = useFriendSuggestionsStore()
    store.received = [
      { id: 's1', createdAt: '', suggested: person('b', 'Bruno'), suggestedBy: [{ id: 'l', displayName: 'Luc' }] },
      { id: 's2', createdAt: '', suggested: person('z', 'Zoé'), suggestedBy: [{ id: 'l', displayName: 'Luc' }] },
    ]

    await store.sendRequest('s1')
    expect(api.post).toHaveBeenCalledWith('/friend-suggestions/s1/send-request', { acting: false })
    expect(afterWrite).toHaveBeenCalled()
    await store.dismiss('s2')
    expect(api.post).toHaveBeenCalledWith('/friend-suggestions/s2/dismiss', { acting: false })
    expect(store.received).toEqual([])
  })

  test('my introductions of one friend, and cancelling one', async () => {
    const store = useFriendSuggestionsStore()
    store.sent = [
      { id: 's1', status: 'pending', createdAt: '', recipient: person('a', 'Anna'), suggested: person('b', 'Bruno') },
      { id: 's2', status: 'pending', createdAt: '', recipient: person('c', 'Chloé'), suggested: person('z', 'Zoé') },
    ]

    expect(store.sentAbout('b').map((s) => s.recipient.displayName)).toEqual(['Anna'])
    await store.cancel('s1')
    expect(api.delete).toHaveBeenCalledWith('/friend-suggestions/s1', { acting: false })
    expect(store.sentAbout('b')).toEqual([])
  })
})

describe('joinNames', () => {
  test('lists the people who suggested someone, in the user\'s language', () => {
    expect(joinNames(['Luc'], 'fr')).toBe('Luc')
    expect(joinNames(['Luc', 'Chloé'], 'fr')).toBe('Luc et Chloé')
    expect(joinNames(['Luc', null, 'Chloé', 'Anna'], 'en')).toBe('Luc, Chloé, and Anna')
  })
})
