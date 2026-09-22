import { mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { describe, expect, test, vi, beforeEach } from 'vitest'
import HomePage from '@/views/HomePage.vue'
import { i18n } from '@/i18n'

describe('HomePage.vue', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn(async () => ({
      ok: true,
      json: async () => ({ status: 'ok' }),
    })))
  })

  test('renders the app name from i18n', () => {
    const wrapper = mount(HomePage, {
      global: { plugins: [createPinia(), i18n] },
    })

    expect(wrapper.text()).toContain('Idées Cadeaux')
  })

  test('reports the API as connected once the health check resolves', async () => {
    const wrapper = mount(HomePage, {
      global: { plugins: [createPinia(), i18n] },
    })

    await vi.waitFor(() => {
      expect(wrapper.text()).toContain('Connectée')
    })
  })
})
