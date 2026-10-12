'use client'

import { useEffect, useRef, useState, type SetStateAction } from 'react'
import Link from 'next/link'
import { useLocale, useTranslations } from 'next-intl'
import { ArrowLeft, BookOpen, CalendarDays, Pause, Search } from 'lucide-react'
import { apiFetch } from '@/lib/api'
import { AudioPlayer } from '@/components/ui/AudioPlayer'
import { PageLoading } from '@/components/ui/page-loading'
import { Pagination } from '@/components/ui/pagination'
import { TargetLanguageText } from '@/components/TargetLanguageText'
import NoPlanBanner from '@/components/plan/NoPlanBanner'
import {
  FlashcardBoundary,
  FlashcardError,
  useFlashcardScope,
} from '@/components/flashcards/FlashcardBoundary'
import {
  FlashcardActions,
  initialFlashcardActionState,
  type FlashcardActionState,
} from '@/components/flashcards/FlashcardActions'
import type { Flashcard } from '@/components/flashcards/types'

const LIMIT = 10

export default function VocabularyPage() {
  return (
    <FlashcardBoundary>
      {(code) => <VocabularyContent targetLanguageCode={code} />}
    </FlashcardBoundary>
  )
}

function VocabularyContent({
  targetLanguageCode,
}: {
  targetLanguageCode: string
}) {
  const t = useTranslations('flashcards')
  const tCommon = useTranslations('common')
  const locale = useLocale()
  const isCurrent = useFlashcardScope()
  const [data, setData] = useState<{
    items: Flashcard[]
    total: number
    page: number
    pages: number
  } | null>(null)
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [loading, setLoading] = useState(true)
  const [source, setSource] = useState('all')
  const [status, setStatus] = useState('all')
  const [loadError, setLoadError] = useState(false)
  const [noPlan, setNoPlan] = useState(false)
  const [revision, setRevision] = useState(0)
  const requestVersion = useRef(0)
  const [actionStates, setActionStates] = useState<
    Record<number, FlashcardActionState>
  >({})

  // Debounce search input: reset to page 1 on new query
  useEffect(() => {
    if (search === debouncedSearch) return
    const timer = setTimeout(() => {
      setDebouncedSearch(search)
      setPage(1)
    }, 300)
    return () => clearTimeout(timer)
  }, [search, debouncedSearch])

  useEffect(() => {
    const controller = new AbortController()
    const version = ++requestVersion.current
    const acceptsResponse = () =>
      isCurrent() &&
      !controller.signal.aborted &&
      version === requestVersion.current
    async function load() {
      setLoading(true)
      setLoadError(false)
      try {
        const params = new URLSearchParams({
          page: String(page),
          limit: String(LIMIT),
          search: debouncedSearch,
          source,
          status,
        })
        const res = await apiFetch(`/api/flashcards/library?${params}`, {
          signal: controller.signal,
        })
        if (res.status === 404) {
          if (acceptsResponse()) setNoPlan(true)
          return
        }
        if (!res.ok) throw new Error('Could not load library')
        const result = await res.json()
        if (acceptsResponse()) {
          setData(result)
          setPage(result.page)
          setNoPlan(false)
        }
      } catch {
        if (acceptsResponse()) setLoadError(true)
      } finally {
        if (acceptsResponse()) setLoading(false)
      }
    }
    void load()
    return () => controller.abort()
  }, [page, debouncedSearch, source, status, revision, isCurrent])

  function reload() {
    if (!isCurrent()) return
    ++requestVersion.current
    setLoading(true)
    setRevision((value) => value + 1)
  }

  function updateActionState(
    card: Flashcard,
    update: SetStateAction<FlashcardActionState>
  ) {
    if (!isCurrent()) return
    setActionStates((previous) => {
      const next =
        typeof update === 'function'
          ? update(previous[card.id] ?? initialFlashcardActionState(card))
          : update
      const states = { ...previous }
      if (!next.mode && !next.pending && !next.error) delete states[card.id]
      else states[card.id] = next
      return states
    })
  }

  if (noPlan) return <NoPlanBanner />

  return (
    <div className="mx-auto max-w-4xl space-y-6 px-4 py-6 font-sans sm:p-6">
      {/* Header */}
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div className="flex min-w-0 items-center gap-3">
          <BookOpen
            className="text-fl-accent size-6 shrink-0"
            aria-hidden="true"
          />
          <h1 className="text-fl-fg text-2xl font-semibold tracking-tight">
            {t('myVocabulary')}
          </h1>
          {!loading && (
            <span className="border-fl-border bg-fl-surface text-fl-muted-1 border px-2.5 py-1 text-sm tabular-nums">
              {data?.total ?? 0}
            </span>
          )}
        </div>
        <Link
          href="/flashcards"
          className="text-fl-fg border-fl-border hover:bg-fl-surface-2 focus-visible:outline-fl-accent inline-flex min-h-11 items-center gap-2 border px-4 py-2 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2"
        >
          <ArrowLeft className="size-4 shrink-0" aria-hidden="true" />
          {t('backToFlashcards')}
        </Link>
      </div>

      {/* Search */}
      <div className="border-fl-border bg-fl-surface space-y-4 border p-4 sm:p-5">
        <div className="grid items-end gap-4 lg:grid-cols-[minmax(0,1fr)_auto_auto]">
          <div className="relative min-w-0">
            <Search
              className="text-fl-muted-2 pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2"
              aria-hidden="true"
            />
            <input
              type="search"
              aria-label={t('librarySearch')}
              maxLength={255}
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder={t('librarySearch')}
              className="bg-fl-bg border-fl-border text-fl-fg placeholder:text-fl-muted-2 focus-visible:outline-fl-accent min-h-11 w-full border py-2 pr-3 pl-11 text-base focus-visible:outline-2 focus-visible:outline-offset-2"
            />
          </div>
          <label className="text-fl-muted-1 flex min-w-0 flex-col gap-2 text-sm font-medium">
            {t('sourceFilter')}
            <select
              value={source}
              onChange={(event) => {
                setLoading(true)
                setSource(event.target.value)
                setPage(1)
              }}
              className="border-fl-border bg-fl-bg text-fl-fg focus-visible:outline-fl-accent min-h-11 w-full min-w-0 border px-3 py-2 text-base font-normal focus-visible:outline-2 focus-visible:outline-offset-2"
            >
              <option value="all">{t('allCards')}</option>
              <option value="from_text">{t('savedFromText')}</option>
            </select>
          </label>
          <label className="text-fl-muted-1 flex min-w-0 flex-col gap-2 text-sm font-medium">
            {t('statusFilter')}
            <select
              value={status}
              onChange={(event) => {
                setLoading(true)
                setStatus(event.target.value)
                setPage(1)
              }}
              className="border-fl-border bg-fl-bg text-fl-fg focus-visible:outline-fl-accent min-h-11 w-full min-w-0 border px-3 py-2 text-base font-normal focus-visible:outline-2 focus-visible:outline-offset-2"
            >
              <option value="all">{tCommon('all')}</option>
              <option value="due">{t('due')}</option>
              <option value="new">{t('newCards')}</option>
              <option value="suspended">{t('suspended')}</option>
            </select>
          </label>
        </div>
        <p className="border-fl-border text-fl-muted-2 border-t pt-3 text-sm leading-relaxed">
          {t('suspendHint')}
        </p>
      </div>

      {/* List */}
      {data && loading && (
        <p role="status" className="text-fl-muted-2 text-sm">
          {tCommon('loading')}
        </p>
      )}
      {data && loadError && <FlashcardError onRetry={reload} />}
      <div className="border-fl-border bg-fl-surface border">
        {loading && !data ? (
          <PageLoading fullScreen={false} className="block p-5" />
        ) : loadError && !data ? (
          <FlashcardError onRetry={reload} />
        ) : !data?.items.length ? (
          <div className="px-6 py-10 text-center">
            <Search
              className="text-fl-muted-2 mx-auto mb-3 size-6"
              aria-hidden="true"
            />
            <p className="text-fl-muted-1 mx-auto max-w-md text-sm leading-relaxed">
              {debouncedSearch || status !== 'all'
                ? t('libraryNoResults')
                : source === 'from_text'
                  ? t('myVocabularyEmpty')
                  : t('libraryEmpty')}
            </p>
          </div>
        ) : (
          <div className="divide-fl-border divide-y">
            {data.items.map((item) => (
              <article
                key={item.id}
                aria-label={item.word}
                className="space-y-4 p-4 sm:p-6"
              >
                <div className="min-w-0 flex-1">
                  <div className="flex min-w-0 items-center gap-3">
                    <TargetLanguageText
                      as="h2"
                      languageCode={targetLanguageCode}
                      className="text-fl-fg min-w-0 text-xl font-semibold [overflow-wrap:anywhere] break-words"
                    >
                      {item.word}
                    </TargetLanguageText>
                    <AudioPlayer
                      text={item.word}
                      studyPlanId={item.study_plan_id}
                      size="sm"
                    />
                  </div>
                  <TargetLanguageText
                    as="p"
                    languageCode={targetLanguageCode}
                    className="text-fl-muted-1 mt-3 [overflow-wrap:anywhere] break-words"
                  >
                    {item.definition}
                  </TargetLanguageText>
                  <p className="text-fl-fg-2 mt-2 font-sans leading-relaxed [overflow-wrap:anywhere] break-words">
                    {item.translation}
                  </p>
                  {item.example_sentence && (
                    <TargetLanguageText
                      as="p"
                      languageCode={targetLanguageCode}
                      className="text-fl-muted-1 mt-3 [overflow-wrap:anywhere] break-words italic"
                    >
                      {item.example_sentence}
                    </TargetLanguageText>
                  )}
                  <p className="border-fl-border bg-fl-bg text-fl-muted-1 mt-3 inline-flex max-w-full items-center gap-2 border px-2.5 py-1.5 text-xs">
                    {item.is_suspended ? (
                      <Pause className="size-3.5 shrink-0" aria-hidden="true" />
                    ) : (
                      <CalendarDays
                        className="size-3.5 shrink-0"
                        aria-hidden="true"
                      />
                    )}
                    {item.is_suspended
                      ? t('suspended')
                      : t('nextReview', {
                          date: new Intl.DateTimeFormat(locale, {
                            dateStyle: 'medium',
                            timeZone: 'UTC',
                          }).format(new Date(`${item.next_review}T00:00:00Z`)),
                        })}
                  </p>
                </div>
                <div className="border-fl-border border-t pt-3">
                  <FlashcardActions
                    card={item}
                    state={actionStates[item.id]}
                    onStateChange={(update) => updateActionState(item, update)}
                    isOwnerCurrent={isCurrent}
                    onSettled={reload}
                    disabled={loading}
                    onUpdate={(updated) => {
                      if (isCurrent())
                        setData(
                          (previous) =>
                            previous && {
                              ...previous,
                              items: previous.items.map((card) =>
                                card.id === updated.id ? updated : card
                              ),
                            }
                        )
                    }}
                    onDelete={(id) => {
                      if (isCurrent())
                        setData(
                          (previous) =>
                            previous && {
                              ...previous,
                              total: previous.items.some(
                                (card) => card.id === id
                              )
                                ? Math.max(0, previous.total - 1)
                                : previous.total,
                              items: previous.items.filter(
                                (card) => card.id !== id
                              ),
                            }
                        )
                    }}
                  />
                </div>
              </article>
            ))}
          </div>
        )}
      </div>

      {/* Pagination */}
      <Pagination
        page={(data?.page ?? 1) - 1}
        totalPages={loadError ? 1 : (data?.pages ?? 1)}
        loading={loading || search !== debouncedSearch}
        onPageChange={(p) => {
          setLoading(true)
          setPage(p + 1)
        }}
        prevLabel={`← ${t('vocabularyPrev')}`}
        nextLabel={`${t('vocabularyNext')} →`}
        pageInfo={t('vocabularyPageInfo', {
          page: data?.page ?? 1,
          pages: data?.pages ?? 1,
        })}
        className="flex-wrap gap-3 border-0 bg-transparent px-0 [&_button]:min-h-11 [&_button]:font-sans [&_button]:text-sm [&_button]:tracking-normal [&_button]:normal-case [&_span]:text-sm [&_span]:tracking-normal"
      />
    </div>
  )
}
