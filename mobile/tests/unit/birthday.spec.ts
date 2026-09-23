import { describe, expect, test } from 'vitest'
import { daysUntilBirthday, formatBirthday } from '@/utils/birthday'

describe('daysUntilBirthday', () => {
  test('counts days to a birthday later this year', () => {
    expect(daysUntilBirthday(4, 10, new Date(2026, 8, 22))).toBe(12)
  })

  test('is 0 on the day itself', () => {
    expect(daysUntilBirthday(22, 9, new Date(2026, 8, 22))).toBe(0)
  })

  test('rolls over to next year once passed', () => {
    expect(daysUntilBirthday(1, 1, new Date(2026, 11, 31))).toBe(1)
  })

  test('celebrates 29 February on 28 February in a non-leap year', () => {
    expect(daysUntilBirthday(29, 2, new Date(2027, 1, 27))).toBe(1)
    expect(daysUntilBirthday(29, 2, new Date(2028, 1, 27))).toBe(2)
  })
})

describe('formatBirthday', () => {
  test('uses Intl month names', () => {
    expect(formatBirthday(4, 10, 'fr')).toBe('4 octobre')
  })
})
