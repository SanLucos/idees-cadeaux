import { describe, expect, test } from 'vitest'
import { isFor, isShared, recipientIds, recipientsKey, recipientsOf } from '@/utils/recipients'
import type { Idea } from '@/types/idea'

const anne = { id: 'anne', displayName: 'Anne', avatarUrl: null }
const luc = { id: 'luc', displayName: 'Luc', avatarUrl: null }

describe('an idea\'s recipients (spec §11 décision 69)', () => {
  test('one or several people, each of them the idea\'s owner', () => {
    const shared = { recipients: [anne, luc] } as Idea
    expect(recipientIds(shared)).toEqual(['anne', 'luc'])
    expect(isShared(shared)).toBe(true)
    expect(isFor(shared, 'luc')).toBe(true)
    expect(isFor(shared, 'paul')).toBe(false)
    expect(isFor(shared, null)).toBe(false)
    expect(isShared({ recipients: [anne] } as Idea)).toBe(false)
  })

  test('a document synced before lot 15 reads as its single owner', () => {
    const legacy = { ownerId: 'anne' } as unknown as Idea
    expect(recipientsOf(legacy)).toEqual([{ id: 'anne', displayName: null, avatarUrl: null }])
    expect(isFor(legacy, 'anne')).toBe(true)
  })

  test('the same people make the same group, whatever the order', () => {
    expect(recipientsKey(['luc', 'anne'])).toBe(recipientsKey(['anne', 'luc']))
    expect(recipientsKey(['anne'])).toBe('anne')
  })
})
