import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, test, vi } from 'vitest'
import ProfileGreeting from '@/components/ProfileGreeting.vue'
import { i18n } from '@/i18n'
import { useActiveProfileStore } from '@/stores/activeProfile'
import { useAuthStore } from '@/stores/auth'
import { applyProfileTint, profileTint } from '@/utils/profileTint'
import type { ManagedProfile } from '@/types/user'

const { create, dismissWith } = vi.hoisted(() => ({ create: vi.fn(), dismissWith: { value: null as unknown } }))
vi.mock('@ionic/vue', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@ionic/vue')>()),
  popoverController: { create },
}))

const ME = '0190a1b2-0000-7000-8000-0000000000aa'
const LEA = '0190a1b2-0000-7000-8000-0000000c0001'
const TOM = '0190a1b2-0000-7000-8000-0000000c0002'

const child = (id: string, displayName: string, deletionScheduledAt: string | null = null) =>
  ({ id, displayName, deletionScheduledAt }) as unknown as ManagedProfile

describe('profileTint', () => {
  test('my own account keeps the usual background', () => {
    expect(profileTint(null, [LEA, TOM])).toBeNull()
  })

  test('each child gets its own tint, by position', () => {
    expect(profileTint(LEA, [LEA, TOM])).toBe(0)
    expect(profileTint(TOM, [LEA, TOM])).toBe(1)
  })

  test('an unknown child (not loaded yet) gets no tint', () => {
    expect(profileTint(LEA, [])).toBeNull()
  })

  test('is set on, then removed from, the root element', () => {
    const root = document.createElement('html')
    applyProfileTint(1, root)
    expect(root.dataset.profileTint).toBe('1')
    applyProfileTint(null, root)
    expect(root.dataset.profileTint).toBeUndefined()
  })
})

describe('ProfileGreeting', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    create.mockReset().mockImplementation(async () => ({ present: vi.fn(), onDidDismiss: async () => dismissWith.value }))
    dismissWith.value = { role: 'backdrop' }
    i18n.global.locale.value = 'fr'
    useAuthStore().user = { id: ME, displayName: 'Luc' } as never
  })

  const mountGreeting = () => mount(ProfileGreeting, { global: { plugins: [i18n] } })

  test('greets me, as plain text when I manage no child', () => {
    const wrapper = mountGreeting()

    expect(wrapper.text()).toBe('Bonjour Luc')
    expect(wrapper.find('button').exists()).toBe(false)
  })

  test('greets the active child and switches profile from the menu under the greeting', async () => {
    const active = useActiveProfileStore()
    active.children = [child(LEA, 'Léa'), child(TOM, 'Tom'), child('0190a1b2-0000-7000-8000-0000000c0003', 'Zoé', '2026-11-20T00:00:00+00:00')]
    active.activeId = LEA
    const wrapper = mountGreeting()

    expect(wrapper.text()).toBe('Bonjour Léa')
    dismissWith.value = { role: 'select', data: { id: TOM } }
    await wrapper.find('button').trigger('click')
    await flushPromises()

    const options = create.mock.calls[0][0] as { event?: { target: Element }; side: string; componentProps: { profiles: { id: string | null; name: string; current: boolean; tintColor: string }[] } }
    // Anchored under the greeting.
    expect(options.event?.target).toBe(wrapper.find('button').element)
    expect(options.side).toBe('bottom')
    // Me, then the children I can act for (not one about to be deleted), each with its background.
    expect(options.componentProps.profiles.map((p) => [p.name, p.current, p.tintColor])).toEqual([
      ['Luc', false, 'var(--ic-profile-tint-me)'],
      ['Léa', true, 'var(--ic-profile-tint-0)'],
      ['Tom', false, 'var(--ic-profile-tint-1)'],
    ])

    expect(active.activeId).toBe(TOM)
    expect(wrapper.text()).toBe('Bonjour Tom')
  })

  test('closing the menu keeps the active profile', async () => {
    const active = useActiveProfileStore()
    active.children = [child(LEA, 'Léa')]
    active.activeId = LEA
    const wrapper = mountGreeting()

    await wrapper.find('button').trigger('click')
    await flushPromises()

    expect(active.activeId).toBe(LEA)
  })
})
