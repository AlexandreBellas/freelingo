'use client'

import { useEffect, useId, useRef, useState } from 'react'
import { useTranslations } from 'next-intl'
import { Check, Pause, Pencil, Play, Trash2 } from 'lucide-react'
import { apiFetch } from '@/lib/api'
import { ConfirmDialog } from '@/components/ui/confirm-dialog'
import { useFlashcardScope } from './FlashcardBoundary'
import type { Flashcard } from './types'

interface Props {
  card: Flashcard
  disabled?: boolean
  onUpdate: (card: Flashcard) => void
  onDelete: (id: number) => void
  onInteractionChange?: (active: boolean) => void
}

export function FlashcardActions({
  card,
  disabled = false,
  onUpdate,
  onDelete,
  onInteractionChange,
}: Props) {
  const t = useTranslations('flashcards')
  const common = useTranslations('common')
  const isCurrent = useFlashcardScope()
  const [mode, setMode] = useState<'edit' | 'delete' | null>(null)
  const [pending, setPending] = useState(false)
  const pendingRef = useRef(false)
  const [error, setError] = useState('')
  const [draft, setDraft] = useState({
    word: card.word,
    definition: card.definition,
    example_sentence: card.example_sentence,
    translation: card.translation,
  })
  const firstInput = useRef<HTMLInputElement>(null)
  const editButton = useRef<HTMLButtonElement>(null)
  const previousMode = useRef<typeof mode>(null)
  const id = useId()

  useEffect(() => {
    if (mode === 'edit') firstInput.current?.focus()
    else if (previousMode.current === 'edit') editButton.current?.focus()
    previousMode.current = mode
  }, [mode])

  function close() {
    if (pendingRef.current) return
    setMode(null)
    setError('')
    onInteractionChange?.(false)
  }

  async function mutate(action: 'edit' | 'delete' | 'suspend') {
    if (pendingRef.current || disabled || !isCurrent()) return
    pendingRef.current = true
    setPending(true)
    setError('')
    onInteractionChange?.(true)
    try {
      const response = await apiFetch(
        `/api/flashcards/${card.id}${action === 'suspend' ? '/suspension' : ''}`,
        {
          method: action === 'delete' ? 'DELETE' : 'PUT',
          ...(action !== 'delete'
            ? {
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(
                  action === 'edit'
                    ? draft
                    : { is_suspended: !card.is_suspended }
                ),
              }
            : {}),
        }
      )
      if (!response.ok) {
        const data = await response.json().catch(() => ({}))
        throw new Error(
          data.detail === 'flashcard_duplicate' ? 'duplicate' : 'failed'
        )
      }
      const updated: Flashcard | null =
        action === 'delete' ? null : await response.json()
      if (!isCurrent()) return
      setMode(null)
      onInteractionChange?.(false)
      if (updated) onUpdate(updated)
      else onDelete(card.id)
    } catch (err) {
      if (isCurrent()) {
        setError(
          err instanceof Error && err.message === 'duplicate'
            ? t('duplicateWord')
            : common('errorMessage')
        )
        if (action === 'suspend') onInteractionChange?.(false)
      }
    } finally {
      pendingRef.current = false
      if (isCurrent()) setPending(false)
    }
  }

  const buttonClass =
    'border-fl-border text-fl-muted-1 hover:bg-fl-surface-2 hover:text-fl-fg focus-visible:outline-fl-accent inline-flex min-h-11 items-center justify-center gap-2 border px-3 py-2 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-40'
  const inputClass =
    'border-fl-border bg-fl-bg text-fl-fg focus-visible:outline-fl-accent min-h-11 w-full border px-3 py-2 font-sans text-base focus-visible:outline-2 focus-visible:outline-offset-2 disabled:opacity-50'

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap gap-2">
        <button
          ref={editButton}
          disabled={disabled || pending || mode !== null}
          className={buttonClass}
          onClick={() => {
            setDraft({
              word: card.word,
              definition: card.definition,
              example_sentence: card.example_sentence,
              translation: card.translation,
            })
            setError('')
            setMode('edit')
            onInteractionChange?.(true)
          }}
        >
          <Pencil className="size-4 shrink-0" aria-hidden="true" />
          {t('editCard')}
        </button>
        <button
          disabled={disabled || pending || mode !== null}
          className={buttonClass}
          onClick={() => void mutate('suspend')}
        >
          {card.is_suspended ? (
            <Play className="size-4 shrink-0" aria-hidden="true" />
          ) : (
            <Pause className="size-4 shrink-0" aria-hidden="true" />
          )}
          {card.is_suspended ? t('reactivate') : t('suspend')}
        </button>
        <button
          disabled={disabled || pending || mode !== null}
          className={buttonClass}
          onClick={() => {
            setError('')
            setMode('delete')
            onInteractionChange?.(true)
          }}
        >
          <Trash2 className="size-4 shrink-0" aria-hidden="true" />
          {common('delete')}
        </button>
      </div>
      {mode === 'edit' && (
        <form
          className="border-fl-border bg-fl-bg space-y-4 border p-4 sm:p-5"
          aria-label={t('editCard')}
          onSubmit={(event) => {
            event.preventDefault()
            void mutate('edit')
          }}
        >
          {(
            ['word', 'definition', 'translation', 'example_sentence'] as const
          ).map((field) => (
            <div key={field}>
              <label
                className="text-fl-muted-1 mb-1 block text-sm"
                htmlFor={`${id}-${field}`}
              >
                {t(`field_${field}`)}
              </label>
              {field === 'word' || field === 'translation' ? (
                <input
                  ref={field === 'word' ? firstInput : undefined}
                  id={`${id}-${field}`}
                  value={draft[field]}
                  maxLength={field === 'word' ? 100 : 255}
                  required={field === 'word'}
                  disabled={pending}
                  className={inputClass}
                  onChange={(event) =>
                    setDraft({ ...draft, [field]: event.target.value })
                  }
                />
              ) : (
                <textarea
                  id={`${id}-${field}`}
                  value={draft[field]}
                  maxLength={10000}
                  rows={3}
                  required={field === 'definition'}
                  disabled={pending}
                  className={inputClass}
                  onChange={(event) =>
                    setDraft({ ...draft, [field]: event.target.value })
                  }
                />
              )}
            </div>
          ))}
          <p className="text-fl-muted-2 text-sm">{t('editKeepsProgress')}</p>
          {error && (
            <p role="alert" className="text-fl-error-fg text-sm">
              {error}
            </p>
          )}
          <div className="flex flex-wrap gap-2">
            <button
              type="submit"
              disabled={
                pending || !draft.word.trim() || !draft.definition.trim()
              }
              className="bg-fl-accent text-fl-accent-fg hover:bg-fl-accent/90 focus-visible:outline-fl-accent inline-flex min-h-11 items-center justify-center gap-2 px-4 py-2 text-sm font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-40"
            >
              <Check className="size-4 shrink-0" aria-hidden="true" />
              {pending ? common('saving') : common('save')}
            </button>
            <button
              type="button"
              disabled={pending}
              className={buttonClass}
              onClick={close}
            >
              {common('cancel')}
            </button>
          </div>
        </form>
      )}
      {mode === null && error && (
        <p role="alert" className="text-fl-error-fg text-sm">
          {error}
        </p>
      )}
      <ConfirmDialog
        open={mode === 'delete'}
        title={t('deleteTitle')}
        message={t('deleteMessage', { word: card.word })}
        confirmLabel={common('delete')}
        danger
        confirming={pending}
        error={error}
        onConfirm={() => void mutate('delete')}
        onCancel={close}
      />
    </div>
  )
}
