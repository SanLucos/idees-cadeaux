import { mount } from '@vue/test-utils'
import { describe, expect, test } from 'vitest'
import InteractionPills from '@/components/InteractionPills.vue'
import { i18n } from '@/i18n'
import type { Contribution, Idea } from '@/types/idea'

const base = { id: 'i1', view: 'friend', priceCurrency: 'EUR' } as Idea
const person = { id: 'u1', displayName: 'Hugo', avatarUrl: null }
const render = (idea: Partial<Idea>) => mount(InteractionPills, { props: { idea: { ...base, ...idea } }, global: { plugins: [i18n] } }).text()

describe('InteractionPills', () => {
  test('owner view carries no hidden field: nothing to render', () => {
    expect(render({ view: 'owner' }).trim()).toBe('')
  })

  test('reservation, by someone else or by me', () => {
    expect(render({ reservation: { id: 'r', user: person, isMine: false, createdAt: '' } })).toContain('Réservé par Hugo')
    expect(render({ reservation: { id: 'r', user: person, isMine: true, createdAt: '' } })).toContain('Réservé par vous')
  })

  test('open contribution shows total over target; likes and comments counts', () => {
    const contribution = { status: 'open', totalAmount: '110.00', targetAmount: '180.00', currency: 'EUR' } as Contribution
    const text = render({ reservation: null, contribution, reactions: { count: 3, likedByMe: false }, commentCount: 2 }).replace(/\s+/g, ' ')

    expect(text).toMatch(/Cotisation · 110\s€ \/ 180\s€/)
    expect(text).toContain('3')
    expect(text).toContain('2')
  })

  test('a closed contribution is not shown as in progress', () => {
    const contribution = { status: 'closed', totalAmount: '40.00', targetAmount: null, currency: 'EUR' } as Contribution
    expect(render({ reservation: null, contribution })).not.toContain('Cotisation')
  })
})
