import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, test, vi } from 'vitest'
import { api, setActingAs } from '@/services/api'
import { interactionsApi } from '@/services/interactions'
import { useActiveProfileStore } from '@/stores/activeProfile'

vi.mock('@aparajita/capacitor-secure-storage', () => ({
  SecureStorage: {
    getItem: vi.fn(async () => 'access-token'),
    setItem: vi.fn(),
    removeItem: vi.fn(),
  },
}))

const CHILD = '0190a1b2-0000-7000-8000-0000000c0001'
let fetchMock: ReturnType<typeof vi.fn>

const headersOfLastCall = () => (fetchMock.mock.calls.at(-1)![1] as RequestInit).headers as Record<string, string>

describe('X-Acting-As', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    setActingAs(null)
    fetchMock = vi.fn(async () => ({ ok: true, status: 200, json: async () => ({}) }) as Response)
    vi.stubGlobal('fetch', fetchMock)
  })

  test('is sent while a child profile is active, and only then', async () => {
    await api.get('/users/me/ideas')
    expect(headersOfLastCall()['X-Acting-As']).toBeUndefined()

    useActiveProfileStore().switchTo(CHILD)
    await api.get('/users/me/ideas')
    expect(headersOfLastCall()['X-Acting-As']).toBe(CHILD)

    useActiveProfileStore().switchTo(null)
    await api.get('/users/me/ideas')
    expect(headersOfLastCall()['X-Acting-As']).toBeUndefined()
  })

  test('is never sent for interactions: the manager acts in their own name', async () => {
    useActiveProfileStore().switchTo(CHILD)

    await interactionsApi.reserve('idea-1')
    expect(headersOfLastCall()['X-Acting-As']).toBeUndefined()
    await interactionsApi.pledge('contribution-1', '10')
    expect(headersOfLastCall()['X-Acting-As']).toBeUndefined()
    await interactionsApi.like('idea-1')
    expect(headersOfLastCall()['X-Acting-As']).toBeUndefined()
  })

  test('is dropped on reset (logout)', async () => {
    const store = useActiveProfileStore()
    store.switchTo(CHILD)
    store.reset()

    await api.get('/users/me/ideas')
    expect(headersOfLastCall()['X-Acting-As']).toBeUndefined()
  })
})
