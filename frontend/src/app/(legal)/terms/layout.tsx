import type { Metadata } from 'next'
import type { ReactNode } from 'react'
import { getLocale, getTranslations } from 'next-intl/server'
import { createPublicMetadata } from '@/lib/seo'

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations('legal.terms')
  return createPublicMetadata({
    path: '/terms',
    title: `${t('pageTitle')} | FreeLingo`,
    description: t('seoDescription'),
    locale: await getLocale(),
  })
}

export default function TermsLayout({ children }: { children: ReactNode }) {
  return children
}
