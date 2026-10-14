import { describe, expect, test, vi } from 'vitest'

vi.mock('@/utils/platform', () => ({ isWeb: () => false }))
vi.mock('@aparajita/capacitor-secure-storage', () => ({
  SecureStorage: { getItem: vi.fn(async () => null), setItem: vi.fn(), removeItem: vi.fn() },
}))

describe('native app API calls', () => {
  test('never ask for cookies: the WebView would reject the response (CORS)', async () => {
    const fetchMock = vi.fn(async () => new Response('{}', { status: 200, headers: { 'Content-Type': 'application/json' } }))
    vi.stubGlobal('fetch', fetchMock)
    const { api } = await import('@/services/api')

    await api.get('/health')

    expect((fetchMock.mock.calls[0] as unknown as [string, RequestInit])[1].credentials).toBe('omit')
  })
})
