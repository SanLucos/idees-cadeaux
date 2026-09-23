import { describe, expect, test } from 'vitest'
import { colorIndex, initials } from '@/utils/colorIndex'

describe('colorIndex', () => {
  test('is stable and within the palette', () => {
    const a = colorIndex('0190a1b2-0000-7000-8000-000000000001', 6)
    expect(a).toBe(colorIndex('0190a1b2-0000-7000-8000-000000000001', 6))
    expect(a).toBeGreaterThanOrEqual(0)
    expect(a).toBeLessThan(6)
  })
})

describe('initials', () => {
  test('one letter for a single word, two for a full name, ? when empty', () => {
    expect(initials('camille')).toBe('C')
    expect(initials('Marc Dupont')).toBe('MD')
    expect(initials(null)).toBe('?')
  })
})
