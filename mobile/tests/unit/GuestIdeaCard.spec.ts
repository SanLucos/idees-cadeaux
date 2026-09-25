import { mount } from '@vue/test-utils'
import { describe, expect, test } from 'vitest'
import GuestIdeaCard from '@/components/GuestIdeaCard.vue'
import { i18n } from '@/i18n'
import type { GuestIdea } from '@/types/shareLink'

const idea: GuestIdea = {
  id: '0190a1b2-0000-7000-8000-000000000001',
  view: 'guest',
  title: 'Sac à dos',
  url: 'https://shop.example/sac',
  priceAmount: '120.00',
  priceCurrency: 'EUR',
  imageUrl: null,
  thumbnailUrl: null,
  note: 'Taille M',
  occasion: 'birthday',
}

describe('GuestIdeaCard', () => {
  test('shows the idea, and every action asks to create an account', async () => {
    const wrapper = mount(GuestIdeaCard, { props: { idea }, global: { plugins: [i18n] } })

    expect(wrapper.text()).toContain('Sac à dos')
    expect(wrapper.text()).toContain('120')
    expect(wrapper.text()).toContain('Anniversaire')
    expect(wrapper.text()).toContain('Taille M')
    expect(wrapper.find('a[href="https://shop.example/sac"]').attributes('rel')).toContain('noopener')

    const buttons = wrapper.findAll('ion-button')
    expect(buttons).toHaveLength(3)
    for (const button of buttons) await button.trigger('click')
    expect(wrapper.emitted('interact')).toHaveLength(3)
  })
})
