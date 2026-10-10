import { readFileSync, readdirSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import { SUPPORTED_LOCALES } from '@/lib/locales'

const { state } = vi.hoisted(() => ({ state: { locale: 'en' } }))
const testDirectory = dirname(fileURLToPath(import.meta.url))

vi.mock('next-intl/server', () => ({
  getLocale: async () => state.locale,
  getTranslations: async (namespace: string) => {
    const catalog = JSON.parse(
      readFileSync(
        join(testDirectory, '../../messages', `${state.locale}.json`),
        'utf8'
      )
    )
    const messages = namespace
      .split('.')
      .reduce((value, key) => value[key], catalog)
    return (key: string) => {
      if (typeof messages[key] !== 'string')
        throw new Error(`Missing ${namespace}.${key}`)
      return messages[key]
    }
  },
}))

vi.mock('next/font/google', () => ({
  Geist: () => ({ variable: 'geist' }),
  Geist_Mono: () => ({ variable: 'mono' }),
  Noto_Sans_JP: () => ({ variable: 'ja' }),
  Noto_Sans_KR: () => ({ variable: 'ko' }),
  Noto_Sans_SC: () => ({ variable: 'zh' }),
}))

import { metadata as rootMetadata } from '@/app/layout'
import { generateMetadata as homeMetadata } from '@/app/page'
import { generateMetadata as privacyMetadata } from '@/app/(legal)/privacy/layout'
import { generateMetadata as termsMetadata } from '@/app/(legal)/terms/layout'
import { generateMetadata as loginMetadata } from '@/app/(auth)/login/layout'
import { generateMetadata as registerMetadata } from '@/app/(auth)/register/layout'
import { generateMetadata as forgotMetadata } from '@/app/(auth)/forgot-password/layout'
import { generateMetadata as resetMetadata } from '@/app/(auth)/reset-password/layout'
import { generateMetadata as verifyMetadata } from '@/app/(auth)/verify-email/layout'
import robots from '@/app/robots'
import sitemap from '@/app/sitemap'

const publicMetadata = [homeMetadata, privacyMetadata, termsMetadata]

describe('public SEO contract', () => {
  it.each(SUPPORTED_LOCALES)(
    'keeps canonical, sitemap and localized social metadata coherent in %s',
    async (locale) => {
      state.locale = locale
      const pages = await Promise.all(
        publicMetadata.map((generate) => generate())
      )
      const urls = pages.map((page) => page.alternates?.canonical)
      expect(urls).toEqual(sitemap().map((entry) => entry.url))
      expect(new Set(urls).size).toBe(3)
      expect(new Set(pages.map((page) => page.description)).size).toBe(3)
      expect(
        new Set(pages.map((page) => JSON.stringify(page.title))).size
      ).toBe(3)
      for (const page of pages) {
        expect(page.robots).toEqual({ index: true, follow: true })
        expect(page.alternates?.languages).toBeUndefined()
        expect(page.openGraph).toMatchObject({
          url: page.alternates?.canonical,
          description: page.description,
          siteName: 'FreeLingo',
          locale: expect.stringMatching(new RegExp(`^${locale}_[A-Z]{2}$`)),
        })
        expect(page.twitter).toMatchObject({
          card: 'summary_large_image',
          description: page.description,
        })
        expect(String(page.alternates?.canonical)).not.toMatch(/[?#]/)
      }
    }
  )

  it('excludes account pages and avoids fabricated sitemap freshness', async () => {
    expect(String(rootMetadata.metadataBase)).toBe('https://freelingo.app/')
    expect(rootMetadata.robots).toEqual({ index: false, follow: true })
    expect(rootMetadata.alternates).toBeUndefined()
    expect(rootMetadata.openGraph).toBeUndefined()
    state.locale = 'es'
    const accountPages = await Promise.all(
      [
        loginMetadata,
        registerMetadata,
        forgotMetadata,
        resetMetadata,
        verifyMetadata,
      ].map((generate) => generate())
    )
    for (const page of accountPages) {
      expect(page.title).toBeTruthy()
      expect(page.robots).toBeUndefined() // Inherits the root noindex.
      expect(page.alternates).toBeUndefined()
    }
    expect(new Set(accountPages.map((page) => page.title)).size).toBe(5)
    expect(sitemap().map((entry) => new URL(entry.url).pathname)).toEqual([
      '/',
      '/privacy',
      '/terms',
    ])
    for (const entry of sitemap()) {
      expect(entry.lastModified).toBeUndefined()
      expect(entry.changeFrequency).toBeUndefined()
      expect(entry.priority).toBeUndefined()
    }
  })

  it('lets crawlers reach every HTML route to see noindex or the existing auth redirect', () => {
    const app = join(testDirectory, '../src/app')
    const pagePaths = readdirSync(app, { recursive: true, encoding: 'utf8' })
      .filter((file) => file.endsWith('page.tsx'))
      .map(
        (file) =>
          '/' +
          file
            .split('/')
            .filter((part) => !part.startsWith('(') && part !== 'page.tsx')
            .join('/')
      )
    const rules = robots().rules
    expect(Array.isArray(rules)).toBe(true)
    const rule = Array.isArray(rules) ? rules[0] : rules
    expect(rule.userAgent).toBe('*')
    expect(rule.allow).toBe('/')
    const disallowed = Array.isArray(rule.disallow)
      ? rule.disallow
      : [rule.disallow]
    for (const path of pagePaths) {
      expect(
        disallowed.some((prefix) => prefix && path.startsWith(prefix))
      ).toBe(false)
    }
    expect(robots().sitemap).toBe('https://freelingo.app/sitemap.xml')
    expect(disallowed).toContain('/api/')
    const files = readdirSync(app, {
      recursive: true,
      encoding: 'utf8',
    }).filter((file) => /\.(ts|tsx)$/.test(file))
    const optIns = files.filter((file) =>
      /createPublicMetadata\(/.test(readFileSync(join(app, file), 'utf8'))
    )
    expect(optIns.sort()).toEqual([
      '(legal)/privacy/layout.tsx',
      '(legal)/terms/layout.tsx',
      'page.tsx',
    ])
  })
})
