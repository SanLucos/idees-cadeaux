import { describe, expect, test } from 'vitest'
import { fallbackTitle, parseShareDeepLink, parseSharedContent } from '@/utils/sharedContent'

describe('shared content (spec §5.6)', () => {
  test('a bare URL', () => {
    expect(parseSharedContent({ text: 'https://shop.example/p/42' })).toEqual({ url: 'https://shop.example/p/42', title: null })
  })

  test('title and URL mixed in the text, trailing punctuation dropped', () => {
    expect(parseSharedContent({ text: 'Carnet de voyage – https://shop.example/carnet.' })).toEqual({
      url: 'https://shop.example/carnet',
      title: 'Carnet de voyage',
    })
  })

  test('a separate subject wins over the surrounding text', () => {
    expect(parseSharedContent({ text: 'Regarde ça https://shop.example/x', title: 'Lampe de chevet' })).toEqual({
      url: 'https://shop.example/x',
      title: 'Lampe de chevet',
    })
  })

  test('text without a link still gives a title; nothing at all gives null', () => {
    expect(parseSharedContent({ text: 'Un pull rouge' })).toEqual({ url: null, title: 'Un pull rouge' })
    expect(parseSharedContent({ text: '   ' })).toBeNull()
  })

  test('non-http links are ignored', () => {
    expect(parseSharedContent({ url: 'javascript:alert(1)', text: null })).toBeNull()
  })

  test('deep links from the native side, whatever the app id scheme', () => {
    expect(parseShareDeepLink('fr.frigologie.ideescadeaux://share?url=https%3A%2F%2Fshop.example%2Fa&title=Casque')).toEqual({
      url: 'https://shop.example/a',
      title: 'Casque',
    })
    expect(parseShareDeepLink('fr.frigologie.ideescadeaux://other?url=https%3A%2F%2Fshop.example%2Fa')).toBeNull()
    expect(parseShareDeepLink('not a link')).toBeNull()
  })

  test('fallback title is the site name', () => {
    expect(fallbackTitle('https://www.boutique.example/carnet?ref=1')).toBe('boutique.example')
  })
})
