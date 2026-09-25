import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest'
import { api, ApiError, setOnDeletionScheduled } from '@/services/api'
import { reportError, resetErrorReporter, setErrorRoute } from '@/services/errorReporter'

vi.mock('@aparajita/capacitor-secure-storage', () => ({
  SecureStorage: { getItem: vi.fn(async () => 'access-token'), setItem: vi.fn(), removeItem: vi.fn() },
}))

let fetchMock: ReturnType<typeof vi.fn>
const respond = (status: number, body: unknown) => ({ ok: status < 400, status, json: async () => body }) as Response

describe('API requests', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    fetchMock = vi.fn(async () => respond(200, {}))
    vi.stubGlobal('fetch', fetchMock)
  })
  afterEach(() => setOnDeletionScheduled(null))

  test('carry a fresh X-Request-Id each (spec §9)', async () => {
    await api.get('/users/me')
    await api.get('/users/me')
    const ids = fetchMock.mock.calls.map((call) => ((call[1] as RequestInit).headers as Record<string, string>)['X-Request-Id'])
    expect(ids[0]).toMatch(/^app-/)
    expect(ids[1]).not.toBe(ids[0])
  })

  test('an account being deleted sends the app to its grace-period screen (spec §5.13)', async () => {
    const onScheduled = vi.fn()
    setOnDeletionScheduled(onScheduled)
    fetchMock.mockResolvedValueOnce(respond(403, { code: 'account.deletion_scheduled', deletionScheduledAt: '2026-10-09T10:00:00+00:00' }))

    await expect(api.get('/users/me/ideas')).rejects.toBeInstanceOf(ApiError)
    expect(onScheduled).toHaveBeenCalledOnce()

    fetchMock.mockResolvedValueOnce(respond(403, { code: 'acting_as.not_allowed' }))
    await expect(api.get('/users/me/ideas')).rejects.toBeInstanceOf(ApiError)
    expect(onScheduled).toHaveBeenCalledOnce()
  })
})

describe('error reports', () => {
  beforeEach(() => {
    resetErrorReporter()
    fetchMock = vi.fn(async () => respond(204, {}))
    vi.stubGlobal('fetch', fetchMock)
  })

  test('send the error and the route name, the same error only once', () => {
    setErrorRoute('MyList')
    reportError(new TypeError('x is undefined'))
    reportError(new TypeError('x is undefined'))

    expect(fetchMock).toHaveBeenCalledOnce()
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toMatch(/\/client-errors$/)
    const body = JSON.parse(String(init.body))
    expect(body).toMatchObject({ message: 'TypeError: x is undefined', route: 'MyList', kind: 'error' })
  })

  test('stop after a few per session (a crash loop must not flood)', () => {
    for (let i = 0; i < 50; i++) reportError(new Error(`boom ${i}`))
    expect(fetchMock).toHaveBeenCalledTimes(20)
  })
})
