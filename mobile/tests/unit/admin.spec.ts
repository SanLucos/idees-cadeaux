import { beforeEach, describe, expect, test, vi } from 'vitest'

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), delete: vi.fn() }))
vi.mock('@/services/api', () => ({ api }))

import { adminApi } from '@/services/admin'
import { isConnectable, pairCount, signInKeys } from '@/utils/admin'
import type { AdminUser } from '@/types/admin'

const user = (overrides: Partial<AdminUser> = {}): AdminUser => ({
  id: 'u1',
  displayName: 'Anna',
  avatarUrl: null,
  email: 'anna@example.com',
  type: 'regular',
  managers: [],
  emailVerified: true,
  deletionScheduledAt: null,
  isSuperAdmin: false,
  friendsCount: 0,
  createdAt: '2026-11-21T10:00:00+00:00',
  signIn: { password: true, emailLink: false, social: [] },
  relation: null,
  ...overrides,
})

describe('back-office (spec §5.17, §11 décision 71)', () => {
  beforeEach(() => vi.clearAllMocks())

  test('only adults not being deleted can be connected', () => {
    expect(isConnectable(user())).toBe(true)
    expect(isConnectable(user({ type: 'managed' }))).toBe(false)
    expect(isConnectable(user({ deletionScheduledAt: '2026-12-01T00:00:00+00:00' }))).toBe(false)
  })

  test('pairs among the people selected', () => {
    expect(pairCount(2)).toBe(1)
    expect(pairCount(3)).toBe(3)
    expect(pairCount(5)).toBe(10)
  })

  test('how an account signs in: password, link + long session, Google, Apple', () => {
    expect(signInKeys(user())).toEqual(['password'])
    expect(signInKeys(user({ signIn: { password: false, emailLink: true, social: [] } }))).toEqual(['emailLink'])
    expect(signInKeys(user({ signIn: { password: true, emailLink: true, social: ['google', 'apple'] } }))).toEqual(['password', 'emailLink', 'google', 'apple'])
    expect(signInKeys(user({ signIn: { password: false, emailLink: false, social: [] } }))).toEqual([])
  })

  test('queries never carry X-Acting-As and skip default values', async () => {
    api.get.mockResolvedValue({ total: 0, page: 1, pageSize: 50, items: [] })
    api.post.mockResolvedValue({ done: 1, unavailable: 0 })

    await adminApi.users()
    await adminApi.users({ q: ' anna ', type: 'regular', sort: 'displayName', page: 2, relativeTo: 'u2' })
    await adminApi.connect(['u1', 'u2'], 'force')
    await adminApi.removeFriendship('f1')
    await adminApi.cancelIntroduction('i1')

    expect(api.get).toHaveBeenNthCalledWith(1, '/admin/users', { acting: false })
    expect(api.get).toHaveBeenNthCalledWith(2, '/admin/users?q=anna&type=regular&sort=displayName&page=2&relativeTo=u2', { acting: false })
    expect(api.post).toHaveBeenCalledWith('/admin/connections', { json: { users: ['u1', 'u2'], mode: 'force' }, acting: false })
    expect(api.delete).toHaveBeenCalledWith('/admin/friendships/f1', { acting: false })
    expect(api.delete).toHaveBeenCalledWith('/admin/introductions/i1', { acting: false })
  })
})
