'use client'

import { useEffect, useId, useRef, useState } from 'react'
import { useLocale, useTranslations } from 'next-intl'
import { ArrowLeft, ArrowRight, Pause, Play, Star } from 'lucide-react'
import { getLanguageByCode } from '@/lib/target-languages'
import type { ReviewPublic, ReviewPublicSummary } from '@/types/api'

function Stars({ rating, label }: { rating: number; label: string }) {
  return (
    <div className="flex shrink-0 gap-1" role="img" aria-label={label}>
      {[1, 2, 3, 4, 5].map((star) => (
        <Star
          key={star}
          className={`size-4 ${star <= rating ? 'fill-yellow-400 text-yellow-400' : 'text-fl-muted-4'}`}
          aria-hidden="true"
        />
      ))}
    </div>
  )
}

function ReviewCard({ review }: { review: ReviewPublic }) {
  const t = useTranslations('landingReviews')
  const tTarget = useTranslations('targetLanguages')
  const commentId = useId()
  const commentRef = useRef<HTMLParagraphElement | null>(null)
  const [expanded, setExpanded] = useState(false)
  const [canExpand, setCanExpand] = useState(false)
  const language = getLanguageByCode(review.target_language)

  useEffect(() => {
    const comment = commentRef.current
    if (!comment || expanded) return
    const measure = () => {
      setCanExpand(comment.scrollHeight > comment.clientHeight + 1)
    }
    measure()
    const observer = new ResizeObserver(measure)
    observer.observe(comment)
    document.fonts?.addEventListener('loadingdone', measure)
    return () => {
      observer.disconnect()
      document.fonts?.removeEventListener('loadingdone', measure)
    }
  }, [expanded, review.comment])

  return (
    <article className="border-fl-border bg-fl-surface flex w-full min-w-0 flex-none snap-start flex-col border p-6 sm:w-[360px]">
      <Stars
        rating={review.rating}
        label={t('starsLabel', { rating: review.rating })}
      />
      <p
        ref={commentRef}
        id={commentId}
        dir="auto"
        className={`text-fl-fg-2 mt-4 font-sans text-base leading-relaxed wrap-anywhere ${expanded ? '' : 'line-clamp-6'}`}
      >
        {review.comment || t('ratingOnly')}
      </p>
      {canExpand && (
        <button
          type="button"
          aria-expanded={expanded}
          aria-controls={commentId}
          onClick={() => setExpanded((value) => !value)}
          className="text-fl-accent focus-visible:outline-fl-accent mt-2 min-h-11 self-start font-sans text-sm font-semibold underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-2"
        >
          {t(expanded ? 'readLess' : 'readMore')}
        </button>
      )}
      <div className="mt-auto pt-6">
        <h3 className="text-fl-fg font-sans text-sm font-semibold wrap-anywhere">
          {review.user_display_name}
        </h3>
        <p className="text-fl-muted-2 mt-1 font-sans text-sm wrap-anywhere">
          {t('learningLanguage', {
            language: language
              ? tTarget(language.iso639)
              : review.target_language,
          })}
        </p>
      </div>
    </article>
  )
}

function scrollReviews(
  scroller: HTMLDivElement,
  direction: 'previous' | 'next',
  reducedMotion: boolean
) {
  const maxLeft = scroller.scrollWidth - scroller.clientWidth
  if (maxLeft <= 1) return
  const currentLeft = scroller.scrollLeft
  const scrollerLeft = scroller.getBoundingClientRect().left
  const positions = Array.from(scroller.children, (card) =>
    Math.min(
      maxLeft,
      card.getBoundingClientRect().left - scrollerLeft + currentLeft
    )
  )
  const left =
    direction === 'next'
      ? (positions.find((position) => position > currentLeft + 1) ?? 0)
      : (positions.reverse().find((position) => position < currentLeft - 1) ??
        maxLeft)
  scroller.scrollTo({
    left,
    behavior: reducedMotion ? 'instant' : 'smooth',
  })
}

export function LandingReviewsCarousel({
  reviews,
  summary,
}: {
  reviews: ReviewPublic[]
  summary: ReviewPublicSummary | null
}) {
  const t = useTranslations('landingReviews')
  const locale = useLocale()
  const carouselId = useId()
  const scrollerRef = useRef<HTMLDivElement | null>(null)
  const [hasOverflow, setHasOverflow] = useState(false)
  const [isPlaying, setIsPlaying] = useState(true)
  const [isHovered, setIsHovered] = useState(false)
  const [reducedMotion, setReducedMotion] = useState(true)

  useEffect(() => {
    const media = window.matchMedia('(prefers-reduced-motion: reduce)')
    const update = () => setReducedMotion(media.matches)
    update()
    media.addEventListener('change', update)
    return () => media.removeEventListener('change', update)
  }, [])

  useEffect(() => {
    const scroller = scrollerRef.current
    if (!scroller) return
    const measure = () => {
      setHasOverflow(scroller.scrollWidth > scroller.clientWidth + 1)
    }
    measure()
    const observer = new ResizeObserver(measure)
    observer.observe(scroller)
    for (const card of scroller.children) observer.observe(card)
    return () => observer.disconnect()
  }, [reviews])

  useEffect(() => {
    const scroller = scrollerRef.current
    if (
      !scroller ||
      reviews.length <= 1 ||
      !hasOverflow ||
      !isPlaying ||
      isHovered ||
      reducedMotion
    )
      return
    const interval = window.setInterval(() => {
      if (!document.hidden) scrollReviews(scroller, 'next', false)
    }, 3500)
    return () => window.clearInterval(interval)
  }, [hasOverflow, isPlaying, isHovered, reducedMotion, reviews.length])

  function navigate(direction: 'previous' | 'next') {
    setIsPlaying(false)
    if (scrollerRef.current)
      scrollReviews(scrollerRef.current, direction, reducedMotion)
  }

  if (!reviews.length && !summary?.total) return null

  const formattedAverage =
    summary && summary.total > 0 && summary.average_rating !== null
      ? new Intl.NumberFormat(locale, {
          maximumFractionDigits: 1,
          minimumFractionDigits: 1,
        }).format(summary.average_rating)
      : null

  return (
    <section
      id="reviews"
      className="mx-auto w-full max-w-5xl scroll-mt-16 px-6 pb-24"
      aria-labelledby="reviews-title"
    >
      <div className="mb-8 flex flex-col gap-3 text-center">
        <span className="text-fl-label text-fl-muted-2 font-code tracking-widest uppercase">
          {t('eyebrow')}
        </span>
        <h2
          id="reviews-title"
          className="text-fl-fg font-sans text-2xl font-bold tracking-tight md:text-4xl"
        >
          {t('title')}
        </h2>
        <p className="text-fl-muted-1 mx-auto max-w-2xl font-sans text-base leading-relaxed">
          {t('subtitle')}
        </p>
        {formattedAverage !== null && summary && (
          <div
            role="group"
            aria-label={t('averageLabel', {
              average: formattedAverage,
              count: summary.total,
            })}
            className="border-fl-border bg-fl-surface mx-auto mt-3 border px-6 py-4"
          >
            <div
              aria-hidden="true"
              className="flex flex-wrap items-center justify-center gap-x-4 gap-y-2"
            >
              <div className="text-fl-fg flex items-baseline gap-1 font-sans tabular-nums">
                <span className="text-4xl font-bold tracking-tight md:text-5xl">
                  {formattedAverage}
                </span>
                <span className="text-fl-muted-2 text-lg">/5</span>
              </div>
              <div className="flex items-center gap-2">
                <Star className="size-5 shrink-0 fill-yellow-400 text-yellow-400" />
                <span className="text-fl-muted-1 font-sans text-base">
                  {t('ratingCount', { count: summary.total })}
                </span>
              </div>
            </div>
          </div>
        )}
      </div>

      {reviews.length > 0 && (
        <div
          onPointerEnter={(event) => {
            if (event.pointerType !== 'touch') setIsHovered(true)
          }}
          onPointerLeave={() => setIsHovered(false)}
        >
          {hasOverflow && reviews.length > 1 && (
            <div className="mb-4 flex flex-wrap items-center justify-end gap-2">
              {!reducedMotion && (
                <button
                  type="button"
                  aria-controls={carouselId}
                  onClick={() => setIsPlaying((value) => !value)}
                  className="border-fl-border text-fl-muted-1 hover:bg-fl-surface focus-visible:outline-fl-accent inline-flex min-h-11 items-center gap-2 border px-3 font-sans text-sm focus-visible:outline-2 focus-visible:outline-offset-2"
                >
                  {isPlaying ? (
                    <Pause className="size-4" aria-hidden="true" />
                  ) : (
                    <Play className="size-4" aria-hidden="true" />
                  )}
                  {t(isPlaying ? 'pause' : 'resume')}
                </button>
              )}
              <button
                type="button"
                aria-label={t('previous')}
                aria-controls={carouselId}
                onFocus={() => setIsPlaying(false)}
                onClick={() => navigate('previous')}
                className="border-fl-border text-fl-fg hover:bg-fl-surface focus-visible:outline-fl-accent inline-flex size-11 items-center justify-center border focus-visible:outline-2 focus-visible:outline-offset-2"
              >
                <ArrowLeft className="size-4" aria-hidden="true" />
              </button>
              <button
                type="button"
                aria-label={t('next')}
                aria-controls={carouselId}
                onFocus={() => setIsPlaying(false)}
                onClick={() => navigate('next')}
                className="border-fl-border text-fl-fg hover:bg-fl-surface focus-visible:outline-fl-accent inline-flex size-11 items-center justify-center border focus-visible:outline-2 focus-visible:outline-offset-2"
              >
                <ArrowRight className="size-4" aria-hidden="true" />
              </button>
            </div>
          )}
          <div
            ref={scrollerRef}
            id={carouselId}
            role="group"
            aria-label={t('carouselLabel')}
            tabIndex={hasOverflow ? 0 : undefined}
            onFocusCapture={() => setIsPlaying(false)}
            onPointerDown={() => setIsPlaying(false)}
            onWheel={() => setIsPlaying(false)}
            className={`scrollbar-thumb-fl-border focus-visible:outline-fl-accent flex snap-x scrollbar-thin scrollbar-track-transparent gap-4 overflow-x-auto pb-3 focus-visible:outline-2 focus-visible:outline-offset-4 ${reviews.length === 1 ? 'justify-center' : ''}`}
          >
            {reviews.map((review) => (
              <ReviewCard key={review.id} review={review} />
            ))}
          </div>
        </div>
      )}
    </section>
  )
}
