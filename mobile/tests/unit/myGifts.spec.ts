import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, test } from 'vitest'
import { useLocalDb } from '@/offline/db'
import { MemoryStore } from '@/offline/memoryStore'
import { repo } from '@/offline/repo'
import { giftsToOffer, groupMyGifts } from '@/utils/myGifts'
import type { Contribution, Idea } from '@/types/idea'

const me = { id: 'me', displayName: 'Luc', avatarUrl: null }
const other = { id: 'zoe', displayName: 'Zoé', avatarUrl: null }

const idea = (id: string, over: Partial<Idea> = {}): Idea =>
  ({ id, recipients: [{ id: 'camille', displayName: 'Camille', avatarUrl: null }], title: id, view: 'friend', status: 'active', visibility: 'published', isSuggestion: false,
    priceAmount: null, priceCurrency: 'EUR', createdAt: `2026-10-0${id.length}T10:00:00Z`, archivedAt: null, archiveKind: null,
    reservation: null, contribution: null, ...over }) as unknown as Idea

const contribution = (over: Partial<Contribution> = {}): Contribution =>
  ({ id: 'c', ideaId: 'x', status: 'open', initiator: other, isInitiator: false, targetAmount: null, currency: 'EUR',
    totalAmount: '50.00', participants: [], myPledge: null, ...over }) as unknown as Contribution

describe('« Mes cadeaux pour mes amis » (spec §11 décision 62)', () => {
  beforeEach(async () => {
    setActivePinia(createPinia())
    await useLocalDb().open(new MemoryStore())
    await useLocalDb().put('user', [
      { id: 'camille', displayName: 'Camille', avatarUrl: null, birthDay: 1, birthMonth: 12 },
      { id: 'alex', displayName: 'Alex', avatarUrl: null, birthDay: null, birthMonth: null },
    ] as never)
  })

  test('lists only my reservations and contributions, never anyone else\'s', async () => {
    await useLocalDb().put('idea', [
      idea('mine', { reservation: { id: 'r1', user: me, isMine: true, createdAt: '' } }),
      idea('theirs', { reservation: { id: 'r2', user: other, isMine: false, createdAt: '' } }),
      idea('initiated', { contribution: contribution({ isInitiator: true }) }),
      idea('pledged', { contribution: contribution({ myPledge: '20.00', participants: [{ user: me, isMe: true, isInitiator: false, amount: '20.00' }] }) }),
      idea('othersPot', { contribution: contribution({ participants: [{ user: other, isMe: false, isInitiator: true }] }) }),
      idea('nothing'),
    ] as never)

    expect(repo.myGifts().map((g) => [g.idea.id, g.kind]).sort()).toEqual([
      ['initiated', 'contribution'],
      ['mine', 'reserved'],
      ['pledged', 'contribution'],
    ])
  })

  test('an archived idea I reserved or contributed to is « given », keeping how', async () => {
    await useLocalDb().put('idea', [
      idea('rec', { status: 'archived', archiveKind: 'received', archivedAt: '2026-10-20T10:00:00Z', reservation: { id: 'r', user: me, isMine: true, createdAt: '' } }),
      idea('pot', { status: 'archived', archiveKind: 'gifted', archivedAt: '2026-10-21T10:00:00Z', contribution: contribution({ status: 'closed', isInitiator: true }) }),
    ] as never)

    expect(repo.myGifts().map((g) => [g.idea.id, g.kind, g.via])).toEqual([
      ['pot', 'given', 'contribution'],
      ['rec', 'given', 'reservation'],
    ])
  })

  test('never my own list, nor a draft', async () => {
    await useLocalDb().put('idea', [
      // The owner view carries no hidden field; even if it did, it is not a gift of mine.
      idea('own', { recipients: [me], view: 'owner', reservation: { id: 'r', user: me, isMine: true, createdAt: '' } }),
      idea('draft', { visibility: 'private', reservation: { id: 'r', user: me, isMine: true, createdAt: '' } }),
    ] as never)

    expect(repo.myGifts()).toEqual([])
  })

  test('the recipient comes from the synced friend, or from my child (manager view)', async () => {
    await useLocalDb().put('managed_profile', [{ id: 'kid', displayName: 'Léa', avatarUrl: null, birthDay: 3, birthMonth: 4 }] as never)
    await useLocalDb().put('idea', [
      idea('a', { reservation: { id: 'r', user: me, isMine: true, createdAt: '' } }),
      idea('b', { recipients: [{ id: 'kid', displayName: 'Léa', avatarUrl: null }], view: 'manager', reservation: { id: 'r', user: me, isMine: true, createdAt: '' } }),
    ] as never)

    expect(Object.fromEntries(repo.myGifts().map((g) => [g.idea.id, g.recipients.map((r) => r.displayName)]))).toEqual({ a: ['Camille'], b: ['Léa'] })
  })

  test('groups by recipient, nearest birthday first, gifts to offer before given ones; filters by kind and friend', async () => {
    await useLocalDb().put('idea', [
      idea('given', { status: 'archived', archiveKind: 'received', archivedAt: '2026-10-30T10:00:00Z', reservation: { id: 'r', user: me, isMine: true, createdAt: '' } }),
      idea('res', { reservation: { id: 'r', user: me, isMine: true, createdAt: '' } }),
      idea('alexPot', { recipients: [{ id: 'alex', displayName: 'Alex', avatarUrl: null }], contribution: contribution({ isInitiator: true }) }),
    ] as never)
    const gifts = repo.myGifts()
    const today = new Date(2026, 10, 4)

    const groups = groupMyGifts(gifts, { kind: 'all', person: null }, today)
    expect(groups.map((g) => [g.key, g.days, g.gifts.map((x) => x.idea.id)])).toEqual([
      ['camille', 27, ['res', 'given']],
      ['alex', null, ['alexPot']],
    ])
    expect(groupMyGifts(gifts, { kind: 'given', person: null }, today).flatMap((g) => g.gifts.map((x) => x.idea.id))).toEqual(['given'])
    expect(groupMyGifts(gifts, { kind: 'all', person: 'alex' }, today).map((g) => g.key)).toEqual(['alex'])
    expect(giftsToOffer(gifts)).toBe(2)
  })

  test('an idea for several people is one gift, under all of them together (décision 69)', async () => {
    const people = [{ id: 'alex', displayName: 'Alex', avatarUrl: null }, { id: 'camille', displayName: 'Camille', avatarUrl: null }, { id: 'nina', displayName: 'Nina', avatarUrl: 'n.jpg' }]
    await useLocalDb().put('idea', [
      idea('airfryer', { recipients: people, reservation: { id: 'r', user: me, isMine: true, createdAt: '' } }),
      idea('res', { reservation: { id: 'r2', user: me, isMine: true, createdAt: '' } }),
    ] as never)
    const gifts = repo.myGifts()
    const shared = gifts.find((g) => 'airfryer' === g.idea.id)!
    // Nina isn't my friend: her name and avatar come from the idea, no birthday.
    expect(shared.recipients.map((r) => [r.id, r.displayName, r.birthMonth])).toEqual([['alex', 'Alex', null], ['camille', 'Camille', 12], ['nina', 'Nina', null]])

    const today = new Date(2026, 10, 4)
    expect(groupMyGifts(gifts, { kind: 'all', person: null }, today).map((g) => [g.key, g.days, g.gifts.map((x) => x.idea.id)])).toEqual([
      // Same nearest birthday (Camille's): by name, « Alex, Camille et Nina » first.
      ['alex+camille+nina', 27, ['airfryer']],
      ['camille', 27, ['res']],
    ])
    expect(groupMyGifts(gifts, { kind: 'all', person: 'nina' }, today).map((g) => g.key)).toEqual(['alex+camille+nina'])
    expect(giftsToOffer(gifts)).toBe(2)
  })
})
