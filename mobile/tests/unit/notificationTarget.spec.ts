import { describe, expect, test } from 'vitest'
import { notificationTarget } from '@/utils/notificationTarget'
import type { AppNotification } from '@/types/notification'

const n = (type: AppNotification['type'], payload: AppNotification['payload']): AppNotification =>
  ({ id: 'n', type, payload, readAt: null, createdAt: '' })

describe('notificationTarget', () => {
  test('ideas, contributions and friends each open their screen', () => {
    expect(notificationTarget(n('comment_added', { idea: { id: 'i1', title: 't' } })).path).toBe('/ideas/i1')
    expect(notificationTarget(n('pledge_added', { idea: { id: 'i1', title: 't' }, contribution: { id: 'c1' } })).path).toBe('/contributions/c1')
    expect(notificationTarget(n('birthday_reminder', { friend: { id: 'u2', displayName: 'Camille' }, days: 14 })).path).toBe('/tabs/friends/u2')
    expect(notificationTarget(n('friend_request_received', { actor: { id: 'u3', displayName: 'Marc' } })).path).toBe('/tabs/friends')
  })

  test('a now-private idea sends to its owner\'s list instead', () => {
    expect(notificationTarget(n('idea_unpublished', { idea: { id: 'i1', title: 't' }, owner: { id: 'o1', displayName: 'Camille' } })).path).toBe('/tabs/friends/o1')
  })

  test('« Pour Jules »: switch to the child profile first', () => {
    const target = notificationTarget(n('friend_request_accepted', { actor: { id: 'u4', displayName: 'Hugo' }, subject: { id: 'child-1', displayName: 'Jules' } }))
    expect(target).toEqual({ path: '/tabs/friends/u4', actAs: 'child-1' })
  })
})
