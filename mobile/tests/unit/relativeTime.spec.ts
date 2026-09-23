import { describe, expect, test } from 'vitest'
import { relativeTime } from '@/utils/relativeTime'

const now = new Date('2026-09-23T12:00:00Z')

describe('relativeTime', () => {
  test('hours and days, via Intl', () => {
    expect(relativeTime('2026-09-23T10:00:00Z', 'fr', now)).toMatch(/il y a 2\sh/)
    expect(relativeTime('2026-09-22T12:00:00Z', 'fr', now)).toBe('hier')
  })

  test('under a minute is left to the caller', () => {
    expect(relativeTime('2026-09-23T11:59:50Z', 'fr', now)).toBeNull()
  })
})
