import { createTranslator } from 'next-intl'
import { describe, expect, it } from 'vitest'
import { SUPPORTED_LOCALES } from '@/lib/locales'
import en from '../../../messages/en.json'

describe.each(SUPPORTED_LOCALES)('landing review messages in %s', (locale) => {
  it('renders rating plurals and accessible carousel controls', async () => {
    const messages = (await import(`../../../messages/${locale}.json`)).default
    const t = createTranslator({
      locale,
      messages,
      namespace: 'landingReviews',
      onError: (error) => {
        throw error
      },
    })
    expect(Object.keys(messages.landingReviews).sort()).toEqual(
      Object.keys(en.landingReviews).sort()
    )
    for (const key of Object.keys(en.landingReviews)) {
      for (const count of [0, 1, 2, 5, 21, 128]) {
        const text = t(key, {
          count,
          average: '4.9',
          rating: 5,
          language: 'English',
        })
        expect(text.trim()).not.toBe('')
        expect(text).not.toContain('landingReviews.')
        expect(text).not.toMatch(/[{}]/)
      }
    }
  })
})
