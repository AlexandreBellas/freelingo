'use client'

import { useEffect, useRef, useState } from 'react'
import { useTranslations } from 'next-intl'
import { apiFetch } from '@/lib/api'
import { useFlashcardScope } from './FlashcardBoundary'
import type { BackContent } from './types'

// The page owns requests, including when the standard-mode selector is hidden.
export function useBackPreference(enabled: boolean) {
  const isCurrent = useFlashcardScope()
  const [value, setValue] = useState<BackContent>('definition')
  const [loading, setLoading] = useState(true)
  const [loaded, setLoaded] = useState(false)
  const [error, setError] = useState(false)
  const [attempt, setAttempt] = useState(0)
  const pending = useRef(false)
  const loadedRef = useRef(false)
  useEffect(() => {
    if (!enabled || loadedRef.current) return
    const controller = new AbortController()
    void (async () => {
      try {
        const res = await apiFetch('/api/flashcards/preferences', {
          signal: controller.signal,
        })
        if (!res.ok) throw new Error('Preferences unavailable')
        const data = await res.json()
        if (!isCurrent() || controller.signal.aborted) return
        setValue(data.back_content)
        loadedRef.current = true
        setLoaded(true)
        setError(false)
      } catch {
        if (isCurrent() && !controller.signal.aborted) setError(true)
      } finally {
        if (isCurrent() && !controller.signal.aborted) setLoading(false)
      }
    })()
    return () => controller.abort()
  }, [attempt, enabled, isCurrent])

  async function save(next: BackContent) {
    if (pending.current || !isCurrent()) return
    pending.current = true
    setLoading(true)
    setError(false)
    try {
      const res = await apiFetch('/api/flashcards/preferences', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ back_content: next }),
      })
      if (!res.ok) throw new Error('Could not save preference')
      if (isCurrent()) setValue(next)
    } catch {
      if (isCurrent()) setError(true)
    } finally {
      pending.current = false
      if (isCurrent()) setLoading(false)
    }
  }

  function retry() {
    if (pending.current) return
    loadedRef.current = false
    setLoading(true)
    setAttempt((n) => n + 1)
  }

  return { value, loading, loaded, error, save, retry }
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
