import { describe, expect, test } from 'vitest'
import { formatSize } from '@/utils/sizeUnits'

describe('formatSize (spec §11 décision 57)', () => {
  const label = (unit: string) => unit.toUpperCase()

  test('the value, then its unit', () => {
    expect(formatSize('42', 'eu', label)).toBe('42 EU')
  })

  test('the value alone without a unit', () => {
    expect(formatSize('M', null, label)).toBe('M')
    expect(formatSize('M', undefined, label)).toBe('M')
  })
})
