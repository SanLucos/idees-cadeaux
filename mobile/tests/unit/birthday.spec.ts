import { describe, expect, test } from 'vitest'
import { birthDatePatch, daysUntilBirthday, formatBirthday } from '@/utils/birthday'

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

describe('birthDatePatch', () => {
  test('sends no birthday when the fields are left empty', () => {
    expect(birthDatePatch(null, null, null)).toEqual({ birthDay: null, birthMonth: null, birthYear: null })
  })

  test('turns emptied inputs into null', () => {
    expect(birthDatePatch('', '', '')).toEqual({ birthDay: null, birthMonth: null, birthYear: null })
  })

  test('ignores a year given without day and month', () => {
    expect(birthDatePatch('', null, 1990)).toEqual({ birthDay: null, birthMonth: null, birthYear: null })
  })

  test('keeps a full or partial date as typed', () => {
    expect(birthDatePatch(14, 7, '')).toEqual({ birthDay: 14, birthMonth: 7, birthYear: null })
    expect(birthDatePatch(14, 7, 1990)).toEqual({ birthDay: 14, birthMonth: 7, birthYear: 1990 })
  })
})
