'use client'

import { useEffect, useRef, useState } from 'react'
import { useTranslations } from 'next-intl'
import { apiFetch } from '@/lib/api'
import { useFlashcardScope } from './FlashcardBoundary'
import type { BackContent } from './types'

export function BackPreference({
  value,
  onChange,
}: {
  value: BackContent
  onChange: (value: BackContent) => void
}) {
  const t = useTranslations('flashcards')
  const common = useTranslations('common')
  const isCurrent = useFlashcardScope()
  const [loading, setLoading] = useState(true)
  const [loaded, setLoaded] = useState(false)
  const [error, setError] = useState(false)
  const [attempt, setAttempt] = useState(0)
  const pending = useRef(false)
  useEffect(() => {
    const controller = new AbortController()
    void (async () => {
      try {
        const res = await apiFetch('/api/flashcards/preferences', {
          signal: controller.signal,
        })
        if (!res.ok) throw new Error('Preferences unavailable')
        const data = await res.json()
        if (!isCurrent() || controller.signal.aborted) return
        onChange(data.back_content)
        setLoaded(true)
        setError(false)
      } catch {
        if (isCurrent() && !controller.signal.aborted) setError(true)
      } finally {
        if (isCurrent() && !controller.signal.aborted) setLoading(false)
      }
    })()
    return () => controller.abort()
  }, [attempt, isCurrent, onChange])

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
      if (isCurrent()) onChange(next)
    } catch {
      if (isCurrent()) setError(true)
    } finally {
      pending.current = false
      if (isCurrent()) setLoading(false)
    }
  }

  return (
    <div className="space-y-2">
      <label className="text-fl-muted-1 flex flex-wrap items-center gap-3 text-sm">
        {t('backPreference')}
        <select
          value={value}
          disabled={loading || !loaded}
          onChange={(event) => void save(event.target.value as BackContent)}
          className="border-fl-border bg-fl-bg text-fl-fg border px-3 py-2 disabled:opacity-50"
        >
          <option value="definition">{t('field_definition')}</option>
          <option value="translation">{t('field_translation')}</option>
        </select>
      </label>
      {error && (
        <div className="flex items-center gap-3 text-sm">
          <p role="alert" className="text-fl-error-fg">
            {common('errorMessage')}
          </p>
          <button
            className="underline"
            onClick={() => {
              setLoading(true)
              setAttempt((n) => n + 1)
            }}
          >
            {common('retry')}
          </button>
        </div>
      )}
    </div>
  )
}
