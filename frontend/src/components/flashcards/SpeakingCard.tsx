'use client'

import { useState } from 'react'
import { useTranslations } from 'next-intl'
import { Check, CircleHelp, Mic, RotateCcw } from 'lucide-react'
import { TargetLanguageText } from '@/components/TargetLanguageText'
import { AudioPlayer } from '@/components/ui/AudioPlayer'
import { VoiceRecorder } from '@/components/ui/VoiceRecorder'
import {
  maskFlashcardExample,
  normalizeFlashcardSpeech,
} from '@/lib/flashcard-review'
import { useFlashcardScope } from './FlashcardBoundary'
import type { Flashcard } from './types'

export function SpeakingCard({
  card,
  languageCode,
  disabled,
  onBusyChange,
  onReview,
}: {
  card: Flashcard
  languageCode: string
  disabled: boolean
  onBusyChange: (busy: boolean) => void
  onReview: (quality: number) => Promise<void>
}) {
  const t = useTranslations('flashcards')
  const common = useTranslations('common')
  const isCurrent = useFlashcardScope()
  const [result, setResult] = useState<{
    text: string
    correct: boolean
  } | null>(null)
  const [empty, setEmpty] = useState(false)
  const example = result
    ? card.example_sentence
    : maskFlashcardExample(card.example_sentence, card.word)

  function transcribed(text: string) {
    if (!isCurrent()) return
    if (!normalizeFlashcardSpeech(text)) {
      setEmpty(true)
      return
    }
    setEmpty(false)
    setResult({
      text,
      correct:
        normalizeFlashcardSpeech(text) === normalizeFlashcardSpeech(card.word),
    })
  }

  const buttonClass =
    'border-fl-border text-fl-fg hover:bg-fl-surface-2 focus-visible:outline-fl-accent inline-flex min-h-11 items-center justify-center gap-2 border px-4 py-2 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-40'
  return (
    <div className="border-fl-accent/35 bg-fl-surface border">
      <div className="border-fl-border flex flex-wrap items-center justify-between gap-2 border-b px-6 py-4">
        <span className="text-fl-muted-1 inline-flex items-center gap-2 text-sm font-medium">
          <Mic className="text-fl-accent size-4 shrink-0" aria-hidden="true" />
          {t('speakingMode')}
        </span>
        <span className="text-fl-muted-1 text-sm">{t('sayWord')}</span>
      </div>
      <div className="flex flex-col items-center gap-5 p-6 text-center sm:p-10">
        <TargetLanguageText
          as="p"
          languageCode={languageCode}
          className="text-fl-fg-2 max-w-full [overflow-wrap:anywhere] break-words"
        >
          {card.definition}
        </TargetLanguageText>
        {example && (
          <TargetLanguageText
            as="p"
            languageCode={languageCode}
            className="text-fl-muted-1 italic"
          >
            {example}
          </TargetLanguageText>
        )}
        {card.translation && (
          <p className="border-fl-border text-fl-muted-1 border-t pt-3 font-sans leading-relaxed">
            {card.translation}
          </p>
        )}
        {result ? (
          <div className="border-fl-border w-full space-y-5 border-t pt-5">
            <p
              role="status"
              className="text-fl-fg flex items-center justify-center gap-2 font-medium"
            >
              {result.correct ? (
                <Check
                  className="text-fl-accent size-5 shrink-0"
                  aria-hidden="true"
                />
              ) : (
                <CircleHelp
                  className="text-fl-muted-1 size-5 shrink-0"
                  aria-hidden="true"
                />
              )}
              {t(result.correct ? 'speechMatch' : 'speechMismatch')}
            </p>
            <dl className="grid gap-3 text-left sm:grid-cols-2">
              <div className="border-fl-border bg-fl-bg min-w-0 space-y-2 border p-4">
                <dt className="text-fl-muted-2 text-sm">{t('speechHeard')}</dt>
                <TargetLanguageText
                  as="dd"
                  languageCode={languageCode}
                  className="text-fl-fg [overflow-wrap:anywhere] break-words"
                >
                  {result.text}
                </TargetLanguageText>
              </div>
              <div className="border-fl-accent/35 bg-fl-accent/10 min-w-0 space-y-2 border p-4">
                <dt className="text-fl-muted-2 text-sm">
                  {t('speechExpected')}
                </dt>
                <dd className="flex items-center gap-2">
                  <TargetLanguageText
                    languageCode={languageCode}
                    className="text-fl-fg min-w-0 font-semibold [overflow-wrap:anywhere] break-words"
                  >
                    {card.word}
                  </TargetLanguageText>
                  <AudioPlayer
                    text={card.word}
                    studyPlanId={card.study_plan_id}
                  />
                </dd>
              </div>
            </dl>
            <p className="text-fl-muted-2 text-sm leading-relaxed">
              {t('speechConfirmHint')}
            </p>
            <div className="flex flex-col justify-center gap-2 sm:flex-row sm:flex-wrap">
              <button
                disabled={disabled}
                className="bg-fl-accent text-fl-accent-fg hover:bg-fl-accent/90 focus-visible:outline-fl-accent inline-flex min-h-11 items-center justify-center gap-2 px-4 py-3 text-sm font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:opacity-40"
                onClick={() => void onReview(result.correct ? 5 : 2)}
              >
                <Check className="size-4 shrink-0" aria-hidden="true" />
                {t('speechContinue')}
              </button>
              <button
                disabled={disabled}
                className={buttonClass}
                onClick={() => setResult(null)}
              >
                <RotateCcw className="size-4 shrink-0" aria-hidden="true" />
                {common('retry')}
              </button>
              {!result.correct && (
                <button
                  disabled={disabled}
                  className={buttonClass}
                  onClick={() => void onReview(5)}
                >
                  {t('speechOverride')}
                </button>
              )}
            </div>
          </div>
        ) : (
          <>
            <div className="[&_button]:focus-visible:outline-fl-accent [&_button]:px-5 [&_button]:py-3 [&_button]:text-sm [&_button]:tracking-normal [&_button]:normal-case [&_button]:focus-visible:outline-2 [&_button]:focus-visible:outline-offset-2">
              <VoiceRecorder
                studyPlanId={card.study_plan_id}
                onTranscription={transcribed}
                maxSeconds={5}
                disabled={disabled}
                onBusyChange={onBusyChange}
                className="min-h-11"
              />
            </div>
            {empty && (
              <p role="alert" className="text-fl-error-fg text-sm">
                {t('speechEmpty')}
              </p>
            )}
          </>
        )}
      </div>
    </div>
  )
}
