import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, test, vi } from 'vitest'
import ProfileGreeting from '@/components/ProfileGreeting.vue'
import { i18n } from '@/i18n'
import { useActiveProfileStore } from '@/stores/activeProfile'
import { useAuthStore } from '@/stores/auth'
import { applyProfileTint, profileTint } from '@/utils/profileTint'
import type { ManagedProfile } from '@/types/user'

const { create } = vi.hoisted(() => ({ create: vi.fn() }))
vi.mock('@ionic/vue', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@ionic/vue')>()),
  actionSheetController: { create },
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
    create.mockReset().mockResolvedValue({ present: vi.fn() })
    i18n.global.locale.value = 'fr'
    useAuthStore().user = { id: ME, displayName: 'Luc' } as never
  })

  const mountGreeting = () => mount(ProfileGreeting, { global: { plugins: [i18n] } })

  test('greets me, as plain text when I manage no child', () => {
    const wrapper = mountGreeting()

    expect(wrapper.text()).toBe('Bonjour Luc')
    expect(wrapper.find('button').exists()).toBe(false)
  })

  test('greets the active child and switches profile on click', async () => {
    const active = useActiveProfileStore()
    active.children = [child(LEA, 'Léa'), child(TOM, 'Tom'), child('0190a1b2-0000-7000-8000-0000000c0003', 'Zoé', '2026-11-20T00:00:00+00:00')]
    active.activeId = LEA
    const wrapper = mountGreeting()

    expect(wrapper.text()).toBe('Bonjour Léa')
    await wrapper.find('button').trigger('click')

    const { buttons } = create.mock.calls[0][0] as { buttons: { text: string; cssClass?: string; role?: string; handler?: () => void }[] }
    // Me, then children I can act for (not one about to be deleted), then cancel.
    expect(buttons.map((b) => b.text)).toEqual(['Luc', 'Léa', 'Tom', 'Annuler'])
    expect(buttons[1].cssClass).toBe('ic-action-current')
    expect(buttons[0].cssClass).toBeUndefined()

    buttons[2].handler!()
    expect(active.activeId).toBe(TOM)
    await wrapper.vm.$nextTick()
    expect(wrapper.text()).toBe('Bonjour Tom')

    buttons[0].handler!()
    expect(active.activeId).toBeNull()
  })
})
