import { describe, expect, test } from 'vitest'
import { appVersionLabel } from '@/utils/appVersion'

describe('app version (Profil)', () => {
  test('the version, then the commit it was built from when known', () => {
    expect(appVersionLabel('1.0.0', '42f3567')).toBe('1.0.0 · 42f3567')
    expect(appVersionLabel('1.0.0', '')).toBe('1.0.0')
  })

  test('injected at build time: the release tag without its « v », else package.json', () => {
    expect(appVersionLabel()).toMatch(/^\d+\.\d+\.\d+\S*( · [0-9a-f]+)?$/)
  })
})
