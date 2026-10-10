import type { Metadata } from 'next'
import type { ReactNode } from 'react'
import { getTranslations } from 'next-intl/server'

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations('auth.forgotPassword')
  return { title: t('title') }
}

export default function ForgotPasswordLayout({
  children,
}: {
  children: ReactNode
}) {
  return children
}
