'use client'

import { useEffect, useRef, useState, useCallback } from 'react'
import Link from 'next/link'
import { useTranslations } from 'next-intl'
import { apiFetch } from '@/lib/api'
import { AudioPlayer } from '@/components/ui/AudioPlayer'
import { VoiceRecorder } from '@/components/ui/VoiceRecorder'
import { PageLoading } from '@/components/ui/page-loading'
import { TargetLanguageText } from '@/components/TargetLanguageText'
import { CEFR_LEVELS } from '@/data/curriculum'
import { trackBrowserEvent } from '@/lib/analytics'
import { useAuthStore } from '@/store/auth'
import {
  FlashcardBoundary,
  FlashcardError,
  useFlashcardScope,
} from '@/components/flashcards/FlashcardBoundary'
import { FlashcardActions } from '@/components/flashcards/FlashcardActions'
import { BackPreference } from '@/components/flashcards/BackPreference'
import type { BackContent, Flashcard } from '@/components/flashcards/types'
import NoPlanBanner from '@/components/plan/NoPlanBanner'

export default function FlashcardsPage() {
  return (
    <FlashcardBoundary>
      {(code) => <FlashcardsContent targetLanguageCode={code} />}
    </FlashcardBoundary>
  )
}

function FlashcardsContent({
  targetLanguageCode,
}: {
  targetLanguageCode: string
}) {
  const t = useTranslations('flashcards')
  const tCommon = useTranslations('common')
  const isCurrent = useFlashcardScope()
  const [cards, setCards] = useState<Flashcard[]>([])
  const [current, setCurrent] = useState(0)
  const [flipped, setFlipped] = useState(false)
  const [loading, setLoading] = useState(true)
  const [total, setTotal] = useState(0)
  const [showGenerate, setShowGenerate] = useState(false)
  const [genTopic, setGenTopic] = useState('')
  const [genCount, setGenCount] = useState(10)
  const [genCefr, setGenCefr] = useState('B1')
  const [generating, setGenerating] = useState(false)
  const [genError, setGenError] = useState('')
  const [speakingMode, setSpeakingMode] = useState(false)
  const [reviewing, setReviewing] = useState(false)
  const reviewingRef = useRef(false)
  const sessionRecorded = useRef(false)
  const loadRequest = useRef<AbortController | null>(null)
  const [loadError, setLoadError] = useState(false)
  const [noPlan, setNoPlan] = useState(false)
  const [reviewError, setReviewError] = useState(false)
  const [managing, setManaging] = useState(false)
  const [recording, setRecording] = useState(false)
  const [backContent, setBackContent] = useState<BackContent>('definition')
  const generatingRef = useRef(false)

  const loadDue = useCallback(async () => {
    if (!isCurrent()) return
    loadRequest.current?.abort()
    const controller = new AbortController()
    loadRequest.current = controller
    setLoading(true)
    setLoadError(false)
    setReviewError(false)
    try {
      const res = await apiFetch('/api/flashcards/due', {
        signal: controller.signal,
      })
      if (res.status === 404) {
        if (isCurrent() && !controller.signal.aborted) setNoPlan(true)
        return
      }
      if (!res.ok) throw new Error('Could not load flashcards')
      const data = await res.json()
      if (isCurrent() && !controller.signal.aborted) {
        setNoPlan(false)
        setCards(data.due)
        setTotal(data.total)
        setCurrent(0)
        setFlipped(false)
        sessionRecorded.current = false
      }
    } catch {
      if (isCurrent() && !controller.signal.aborted) setLoadError(true)
    } finally {
      if (isCurrent() && !controller.signal.aborted) setLoading(false)
    }
  }, [isCurrent])

  useEffect(() => {
    void loadDue()
    return () => loadRequest.current?.abort()
  }, [loadDue])

  async function reviewCard(quality: number) {
    if (
      cards.length === 0 ||
      reviewingRef.current ||
      managing ||
      generatingRef.current ||
      !isCurrent()
    )
      return
    reviewingRef.current = true
    setReviewing(true)
    setReviewError(false)
    const card = cards[current]
    const sessionVersion = useAuthStore.getState().sessionVersion
    try {
      const response = await apiFetch(`/api/flashcards/${card.id}/review`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ quality }),
      })
      if (!response.ok) throw new Error('Could not review flashcard')
      if (!isCurrent()) return
      if (!sessionRecorded.current)
        sessionRecorded.current = trackBrowserEvent(
          'flashcard_session_started',
          { sessionVersion }
        )
      if (current < cards.length - 1) {
        setCurrent(current + 1)
        setFlipped(false)
      } else {
        await loadDue()
      }
    } catch {
      if (isCurrent()) setReviewError(true)
    } finally {
      reviewingRef.current = false
      if (isCurrent()) setReviewing(false)
    }
  }

  async function handleSpeakingTranscription(transcription: string) {
    if (cards.length === 0) return
    const card = cards[current]
    const norm = (s: string) =>
      s
        .trim()
        .toLowerCase()
        .replace(/[\p{P}\p{S}\s]+/gu, '')
    const isCorrect = norm(transcription) === norm(card.word)
    await reviewCard(isCorrect ? 5 : 2)
  }

  async function generateCards(e: React.FormEvent) {
    e.preventDefault()
    if (
      !genTopic.trim() ||
      generatingRef.current ||
      reviewingRef.current ||
      managing ||
      recording ||
      !isCurrent()
    )
      return
    generatingRef.current = true
    setGenerating(true)
    setGenError('')
    try {
      const res = await apiFetch('/api/flashcards/generate', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          topic: genTopic.trim(),
          count: genCount,
          cefr_level: genCefr,
        }),
      })
      if (!res.ok) {
        const data = await res.json().catch(() => ({}))
        throw new Error(data.detail || `Error ${res.status}`)
      }
      if (!isCurrent()) return
      setShowGenerate(false)
      setGenTopic('')
      await loadDue()
    } catch (err: unknown) {
      if (!isCurrent()) return
      const msg = err instanceof Error ? err.message : ''
      setGenError(
        msg === 'No active study plan found'
          ? tCommon('noActivePlan')
          : tCommon('errorMessage')
      )
    } finally {
      generatingRef.current = false
      if (isCurrent()) setGenerating(false)
    }
  }

  if (loading) {
    return <PageLoading />
  }

  if (loadError) return <FlashcardError onRetry={() => void loadDue()} />
  if (noPlan) return <NoPlanBanner />

  function removeCurrent(deleted: boolean) {
    setManaging(false)
    setReviewError(false)
    setFlipped(false)
    if (deleted) setTotal((value) => value - 1)
    if (current >= cards.length - 1) {
      void loadDue()
    } else {
      setCards((items) => items.filter((item) => item.id !== cards[current].id))
    }
  }

  return (
    <div className="mx-auto max-w-4xl space-y-4 p-6">
      {/* Header */}
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <span className="text-fl-label text-fl-muted-3">●</span>
          <span className="text-fl-label text-fl-muted-2 font-mono tracking-widest uppercase">
            {t('title')}
          </span>
          <span className="text-fl-hint text-fl-muted-2 font-mono tracking-widest">
            {total} {t('total')} · {cards.length} {t('due')}
          </span>
        </div>
        <div className="flex items-center gap-2">
          <Link
            href="/flashcards/vocabulary"
            className="text-fl-label border-fl-border text-fl-muted-2 hover:text-fl-fg hover:border-fl-border-2 border px-4 py-2 font-mono tracking-widest uppercase transition-colors"
          >
            {t('myVocabularyBtn')}
          </Link>
          <button
            disabled={reviewing || managing || recording || generating}
            onClick={() => {
              setShowGenerate(!showGenerate)
            }}
            className={`text-fl-label border px-4 py-2 font-mono tracking-widest uppercase transition-colors ${
              showGenerate
                ? 'border-fl-border-2 text-fl-fg'
                : 'border-fl-border text-fl-muted-2 hover:text-fl-fg hover:border-fl-border-2'
            }`}
          >
            + {t('generateBtn')}
          </button>
        </div>
      </div>

      {/* Generate panel */}
      {showGenerate && (
        <div className="border-fl-border bg-fl-surface border">
          <div className="border-fl-border flex items-center gap-2 border-b px-5 py-4">
            <span className="text-fl-label text-fl-muted-3">●</span>
            <span className="text-fl-label text-fl-muted-2 font-mono tracking-widest uppercase">
              {t('generate')}
            </span>
          </div>
          {genError && (
            <div className="border-fl-error/40 text-fl-error-fg mx-5 mt-4 border px-4 py-3 font-mono text-xs">
              ✕ {genError}
            </div>
          )}
          <form onSubmit={generateCards} className="space-y-3 p-5">
            <div>
              <label
                htmlFor="flashcard-topic"
                className="text-fl-muted-3 mb-2 block font-mono text-xs tracking-widest uppercase"
              >
                {t('topic')}
              </label>
              <input
                id="flashcard-topic"
                type="text"
                value={genTopic}
                onChange={(e) => setGenTopic(e.target.value)}
                required
                placeholder={t('topicPlaceholder')}
                className="bg-fl-bg border-fl-border text-fl-fg placeholder:text-fl-border-2 focus:border-fl-border-2 w-full border px-4 py-3 font-mono text-sm transition-colors focus:outline-none"
              />
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label
                  htmlFor="flashcard-count"
                  className="text-fl-muted-3 mb-2 block font-mono text-xs tracking-widest uppercase"
                >
                  {t('count')}
                </label>
                <select
                  id="flashcard-count"
                  value={genCount}
                  onChange={(e) => setGenCount(Number(e.target.value))}
                  className="bg-fl-bg border-fl-border text-fl-fg focus:border-fl-border-2 w-full appearance-none border px-4 py-3 font-mono text-sm focus:outline-none"
                >
                  {[5, 10, 15, 20].map((n) => (
                    <option key={n} value={n}>
                      {n} {t('cards')}
                    </option>
                  ))}
                </select>
              </div>
              <div>
                <label
                  htmlFor="flashcard-level"
                  className="text-fl-muted-3 mb-2 block font-mono text-xs tracking-widest uppercase"
                >
                  {t('level')}
                </label>
                <select
                  id="flashcard-level"
                  value={genCefr}
                  onChange={(e) => setGenCefr(e.target.value)}
                  className="bg-fl-bg border-fl-border text-fl-fg focus:border-fl-border-2 w-full appearance-none border px-4 py-3 font-mono text-sm focus:outline-none"
                >
                  {CEFR_LEVELS.map((l) => (
                    <option key={l} value={l}>
                      {l}
                    </option>
                  ))}
                </select>
              </div>
            </div>
            <button
              type="submit"
              disabled={
                generating ||
                reviewing ||
                managing ||
                recording ||
                !genTopic.trim()
              }
              className="bg-fl-accent text-fl-accent-fg hover:bg-fl-accent/90 w-full py-3 font-mono text-sm font-bold tracking-widest uppercase transition-colors disabled:opacity-40"
            >
              {generating ? t('generating') : t('submit')}
            </button>
          </form>
        </div>
      )}

      {/* No cards */}
      {cards.length === 0 && (
        <div className="border-fl-border bg-fl-surface border px-6 py-10 text-center">
          <p className="text-fl-muted-1 font-mono text-sm">{t('noDue')}</p>
          {total === 0 && (
            <p className="text-fl-muted-2 mt-2 font-mono text-xs">
              {t('noCardsHint')}
            </p>
          )}
          <button
            onClick={loadDue}
            disabled={generating}
            className="border-fl-border text-fl-label text-fl-muted-2 hover:text-fl-fg hover:border-fl-border-2 mt-6 border px-6 py-2 font-mono tracking-widest uppercase transition-colors"
          >
            {t('refresh')}
          </button>
        </div>
      )}

      {/* Card review */}
      {cards.length > 0 && (
        <>
          <div className="text-fl-label text-fl-muted-3 flex items-center justify-between font-mono tracking-widest uppercase">
            <span>
              {current + 1} / {cards.length} {t('due')}
            </span>
            {/* Mode toggle */}
            <div className="flex gap-1">
              <button
                disabled={reviewing || managing || recording || generating}
                onClick={() => {
                  setSpeakingMode(false)
                  setFlipped(false)
                }}
                className={`text-fl-hint border px-3 py-1 tracking-widest transition-colors disabled:cursor-not-allowed disabled:opacity-50 ${!speakingMode ? 'border-fl-border-2 text-fl-fg' : 'border-fl-border text-fl-muted-3 hover:text-fl-muted-1'}`}
              >
                {t('standardMode')}
              </button>
              <button
                disabled={reviewing || managing || recording || generating}
                onClick={() => {
                  setSpeakingMode(true)
                  setFlipped(false)
                }}
                className={`text-fl-hint border px-3 py-1 tracking-widest transition-colors disabled:cursor-not-allowed disabled:opacity-50 ${speakingMode ? 'border-fl-border-2 text-fl-fg' : 'border-fl-border text-fl-muted-3 hover:text-fl-muted-1'}`}
              >
                {t('speakingMode')}
              </button>
            </div>
          </div>

          {/* ── Standard mode ── */}
          {!speakingMode && (
            <>
              <BackPreference value={backContent} onChange={setBackContent} />
              <div className="border-fl-border bg-fl-surface border">
                <div className="border-fl-border flex items-center justify-between gap-3 border-b px-6 py-4">
                  <div className="flex items-center gap-2">
                    <span className="text-fl-label text-fl-muted-3">●</span>
                    <span className="text-fl-label text-fl-muted-2 font-mono tracking-widest uppercase">
                      {flipped
                        ? t(
                            backContent === 'translation' &&
                              cards[current].translation.trim()
                              ? 'field_translation'
                              : 'back'
                          )
                        : t('front')}
                    </span>
                  </div>
                  <AudioPlayer
                    text={cards[current].word}
                    studyPlanId={cards[current].study_plan_id}
                    size="md"
                  />
                </div>

                <button
                  type="button"
                  aria-expanded={flipped}
                  aria-label={flipped ? t('tapToHide') : t('tapToReveal')}
                  disabled={reviewing || managing || generating}
                  onClick={() => setFlipped(!flipped)}
                  className="hover:bg-fl-surface-2 flex min-h-[220px] w-full flex-col items-center justify-center gap-4 p-10 text-center disabled:opacity-60"
                >
                  {!flipped ? (
                    <TargetLanguageText
                      as="span"
                      languageCode={targetLanguageCode}
                      className="text-fl-fg text-3xl font-bold"
                    >
                      {cards[current].word}
                    </TargetLanguageText>
                  ) : (
                    <>
                      {backContent === 'translation' &&
                      cards[current].translation.trim() ? (
                        <span className="text-fl-fg-2 font-sans text-xl leading-relaxed">
                          {cards[current].translation}
                        </span>
                      ) : (
                        <TargetLanguageText
                          languageCode={targetLanguageCode}
                          className="text-fl-fg-2 text-xl"
                        >
                          {cards[current].definition}
                        </TargetLanguageText>
                      )}
                      {backContent === 'translation' &&
                        cards[current].translation.trim() && (
                          <TargetLanguageText
                            languageCode={targetLanguageCode}
                            className="text-fl-muted-1"
                          >
                            {cards[current].definition}
                          </TargetLanguageText>
                        )}
                      {cards[current].example_sentence && (
                        <TargetLanguageText
                          as="span"
                          languageCode={targetLanguageCode}
                          className="text-fl-muted-1 italic"
                        >
                          {cards[current].example_sentence}
                        </TargetLanguageText>
                      )}
                      {backContent === 'definition' &&
                        cards[current].translation && (
                          <span className="text-fl-muted-1 border-fl-border mt-1 border-t pt-3 font-sans text-sm leading-relaxed">
                            {cards[current].translation}
                          </span>
                        )}
                    </>
                  )}
                  <span className="text-fl-muted-2 mt-2 text-sm">
                    {flipped ? t('tapToHide') : t('tapToReveal')}
                  </span>
                </button>
              </div>

              {flipped && (
                <div
                  role="group"
                  aria-labelledby="flashcard-rating-title"
                  aria-describedby="flashcard-rating-help flashcard-rating-again"
                  className="space-y-3"
                >
                  <div className="space-y-1">
                    <p
                      id="flashcard-rating-title"
                      className="text-fl-fg font-sans font-medium"
                    >
                      {t('ratingTitle')}
                    </p>
                    <p
                      id="flashcard-rating-help"
                      className="text-fl-muted-1 font-sans text-sm"
                    >
                      {t('ratingHelp')}
                    </p>
                  </div>
                  <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    {[
                      { key: 'again', q: 0, color: 'var(--fl-muted-1)' },
                      { key: 'hard', q: 3, color: 'var(--fl-muted-1)' },
                      { key: 'good', q: 4, color: 'var(--fl-muted-0)' },
                      { key: 'easy', q: 5, color: 'var(--fl-fg)' },
                    ].map(({ key, q, color }) => (
                      <div key={q} className="space-y-2 text-center">
                        <button
                          disabled={reviewing || managing || generating}
                          onClick={() => reviewCard(q)}
                          aria-describedby={`flashcard-rating-${key}-hint`}
                          className="border-fl-border text-fl-label hover:border-fl-border-2 w-full border px-2 py-3 font-mono tracking-widest uppercase transition-all disabled:cursor-not-allowed disabled:opacity-50"
                          style={{ color }}
                        >
                          {t(key)}
                        </button>
                        <p
                          id={`flashcard-rating-${key}-hint`}
                          className="text-fl-muted-1 font-sans text-sm"
                        >
                          {t(`${key}Hint`)}
                        </p>
                      </div>
                    ))}
                  </div>
                  <p
                    id="flashcard-rating-again"
                    className="text-fl-muted-2 font-sans text-sm"
                  >
                    {t('againTomorrow')}
                  </p>
                </div>
              )}
            </>
          )}

          {/* ── Speaking mode ── */}
          {speakingMode && (
            <div className="border-fl-border bg-fl-surface border">
              <div className="border-fl-border flex items-center justify-between border-b px-6 py-4">
                <div className="flex items-center gap-2">
                  <span className="text-fl-label text-fl-muted-3">●</span>
                  <span className="text-fl-label text-fl-muted-2 font-mono tracking-widest uppercase">
                    {t('speakingMode')}
                  </span>
                </div>
                <span className="text-fl-caption text-fl-muted-1 font-sans leading-relaxed">
                  {t('sayWord')}
                </span>
              </div>

              <div className="flex flex-col items-center justify-center gap-5 p-10 text-center">
                <TargetLanguageText
                  as="p"
                  languageCode={targetLanguageCode}
                  className="text-fl-fg-2"
                >
                  {cards[current].definition}
                </TargetLanguageText>
                {cards[current].example_sentence && (
                  <TargetLanguageText
                    as="p"
                    languageCode={targetLanguageCode}
                    className="text-fl-muted-1 italic"
                  >
                    {cards[current].example_sentence}
                  </TargetLanguageText>
                )}
                {cards[current].translation && (
                  <p className="text-fl-muted-1 border-fl-border mt-1 border-t pt-3 font-sans text-sm leading-relaxed">
                    {cards[current].translation}
                  </p>
                )}
                <VoiceRecorder
                  key={cards[current].id}
                  studyPlanId={cards[current].study_plan_id}
                  onTranscription={handleSpeakingTranscription}
                  maxSeconds={5}
                  disabled={reviewing || managing || generating}
                  onBusyChange={setRecording}
                  className="mt-2"
                />
              </div>
            </div>
          )}

          {reviewError && (
            <div className="flex flex-wrap items-center gap-3 text-sm">
              <p role="alert" className="text-fl-error-fg">
                {tCommon('errorMessage')}
              </p>
              <button
                className="underline"
                disabled={reviewing || managing || recording || generating}
                onClick={() => void loadDue()}
              >
                {t('refresh')}
              </button>
            </div>
          )}
          <FlashcardActions
            key={cards[current].id}
            card={cards[current]}
            disabled={reviewing || recording || generating}
            onInteractionChange={setManaging}
            onUpdate={(updated) => {
              if (updated.is_suspended) removeCurrent(false)
              else
                setCards((items) =>
                  items.map((item) => (item.id === updated.id ? updated : item))
                )
            }}
            onDelete={() => removeCurrent(true)}
          />
          <p className="text-fl-muted-2 text-sm">{t('suspendHint')}</p>

          <p className="text-fl-hint text-fl-border-2 text-center font-mono tracking-widest uppercase">
            EF {cards[current].ease_factor.toFixed(2)} · {t('interval')}{' '}
            {cards[current].interval}d · {t('repetitions')}{' '}
            {cards[current].repetitions}
          </p>
        </>
      )}
    </div>
  )
}
