'use client'

import { useEffect, useMemo } from 'react'
import { useStore } from 'zustand'
import { useTranslations } from 'next-intl'
import { useAuthStore } from '@/store/auth'
import { getFlashcardPreferences } from '@/store/flashcard-preferences'
import type { BackContent } from './types'

// Account-owned requests survive language boundaries and page navigation.
export function useBackPreference(enabled: boolean) {
  const sessionVersion = useAuthStore((state) => state.sessionVersion)
  const store = useMemo(
    () => getFlashcardPreferences(sessionVersion),
    [sessionVersion]
  )
  const preference = useStore(store)
  const { load } = preference
  useEffect(() => {
    if (enabled) void load()
  }, [enabled, load])
  return { ...preference, retry: load }
}

export function BackPreference({
  preference,
}: {
  preference: ReturnType<typeof useBackPreference>
}) {
  const t = useTranslations('flashcards')
  const common = useTranslations('common')
  const { value, loading, loaded, error, save, retry } = preference

  return (
    <div className="min-w-0 space-y-2">
      <label className="text-fl-muted-1 flex flex-col gap-2 text-sm">
        {t('backPreference')}
        <select
          value={value}
          disabled={loading || !loaded}
          onChange={(event) => void save(event.target.value as BackContent)}
          className="border-fl-border bg-fl-bg text-fl-fg focus-visible:outline-fl-accent min-h-11 max-w-full border px-3 py-2 text-base focus-visible:outline-2 focus-visible:outline-offset-2 disabled:opacity-40"
        >
          <option value="definition">{t('field_definition')}</option>
          <option value="translation">{t('field_translation')}</option>
        </select>
      </label>
      {error && (
        <div className="flex flex-wrap items-center gap-3 text-sm">
          <p role="alert" className="text-fl-error-fg">
            {common('errorMessage')}
          </p>
          <button
            className="focus-visible:outline-fl-accent min-h-11 px-1 underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-2"
            onClick={retry}
          >
            {common('retry')}
          </button>
        </div>
      )}
    </div>
  )
}
