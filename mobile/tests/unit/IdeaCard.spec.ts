import { mount, RouterLinkStub } from '@vue/test-utils'
import { describe, expect, test } from 'vitest'
import IdeaCard from '@/components/IdeaCard.vue'
import { i18n } from '@/i18n'
import type { Idea } from '@/types/idea'

const idea = (overrides: Partial<Idea> = {}): Idea => ({
  id: '0190a1b2-0000-7000-8000-000000000001',
  ownerId: '0190a1b2-0000-7000-8000-0000000000aa',
  title: 'Platine vinyle',
  url: null,
  priceAmount: '149.00',
  priceCurrency: 'EUR',
  imageUrl: null,
  thumbnailUrl: null,
  note: null,
  occasion: 'birthday',
  visibility: 'published',
  publishedAt: '2026-09-22T10:00:00+00:00',
  status: 'active',
  archivedAt: null,
  archiveKind: null,
  createdAt: '2026-09-22T10:00:00+00:00',
  updatedAt: '2026-09-22T10:00:00+00:00',
  view: 'owner',
  canEdit: true,
  canUnarchive: false,
  ...overrides,
})

const mountCard = (props: Record<string, unknown>) =>
  mount(IdeaCard, { props, global: { plugins: [i18n], stubs: { RouterLink: RouterLinkStub } } })

describe('IdeaCard', () => {
  test('shows title, price and translated occasion, linking to the detail', () => {
    const wrapper = mountCard({ idea: idea() })

    expect(wrapper.text()).toContain('Platine vinyle')
    expect(wrapper.text()).toMatch(/149\s€/)
    expect(wrapper.text()).toContain('Anniversaire')
    expect(wrapper.findComponent(RouterLinkStub).props('to')).toBe('/ideas/0190a1b2-0000-7000-8000-000000000001')
  })

  test('owner view has nothing hidden to show: no suggestion or secret wording', () => {
    const text = mountCard({ idea: idea(), actions: true }).text()

    expect(text).not.toMatch(/Suggérée|invisible|Réserv|Cotisation/)
  })

  test('a draft gets the dashed style and, on request, the "visible to you only" note', () => {
    const wrapper = mountCard({ idea: idea({ visibility: 'private' }), showPrivateNote: true })

    expect(wrapper.classes()).toContain('idea-card--draft')
    expect(wrapper.text()).toContain('Visible de vous seul')
  })

  test('emits actions from the "…" button', async () => {
    const wrapper = mountCard({ idea: idea(), actions: true })
    await wrapper.find('ion-button').trigger('click')

    expect(wrapper.emitted('actions')?.[0]?.[0]).toMatchObject({ title: 'Platine vinyle' })
  })
})
