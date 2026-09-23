import { describe, expect, test } from 'vitest'
import { formatPrice } from '@/utils/price'
import { uuidv7 } from '@/utils/uuidv7'

// Intl uses (narrow) no-break spaces in French; normalise them for readable assertions.
const plain = (s: string | null) => s?.replace(/[\u202f\u00a0]/g, ' ')

describe('formatPrice', () => {
  test('drops decimals on whole amounts, keeps two otherwise', () => {
    expect(plain(formatPrice('149.00', 'EUR', 'fr'))).toBe('149 €')
    expect(plain(formatPrice('29.90', 'EUR', 'fr'))).toBe('29,90 €')
  })

  test('no amount, no price', () => {
    expect(formatPrice(null, 'EUR', 'fr')).toBeNull()
  })
})

describe('uuidv7', () => {
  test('is a version-7, RFC-variant UUID', () => {
    expect(uuidv7()).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/)
  })

  test('sorts by creation time', () => {
    expect(uuidv7(1_000) < uuidv7(2_000)).toBe(true)
  })
})
