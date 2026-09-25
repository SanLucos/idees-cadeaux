import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest'
import { parseShareLinkToken } from '@/utils/shareLinkToken'
import { PENDING_INVITATION_TTL_MS, usePendingInvitationStore } from '@/stores/pendingInvitation'

const TOKEN = 'k7Q2mV9xPq3LwT8r_-AbCd'

describe('parseShareLinkToken', () => {
  test('reads the token from the web link, the app scheme or on its own', () => {
    expect(parseShareLinkToken(`https://example.org/u/${TOKEN}`)).toBe(TOKEN)
    expect(parseShareLinkToken(`  https://example.org/u/${TOKEN}?utm=x  `)).toBe(TOKEN)
    expect(parseShareLinkToken(`com.example.app://u/${TOKEN}`)).toBe(TOKEN)
    expect(parseShareLinkToken(`Regarde mes idées : https://example.org/u/${TOKEN} !`)).toBe(TOKEN)
    expect(parseShareLinkToken(TOKEN)).toBe(TOKEN)
  })

  test('anything else is not an invitation', () => {
    expect(parseShareLinkToken('')).toBeNull()
    expect(parseShareLinkToken(null)).toBeNull()
    expect(parseShareLinkToken('https://example.org/u/short')).toBeNull()
    expect(parseShareLinkToken(`https://example.org/u/${TOKEN}extra`)).toBeNull()
    expect(parseShareLinkToken('com.example.app://share?url=https://shop.example/x')).toBeNull()
  })
})

describe('pending invitation', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
  })
  afterEach(() => vi.useRealTimers())

  test('survives a restart for 7 days, then is forgotten', () => {
    vi.useFakeTimers()
    usePendingInvitationStore().keep(TOKEN)

    setActivePinia(createPinia())
    expect(usePendingInvitationStore().token).toBe(TOKEN)

    vi.advanceTimersByTime(PENDING_INVITATION_TTL_MS + 1000)
    setActivePinia(createPinia())
    expect(usePendingInvitationStore().token).toBeNull()
  })

  test('put aside offline, resumed by reopening it, cleared once handled', () => {
    const store = usePendingInvitationStore()
    store.keep(TOKEN)
    store.defer()
    expect(store.deferred).toBe(true)

    store.keep(TOKEN)
    expect(store.deferred).toBe(false)

    store.clear()
    expect(store.token).toBeNull()
    expect(localStorage.getItem('ic.pendingInvitation')).toBeNull()
  })
})
