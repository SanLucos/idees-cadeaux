import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, test, vi } from 'vitest'
import { useLocalDb } from '@/offline/db'
import { MemoryStore } from '@/offline/memoryStore'
import { profileMutations } from '@/offline/mutations'
import { repo } from '@/offline/repo'
import { useSync } from '@/offline/sync'
import { useAuthStore } from '@/stores/auth'

vi.mock('@aparajita/capacitor-secure-storage', () => ({
  SecureStorage: { getItem: vi.fn(async () => 'token'), setItem: vi.fn(), removeItem: vi.fn() },
}))

const lastYear = { id: 'h1', value: '36', since: '2025-09-01T10:00:00.000Z' }
const size = (history = [lastYear]) => ({ id: 's1', '@id': '/api/profile_sizes/s1', userId: 'me', label: 'Pointure', value: history.at(-1)!.value, note: null, sortOrder: 0, history })

describe('size history offline (spec §11 décision 51)', () => {
  beforeEach(async () => {
    setActivePinia(createPinia())
    vi.stubGlobal('fetch', vi.fn(async () => { throw new TypeError('offline') }))
    await useLocalDb().open(new MemoryStore())
    useAuthStore().user = { id: 'me', displayName: 'Luc', avatarUrl: null } as never
  })

  test('a new value shows in the history at once; reordering or renaming leaves it alone', async () => {
    await useLocalDb().put('profile_size', [size()])

    await profileMutations.updateSize('s1', { sortOrder: 2 })
    await profileMutations.updateSize('s1', { label: 'Chaussures', value: '36', note: null })
    expect(repo.sizes('me')[0].history).toEqual([lastYear])

    await profileMutations.updateSize('s1', { label: 'Chaussures', value: '37', note: null })
    expect(repo.sizes('me')[0].history!.map((e) => e.value)).toEqual(['36', '37'])
    expect(useSync().pending.map((op) => op.label.kind)).toEqual(['size.update', 'size.edit', 'size.edit'])
  })

  test('a size added offline starts its history', async () => {
    await profileMutations.addSize('Pointure', '36', null, 0)

    expect(repo.sizes('me')[0].history!.map((e) => e.value)).toEqual(['36'])
  })

  test('a past value is removed at once and the request queued', async () => {
    const current = { id: 'h2', value: '37', since: '2026-09-01T10:00:00.000Z' }
    await useLocalDb().put('profile_size', [size([lastYear, current])])

    await profileMutations.removeSizeHistoryEntry('s1', 'h1')

    expect(repo.sizes('me')[0].history).toEqual([current])
    expect(repo.sizes('me')[0].value).toBe('37')
    expect(useSync().pending.at(-1)).toMatchObject({ method: 'DELETE', path: '/profile_sizes/s1/history/h1', label: { kind: 'size.history_delete', title: 'Pointure' } })
  })
})
