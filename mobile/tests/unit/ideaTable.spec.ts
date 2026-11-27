import { beforeEach, describe, expect, test } from 'vitest'
import {
  countBy,
  DEFAULT_SORT,
  filterIdeas,
  ideaPhase,
  ideaSource,
  ideaState,
  NO_OCCASION,
  occasionKey,
  readStoredSet,
  sortIdeas,
  storeSet,
  toggled,
  uniqueIdeas,
  type SortLabels,
} from '@/utils/ideaTable'
import type { Idea } from '@/types/idea'

let n = 0
const idea = (overrides: Partial<Idea> = {}): Idea => ({
  id: `i${++n}`,
  ownerId: 'camille',
  title: 'Idée',
  url: null,
  priceAmount: null,
  priceCurrency: 'EUR',
  imageUrl: null,
  thumbnailUrl: null,
  note: null,
  occasion: null,
  visibility: 'published',
  publishedAt: null,
  status: 'active',
  archivedAt: null,
  archiveKind: null,
  createdAt: '2026-11-01T10:00:00Z',
  updatedAt: '2026-11-01T10:00:00Z',
  view: 'owner',
  canEdit: true,
  canUnarchive: false,
  ...overrides,
})

const labels: SortLabels = { occasion: (i) => i.occasion ?? 'Sans occasion', source: (i) => ideaSource(i) }

/** Spec §11 décision 72: every idea of a list in one table, told apart by columns. */
describe('idea table helpers', () => {
  test('state, source and phase of an idea', () => {
    expect(ideaState(idea())).toBe('published')
    expect(ideaState(idea({ visibility: 'private' }))).toBe('draft')
    expect(ideaState(idea({ status: 'archived', visibility: 'private' }))).toBe('archived')

    expect(ideaSource(idea({ view: 'friend', isSuggestion: false }))).toBe('owner')
    expect(ideaSource(idea({ view: 'friend', isSuggestion: true }))).toBe('suggestion')
    expect(ideaSource(idea({ view: 'friend', isSuggestion: true, visibility: 'private' }))).toBe('draft')

    expect(ideaPhase(idea())).toBe('active')
    expect(ideaPhase(idea({ status: 'archived' }))).toBe('archived')

    expect(occasionKey(idea())).toBe(NO_OCCASION)
    expect(occasionKey(idea({ occasion: 'christmas' }))).toBe('christmas')
  })

  test('filters add up: state, source, phase, occasion and search', () => {
    const published = idea({ title: 'Platine vinyle', occasion: 'birthday' })
    const draft = idea({ title: 'Carnet', visibility: 'private', note: 'papier coton' })
    const archived = idea({ title: 'Montre', status: 'archived', occasion: 'birthday' })
    const all = [published, draft, archived]

    expect(filterIdeas(all, { states: new Set(['published', 'draft'] as const) })).toEqual([published, draft])
    expect(filterIdeas(all, { phases: new Set(['archived'] as const) })).toEqual([archived])
    expect(filterIdeas(all, { occasion: 'birthday' })).toEqual([published, archived])
    expect(filterIdeas(all, { occasion: NO_OCCASION })).toEqual([draft])
    expect(filterIdeas(all, { search: 'COTON' })).toEqual([draft])
    expect(filterIdeas(all, { sources: new Set(['draft'] as const) })).toEqual([draft])
    expect(filterIdeas(all, {})).toEqual(all)
  })

  test('counts and duplicates', () => {
    const a = idea({ occasion: 'birthday' })
    const b = idea({ occasion: 'birthday' })
    const c = idea({ visibility: 'private' })

    expect(countBy([a, b, c], occasionKey)).toEqual({ birthday: 2, [NO_OCCASION]: 1 })
    expect(countBy([a, b, c], ideaState)).toEqual({ published: 2, draft: 1 })
    expect(uniqueIdeas([a, b, a])).toEqual([a, b])
  })

  test('sorts by date (most recent first by default), title, state and price', () => {
    const old = idea({ title: 'b', createdAt: '2025-01-01T00:00:00Z', priceAmount: '30.00', visibility: 'private' })
    const recent = idea({ title: 'A', createdAt: '2026-11-20T00:00:00Z', priceAmount: '149.00' })
    const noPrice = idea({ title: 'c', createdAt: '2026-06-01T00:00:00Z', status: 'archived' })
    const all = [old, noPrice, recent]

    expect(sortIdeas(all, DEFAULT_SORT, labels, 'fr')).toEqual([recent, noPrice, old])
    expect(sortIdeas(all, { key: 'added', dir: 'asc' }, labels, 'fr')).toEqual([old, noPrice, recent])
    expect(sortIdeas(all, { key: 'title', dir: 'asc' }, labels, 'fr')).toEqual([recent, old, noPrice])
    expect(sortIdeas(all, { key: 'state', dir: 'asc' }, labels, 'fr')).toEqual([recent, old, noPrice])
    // Without a price: last, whichever the direction.
    expect(sortIdeas(all, { key: 'price', dir: 'asc' }, labels, 'fr')).toEqual([old, recent, noPrice])
    expect(sortIdeas(all, { key: 'price', dir: 'desc' }, labels, 'fr')).toEqual([recent, old, noPrice])
    // A copy: the slices stay as loaded.
    expect(all).toEqual([old, noPrice, recent])
  })

  describe('kept filters', () => {
    beforeEach(() => localStorage.clear())

    test('default, then what was stored, keeping known values only', () => {
      expect([...readStoredSet('k', ['published', 'draft', 'archived'], ['published', 'draft'])]).toEqual(['published', 'draft'])

      storeSet('k', new Set(['archived', 'nonsense']))
      expect([...readStoredSet('k', ['published', 'draft', 'archived'], ['published'])]).toEqual(['archived'])

      localStorage.setItem('k', '{broken')
      expect([...readStoredSet('k', ['published'], ['published'])]).toEqual(['published'])
    })

    test('toggling makes a copy', () => {
      const values = new Set(['a'])

      expect([...toggled(values, 'b')]).toEqual(['a', 'b'])
      expect([...toggled(values, 'a')]).toEqual([])
      expect([...values]).toEqual(['a'])
    })
  })
})
