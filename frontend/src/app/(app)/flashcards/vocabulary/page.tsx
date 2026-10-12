'use client'

import { useEffect, useState } from 'react'
import Link from 'next/link'
import { useLocale, useTranslations } from 'next-intl'
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
import { FlashcardActions } from '@/components/flashcards/FlashcardActions'
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
          if (isCurrent() && !controller.signal.aborted) setNoPlan(true)
          return
        }
        if (!res.ok) throw new Error('Could not load library')
        const result = await res.json()
        if (isCurrent() && !controller.signal.aborted) {
          setData(result)
          setPage(result.page)
          setNoPlan(false)
        }
      } catch {
        if (isCurrent() && !controller.signal.aborted) setLoadError(true)
      } finally {
        if (isCurrent() && !controller.signal.aborted) setLoading(false)
      }
    }
    void load()
    return () => controller.abort()
  }, [page, debouncedSearch, source, status, revision, isCurrent])

  function reload() {
    setLoading(true)
    setRevision((value) => value + 1)
  }

  if (noPlan) return <NoPlanBanner />

  return (
    <div className="mx-auto max-w-4xl space-y-4 p-6">
      {/* Header */}
      <div className="flex items-center justify-between gap-4">
        <div className="flex items-center gap-2">
          <span className="text-fl-label text-fl-muted-3">●</span>
          <span className="text-fl-label text-fl-muted-2 font-mono tracking-widest uppercase">
            {t('myVocabulary')}
          </span>
          {!loading && (
            <span className="text-fl-hint text-fl-muted-3 font-mono tracking-widest">
              {data?.total ?? 0}
            </span>
          )}
        </div>
        <Link
          href="/flashcards"
          className="text-fl-label text-fl-muted-3 hover:text-fl-fg border-fl-border hover:border-fl-border-2 border px-4 py-2 font-mono tracking-widest uppercase transition-colors"
        >
          ← {t('backToFlashcards')}
        </Link>
      </div>

      {/* Search */}
      <input
        type="search"
        aria-label={t('librarySearch')}
        maxLength={255}
        value={search}
        onChange={(e) => setSearch(e.target.value)}
        placeholder={t('librarySearch')}
        className="bg-fl-bg border-fl-border text-fl-fg placeholder:text-fl-border-2 focus:border-fl-border-2 w-full border px-4 py-3 font-mono text-sm transition-colors focus:outline-none"
      />
      <div className="flex flex-wrap gap-4">
        <label className="text-fl-muted-1 flex items-center gap-2 text-sm">
          {t('sourceFilter')}
          <select
            value={source}
            onChange={(event) => {
              setLoading(true)
              setSource(event.target.value)
              setPage(1)
            }}
            className="border-fl-border bg-fl-bg text-fl-fg border px-3 py-2"
          >
            <option value="all">{t('allCards')}</option>
            <option value="from_text">{t('savedFromText')}</option>
          </select>
        </label>
        <label className="text-fl-muted-1 flex items-center gap-2 text-sm">
          {t('statusFilter')}
          <select
            value={status}
            onChange={(event) => {
              setLoading(true)
              setStatus(event.target.value)
              setPage(1)
            }}
            className="border-fl-border bg-fl-bg text-fl-fg border px-3 py-2"
          >
            <option value="all">{tCommon('all')}</option>
            <option value="due">{t('due')}</option>
            <option value="new">{t('newCards')}</option>
            <option value="suspended">{t('suspended')}</option>
          </select>
        </label>
      </div>
      <p className="text-fl-muted-2 text-sm">{t('suspendHint')}</p>

      {/* List */}
      <div className="border-fl-border bg-fl-surface border">
        {loading ? (
          <PageLoading fullScreen={false} className="block p-5" />
        ) : loadError ? (
          <FlashcardError onRetry={reload} />
        ) : !data?.items.length ? (
          <p className="text-fl-muted-3 p-5 font-mono text-xs tracking-widest uppercase">
            {debouncedSearch || status !== 'all'
              ? t('libraryNoResults')
              : source === 'from_text'
                ? t('myVocabularyEmpty')
                : t('libraryEmpty')}
          </p>
        ) : (
          <div className="divide-fl-border divide-y">
            {data.items.map((item) => (
              <article
                key={item.id}
                aria-label={item.word}
                className="space-y-3 px-5 py-4"
              >
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2">
                    <TargetLanguageText
                      as="h2"
                      languageCode={targetLanguageCode}
                      className="text-fl-fg text-lg font-bold"
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
                    className="text-fl-muted-1 mt-2"
                  >
                    {item.definition}
                  </TargetLanguageText>
                  <p className="text-fl-muted-1 mt-1 font-sans leading-relaxed">
                    {item.translation}
                  </p>
                  {item.example_sentence && (
                    <TargetLanguageText
                      as="p"
                      languageCode={targetLanguageCode}
                      className="text-fl-muted-2 mt-2 italic"
                    >
                      {item.example_sentence}
                    </TargetLanguageText>
                  )}
                  <p className="text-fl-muted-2 mt-2 text-sm">
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
                <FlashcardActions
                  card={item}
                  onUpdate={reload}
                  onDelete={reload}
                />
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
        className="gap-2 border-0 bg-transparent"
      />
    </div>
  )
}
