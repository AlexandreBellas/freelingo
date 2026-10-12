'use client'

import { useState } from 'react'
import { useTranslations } from 'next-intl'
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
    'border-fl-border text-fl-fg hover:bg-fl-surface-2 border px-4 py-2 text-sm disabled:opacity-40'
  return (
    <div className="border-fl-border bg-fl-surface border">
      <div className="border-fl-border flex flex-wrap items-center justify-between gap-2 border-b px-6 py-4">
        <span className="text-fl-muted-1 text-sm">{t('speakingMode')}</span>
        <span className="text-fl-muted-1 text-sm">{t('sayWord')}</span>
      </div>
      <div className="flex flex-col items-center gap-5 p-6 text-center sm:p-10">
        <TargetLanguageText
          as="p"
          languageCode={languageCode}
          className="text-fl-fg-2"
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
          <div className="w-full space-y-4">
            <p role="status" className="text-fl-fg font-medium">
              {t(result.correct ? 'speechMatch' : 'speechMismatch')}
            </p>
            <dl className="space-y-3">
              <div>
                <dt className="text-fl-muted-2 text-sm">{t('speechHeard')}</dt>
                <TargetLanguageText
                  as="dd"
                  languageCode={languageCode}
                  className="text-fl-fg"
                >
                  {result.text}
                </TargetLanguageText>
              </div>
              <div>
                <dt className="text-fl-muted-2 text-sm">
                  {t('speechExpected')}
                </dt>
                <dd className="flex items-center justify-center gap-2">
                  <TargetLanguageText
                    languageCode={languageCode}
                    className="text-fl-fg font-bold"
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
            <p className="text-fl-muted-2 text-sm">{t('speechConfirmHint')}</p>
            <div className="flex flex-wrap justify-center gap-2">
              <button
                disabled={disabled}
                className={buttonClass}
                onClick={() => void onReview(result.correct ? 5 : 2)}
              >
                {t('speechContinue')}
              </button>
              <button
                disabled={disabled}
                className={buttonClass}
                onClick={() => setResult(null)}
              >
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
            <VoiceRecorder
              studyPlanId={card.study_plan_id}
              onTranscription={transcribed}
              maxSeconds={5}
              disabled={disabled}
              onBusyChange={onBusyChange}
            />
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
