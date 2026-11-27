import { mount, RouterLinkStub } from '@vue/test-utils'
import { describe, expect, test } from 'vitest'
import IdeaTable from '@/components/IdeaTable.vue'
import { i18n } from '@/i18n'
import { DEFAULT_SORT } from '@/utils/ideaTable'
import type { Idea } from '@/types/idea'

const idea = (overrides: Partial<Idea> = {}): Idea => ({
  id: '0190a1b2-0000-7000-8000-000000000001',
  ownerId: 'camille',
  title: 'Casque audio',
  url: null,
  priceAmount: '180.00',
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

const ownerColumns = ['idea', 'state', 'occasion', 'price', 'added']
const friendColumns = ['idea', 'source', 'occasion', 'price', 'status', 'interactions']

const mountTable = (props: Record<string, unknown>) =>
  mount(IdeaTable, {
    props: { sort: DEFAULT_SORT, ...props },
    global: { plugins: [i18n], stubs: { RouterLink: RouterLinkStub } },
  })

/** Spec §11 décision 72: the ideas of a list as a table, on a computer. */
describe('IdeaTable', () => {
  test('owner view: state and occasion columns, nothing hidden', () => {
    const wrapper = mountTable({
      columns: ownerColumns,
      ideas: [idea(), idea({ id: 'd', visibility: 'private', occasion: null }), idea({ id: 'a', status: 'archived', archiveKind: 'received' })],
      actions: () => true,
    })
    const text = wrapper.text()

    expect(wrapper.findAll('tbody tr')).toHaveLength(3)
    expect(text).toContain('Publiée')
    expect(text).toContain('Brouillon')
    expect(text).toContain('Archivée')
    expect(text).toContain('Sans occasion')
    expect(text).toMatch(/180\s€/)
    expect(text).not.toMatch(/Suggérée|Réserv|Cotisation|Libre/)
    expect(wrapper.findComponent(RouterLinkStub).props('to')).toBe('/ideas/0190a1b2-0000-7000-8000-000000000001')
  })

  test('friend view: source, status and counters from the hidden fields the API sent', () => {
    const wrapper = mountTable({
      columns: friendColumns,
      ownerName: 'Camille',
      ideas: [
        idea({ id: 'own', view: 'friend', isSuggestion: false, reservation: null, contribution: null, reactions: { count: 3, likedByMe: false }, commentCount: 2 }),
        idea({
          id: 'sugg',
          view: 'friend',
          isSuggestion: true,
          isMine: false,
          author: { id: 'lea', displayName: 'Léa', avatarUrl: null },
          reservation: { id: 'r', user: { id: 'hugo', displayName: 'Hugo', avatarUrl: null }, isMine: false, createdAt: '' },
        }),
        idea({ id: 'draft', view: 'friend', isSuggestion: true, isMine: true, visibility: 'private' }),
        idea({ id: 'gone', view: 'friend', isSuggestion: false, status: 'archived', archiveKind: 'received', archivedAt: '2025-12-25T10:00:00Z' }),
      ],
    })
    const rows = wrapper.findAll('tbody tr').map((row) => row.text())

    expect(rows[0]).toContain('Idée de Camille')
    expect(rows[0]).toContain('Libre')
    expect(rows[0]).toMatch(/3.*2/)
    expect(rows[1]).toContain('Suggérée par Léa')
    expect(rows[1]).toContain('Réservé par Hugo')
    expect(rows[2]).toContain('Mon brouillon')
    expect(rows[2]).toContain('Publier')
    expect(rows[3]).toMatch(/Reçue · 25 déc\.? 2025/)
  })

  test('a header click sorts, a second one reverses', async () => {
    const wrapper = mountTable({ columns: ownerColumns, ideas: [idea()] })

    const price = wrapper.findAll('th button').find((b) => b.text() === 'Prix')!
    await price.trigger('click')
    expect(wrapper.emitted('update:sort')![0]).toEqual([{ key: 'price', dir: 'asc' }])

    await wrapper.setProps({ sort: { key: 'price', dir: 'asc' } })
    expect(wrapper.find('th.col-price').attributes('aria-sort')).toBe('ascending')
    await price.trigger('click')
    expect(wrapper.emitted('update:sort')![1]).toEqual([{ key: 'price', dir: 'desc' }])
  })

  test('emits actions and publish', async () => {
    const draft = idea({ view: 'friend', isSuggestion: true, isMine: true, visibility: 'private' })
    const wrapper = mountTable({ columns: friendColumns, ideas: [draft], actions: () => true })

    await wrapper.find('.publish').trigger('click')
    await wrapper.find('.more').trigger('click')

    expect(wrapper.emitted('publish')![0]).toEqual([draft])
    expect(wrapper.emitted('actions')![0]).toEqual([draft])
  })
})
