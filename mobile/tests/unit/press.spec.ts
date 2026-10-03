import { mount } from '@vue/test-utils'
import { describe, expect, test, vi } from 'vitest'
import { press } from '@/directives/press'

describe('v-press', () => {
  test('makes a clickable element a keyboard-operable button', async () => {
    const onClick = vi.fn()
    const wrapper = mount({ template: '<div v-press @click="onClick">Noël</div>', methods: { onClick } }, { global: { directives: { press } } })
    const el = wrapper.element as HTMLElement

    expect(el.getAttribute('role')).toBe('button')
    expect(el.getAttribute('tabindex')).toBe('0')

    await wrapper.trigger('keydown', { key: 'Enter' })
    await wrapper.trigger('keydown', { key: ' ' })
    await wrapper.trigger('keydown', { key: 'a' })
    expect(onClick).toHaveBeenCalledTimes(2)
  })

  test('keeps a role or tabindex already set', () => {
    const wrapper = mount({ template: '<div v-press role="checkbox" tabindex="-1" />' }, { global: { directives: { press } } })
    expect(wrapper.element.getAttribute('role')).toBe('checkbox')
    expect(wrapper.element.getAttribute('tabindex')).toBe('-1')
  })
})
