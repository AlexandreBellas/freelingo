'use client'

import {
  Fragment,
  useCallback,
  useEffect,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import { useTranslations } from 'next-intl'
import { useLanguageStore } from '@/store/language'
import { useAuthStore } from '@/store/auth'
import { PageLoading } from '@/components/ui/page-loading'
import NoPlanBanner from '@/components/plan/NoPlanBanner'

export function FlashcardError({ onRetry }: { onRetry: () => void }) {
  const t = useTranslations('common')
  return (
    <div className="space-y-3 p-6 text-center">
      <p role="alert" className="text-fl-error-fg">
        {t('errorMessage')}
      </p>
      <button
        className="border-fl-border text-fl-fg border px-4 py-2 text-sm"
        onClick={onRetry}
      >
        {t('retry')}
      </button>
    </div>
  )
}

function LanguageRecovery() {
  const fetchLanguages = useLanguageStore((s) => s.fetchLanguages)
  const [loading, setLoading] = useState(true)
  const [failed, setFailed] = useState(false)
  const [attempt, setAttempt] = useState(0)
  useEffect(() => {
    const controller = new AbortController()
    void fetchLanguages(controller.signal).then((ok) => {
      if (controller.signal.aborted) return
      setFailed(!ok)
      setLoading(false)
    })
    return () => controller.abort()
  }, [fetchLanguages, attempt])
  if (loading) return <PageLoading />
  if (failed)
    return (
      <FlashcardError
        onRetry={() => {
          setLoading(true)
          setAttempt((value) => value + 1)
        }}
      />
    )
  return <NoPlanBanner />
}

export function FlashcardBoundary({
  children,
}: {
  children: (code: string) => ReactNode
}) {
  const activeLanguage = useLanguageStore((s) => s.activeLanguage)
  const needsRefresh = useLanguageStore((s) => s.needsRefresh)
  const isSwitching = useLanguageStore((s) => s.isSwitching)
  const version = useLanguageStore((s) => s.invalidationVersion)
  const planId = useLanguageStore(
    (s) =>
      s.userLanguages.find(
        (language) => language.target_language === s.activeLanguage?.code
      )?.plan?.id
  )
  const sessionVersion = useAuthStore((s) => s.sessionVersion)
  if (isSwitching) return <PageLoading />
  if (needsRefresh || !activeLanguage) return <LanguageRecovery />
  return (
    <Fragment
      key={`${sessionVersion}:${activeLanguage.code}:${planId}:${version}`}
    >
      {children(activeLanguage.code)}
    </Fragment>
  )
}

// Check the store too: a response can settle before React unmounts the old language.
export function useFlashcardScope() {
  const code = useLanguageStore((s) => s.activeLanguage?.code)
  const version = useLanguageStore((s) => s.invalidationVersion)
  const sessionVersion = useAuthStore((s) => s.sessionVersion)
  const planId = useLanguageStore(
    (s) =>
      s.userLanguages.find(
        (language) => language.target_language === s.activeLanguage?.code
      )?.plan?.id
  )
  const mounted = useRef(true)
  useEffect(() => {
    mounted.current = true
    return () => {
      mounted.current = false
    }
  }, [])
  return useCallback(() => {
    const language = useLanguageStore.getState()
    return (
      mounted.current &&
      useAuthStore.getState().sessionVersion === sessionVersion &&
      language.activeLanguage?.code === code &&
      language.invalidationVersion === version &&
      language.userLanguages.find((item) => item.target_language === code)?.plan
        ?.id === planId &&
      !language.isSwitching &&
      !language.needsRefresh
    )
  }, [code, version, sessionVersion, planId])
}
