import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, test, vi } from 'vitest'
import { useAuthStore } from '@/stores/auth'

vi.mock('@aparajita/capacitor-secure-storage', () => {
  const store = new Map<string, string>()
  return {
    SecureStorage: {
      getItem: vi.fn(async (key: string) => store.get(key) ?? null),
      setItem: vi.fn(async (key: string, value: string) => {
        store.set(key, value)
      }),
      removeItem: vi.fn(async (key: string) => {
        store.delete(key)
      }),
    },
  }
})

function jsonResponse(body: unknown, status = 200): Response {
  return {
    ok: status < 400,
    status,
    json: async () => body,
  } as Response
}

describe('auth store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.restoreAllMocks()
  })

  test('login stores tokens and fetches the profile', async () => {
    const fetchMock = vi.fn(async (url: string) => {
      if (url.endsWith('/auth/login')) {
        return jsonResponse({
          token: 'access-token',
          refresh_token: 'refresh-token',
          user: { id: '1', email: 'a@example.com', displayName: null, isOnboarded: false, locale: 'fr' },
        })
      }
      if (url.endsWith('/users/me')) {
        return jsonResponse({
          id: '1',
          email: 'a@example.com',
          displayName: null,
          avatarUrl: null,
          birthDay: null,
          birthMonth: null,
          birthYear: null,
          locale: 'fr',
          isOnboarded: false,
          emailVerified: true,
        })
      }
      throw new Error(`unexpected request: ${url}`)
    })
    vi.stubGlobal('fetch', fetchMock)

    const auth = useAuthStore()
    await auth.login('a@example.com', 'correcthorsebattery')

    expect(auth.isAuthenticated).toBe(true)
    expect(auth.user?.email).toBe('a@example.com')
  })

  test('a failed login leaves the store unauthenticated', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => jsonResponse({ code: 'auth.invalid_credentials', detail: 'Invalid credentials.' }, 401)),
    )

    const auth = useAuthStore()
    await expect(auth.login('a@example.com', 'wrong')).rejects.toThrow()
    expect(auth.isAuthenticated).toBe(false)
  })

  test('logout clears the session even if the server call fails', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({}, 500)))

    const auth = useAuthStore()
    auth.user = {
      id: '1',
      email: 'a@example.com',
      displayName: 'A',
      avatarUrl: null,
      birthDay: null,
      birthMonth: null,
      birthYear: null,
      locale: 'fr',
      isOnboarded: true,
      emailVerified: true,
    }

    await auth.logout()

    expect(auth.isAuthenticated).toBe(false)
  })
})
