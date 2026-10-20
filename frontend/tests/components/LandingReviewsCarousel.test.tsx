import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react'
import { LandingReviewsCarousel } from '@/components/reviews/LandingReviewsCarousel'
import type { ReviewPublic } from '@/types/api'

vi.mock('next-intl', () => ({
  useLocale: () => 'en',
  useTranslations:
    (namespace?: string) =>
    (key: string, values?: Record<string, string | number>) => {
      if (namespace === 'targetLanguages') {
        const languageNames: Record<string, string> = {
          es: 'Spanish',
          fr: 'French',
        }
        return languageNames[key] ?? key
      }
      if (key === 'learningLanguage') return `Learning ${values?.language}`
      if (key === 'starsLabel') return `${values?.rating} out of 5 stars`
      if (key === 'ratingOnly') return 'Verified FreeLingo rating.'
      if (key === 'averageLabel')
        return `${values?.average}/5 · ${values?.count} ratings`
      if (key === 'ratingCount') return `${values?.count} ratings`
      if (key === 'carouselLabel') return 'Student reviews'
      return key
    },
}))

let viewportWidth: number
let cardWidth: number
let reducedMotion: boolean
let commentOverflows: boolean
let media: MediaQueryList
const resizeCallbacks = new Set<() => void>()

beforeEach(() => {
  vi.useFakeTimers()
  viewportWidth = 600
  cardWidth = 360
  reducedMotion = false
  commentOverflows = false
  resizeCallbacks.clear()
  vi.spyOn(document, 'hidden', 'get').mockReturnValue(false)
  const mediaEvents = new EventTarget()
  media = {
    get matches() {
      return reducedMotion
    },
    media: '(prefers-reduced-motion: reduce)',
    onchange: null,
    addListener: vi.fn(),
    removeListener: vi.fn(),
    addEventListener: vi.fn(mediaEvents.addEventListener.bind(mediaEvents)),
    removeEventListener: vi.fn(mediaEvents.removeEventListener.bind(mediaEvents)),
    dispatchEvent: mediaEvents.dispatchEvent.bind(mediaEvents),
  }
  vi.spyOn(window, 'matchMedia').mockReturnValue(media)
  vi.stubGlobal(
    'ResizeObserver',
    class {
      constructor(private callback: () => void) {
        resizeCallbacks.add(callback)
      }
      observe() {}
      disconnect() {
        resizeCallbacks.delete(this.callback)
      }
    }
  )
  vi.spyOn(HTMLElement.prototype, 'clientWidth', 'get').mockImplementation(
    function (this: HTMLElement) {
      return this.getAttribute('aria-label') === 'Student reviews'
        ? viewportWidth
        : cardWidth
    }
  )
  vi.spyOn(HTMLElement.prototype, 'scrollWidth', 'get').mockImplementation(
    function (this: HTMLElement) {
      return this.getAttribute('aria-label') === 'Student reviews'
        ? this.children.length * (cardWidth + 16) - 16
        : cardWidth
    }
  )
  vi.spyOn(HTMLElement.prototype, 'clientHeight', 'get').mockReturnValue(144)
  vi.spyOn(HTMLElement.prototype, 'scrollHeight', 'get').mockImplementation(
    () => (commentOverflows ? 300 : 144)
  )
  vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(
    function (this: HTMLElement) {
      const index = this.parentElement
        ? Array.from(this.parentElement.children).indexOf(this)
        : 0
      const left =
        this.tagName === 'ARTICLE'
          ? 24 +
            index * (cardWidth + 16) -
            (this.parentElement?.scrollLeft ?? 0)
          : 24
      return new DOMRect(left, 0, cardWidth, 200)
    }
  )
})

afterEach(() => {
  cleanup()
  vi.useRealTimers()
  vi.unstubAllGlobals()
})

function renderCarousel(count = 4) {
  const reviews: ReviewPublic[] = Array.from({ length: count }, (_, index) => ({
    id: index + 1,
    user_display_name: `Student ${index + 1}`,
    target_language: 'es-ES',
    rating: 5,
    comment: `Review ${index + 1}`,
    created_at: '2026-10-20',
  }))
  const result = render(
    <LandingReviewsCarousel
      reviews={reviews}
      summary={{ total: 128, average_rating: 4.3 }}
    />
  )
  const scroller = screen.getByRole('group', { name: 'Student reviews' })
  const scrollTo = vi.fn((options?: ScrollToOptions | number) => {
    scroller.scrollLeft =
      typeof options === 'number' ? options : (options?.left ?? 0)
  })
  scroller.scrollTo = scrollTo
  return { ...result, scroller, scrollTo }
}

function tick() {
  act(() => vi.advanceTimersByTime(3500))
}

function resize() {
  act(() => [...resizeCallbacks].forEach((callback) => callback()))
}

describe('LandingReviewsCarousel', () => {
  it('renders reviews with comments', () => {
    render(
      <LandingReviewsCarousel
        summary={{ total: 128, average_rating: 4.3 }}
        reviews={[
          {
            id: 1,
            user_display_name: 'Ada',
            target_language: 'es-ES',
            rating: 5,
            comment: 'Excellent practice',
            created_at: '2026-06-19',
          },
        ]}
      />
    )
    expect(screen.getByText('Ada')).toBeDefined()
    expect(screen.getByText('Excellent practice')).toBeDefined()
    expect(screen.getByText('Learning Spanish')).toBeDefined()
    expect(
      screen.getByRole('group', { name: '4.3/5 · 128 ratings' })
    ).toBeInTheDocument()
    expect(screen.getAllByRole('article')).toHaveLength(1)
  })

  it('renders rating-only reviews with fallback text', () => {
    render(
      <LandingReviewsCarousel
        summary={null}
        reviews={[
          {
            id: 1,
            user_display_name: 'Sam',
            target_language: 'fr-FR',
            rating: 4,
            comment: null,
            created_at: '2026-06-19',
          },
        ]}
      />
    )
    expect(screen.getByText('Verified FreeLingo rating.')).toBeDefined()
  })

  it.each([null, { total: 0, average_rating: null }])(
    'renders nothing without reviews or approved ratings: %j',
    (summary) => {
      const { container } = render(
        <LandingReviewsCarousel reviews={[]} summary={summary} />
      )
      expect(container.firstChild).toBeNull()
    }
  )

  it('shows the summary without cards when no positive comments exist', () => {
    render(
      <LandingReviewsCarousel
        reviews={[]}
        summary={{ total: 128, average_rating: 4.94 }}
      />
    )
    expect(
      screen.getByRole('group', { name: '4.9/5 · 128 ratings' })
    ).toBeInTheDocument()
    expect(screen.queryByRole('article')).not.toBeInTheDocument()
    expect(screen.queryByRole('button')).not.toBeInTheDocument()
  })

  it('keeps comments without inventing a summary when the summary is unavailable', () => {
    render(
      <LandingReviewsCarousel
        reviews={[
          {
            id: 1,
            user_display_name: 'Ada',
            target_language: 'es-ES',
            rating: 5,
            comment: 'Excellent practice',
            created_at: '2026-06-19',
          },
        ]}
        summary={null}
      />
    )
    expect(screen.getByText('Excellent practice')).toBeInTheDocument()
    expect(screen.queryByText(/ratings/)).not.toBeInTheDocument()
  })

  it('moves to actual card boundaries, clamps the last step and wraps both ways', () => {
    const { scrollTo } = renderCarousel()
    const next = screen.getByRole('button', { name: 'next' })
    for (const left of [376, 752, 888, 0]) {
      fireEvent.click(next)
      expect(scrollTo).toHaveBeenLastCalledWith({ left, behavior: 'smooth' })
    }
    fireEvent.click(screen.getByRole('button', { name: 'previous' }))
    expect(scrollTo).toHaveBeenLastCalledWith({ left: 888, behavior: 'smooth' })
    fireEvent.click(screen.getByRole('button', { name: 'previous' }))
    expect(scrollTo).toHaveBeenLastCalledWith({ left: 752, behavior: 'smooth' })
    tick()
    expect(scrollTo).toHaveBeenCalledTimes(6)
  })

  it('uses current card widths after a responsive resize', () => {
    const { scrollTo } = renderCarousel()
    viewportWidth = 280
    cardWidth = 280
    resize()
    fireEvent.click(screen.getByRole('button', { name: 'next' }))
    expect(scrollTo).toHaveBeenLastCalledWith({ left: 296, behavior: 'smooth' })
  })

  it('pauses on hover and resumes after leaving unless explicitly paused', () => {
    const { scroller, scrollTo } = renderCarousel()
    tick()
    expect(scrollTo).toHaveBeenCalledTimes(1)
    fireEvent.pointerEnter(scroller.parentElement!)
    tick()
    expect(scrollTo).toHaveBeenCalledTimes(1)
    fireEvent.pointerLeave(scroller.parentElement!)
    tick()
    expect(scrollTo).toHaveBeenCalledTimes(2)
    fireEvent.click(screen.getByRole('button', { name: 'pause' }))
    tick()
    expect(scrollTo).toHaveBeenCalledTimes(2)
    fireEvent.click(screen.getByRole('button', { name: 'resume' }))
    tick()
    expect(scrollTo).toHaveBeenCalledTimes(3)
  })

  it.each(['focus', 'pointer', 'wheel'])(
    'stops automatic scrolling after %s interaction until explicitly resumed',
    (interaction) => {
      const { scroller, scrollTo } = renderCarousel()
      if (interaction === 'focus') fireEvent.focus(scroller)
      if (interaction === 'pointer') fireEvent.pointerDown(scroller)
      if (interaction === 'wheel') fireEvent.wheel(scroller)
      fireEvent.blur(scroller)
      tick()
      expect(scrollTo).not.toHaveBeenCalled()
      fireEvent.click(screen.getByRole('button', { name: 'resume' }))
      tick()
      expect(scrollTo).toHaveBeenCalledTimes(1)
    }
  )

  it('disables autoplay and animated manual scrolling for reduced motion', () => {
    reducedMotion = true
    const { scrollTo } = renderCarousel()
    tick()
    expect(scrollTo).not.toHaveBeenCalled()
    expect(
      screen.queryByRole('button', { name: 'pause' })
    ).not.toBeInTheDocument()
    expect(
      screen.queryByRole('button', { name: 'resume' })
    ).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'next' }))
    expect(scrollTo).toHaveBeenLastCalledWith({
      left: 376,
      behavior: 'instant',
    })
  })

  it('responds to changes in the reduced-motion preference', () => {
    const { scrollTo } = renderCarousel()
    tick()
    expect(scrollTo).toHaveBeenCalledTimes(1)
    reducedMotion = true
    act(() => media.dispatchEvent(new Event('change')))
    tick()
    expect(scrollTo).toHaveBeenCalledTimes(1)
    fireEvent.click(screen.getByRole('button', { name: 'next' }))
    expect(scrollTo).toHaveBeenLastCalledWith({
      left: 752,
      behavior: 'instant',
    })
  })

  it('does not advance while the page is hidden', () => {
    const { scrollTo } = renderCarousel()
    vi.spyOn(document, 'hidden', 'get').mockReturnValue(true)
    tick()
    expect(scrollTo).not.toHaveBeenCalled()
    vi.spyOn(document, 'hidden', 'get').mockReturnValue(false)
    tick()
    expect(scrollTo).toHaveBeenCalledTimes(1)
  })

  it.each([1, 2])(
    'hides controls and does not advance when %s cards fit',
    (count) => {
      viewportWidth = 1000
      const { scrollTo } = renderCarousel(count)
      expect(screen.queryByRole('button')).not.toBeInTheDocument()
      tick()
      expect(scrollTo).not.toHaveBeenCalled()
    }
  )

  it('updates overflow controls when the viewport grows', () => {
    const { scrollTo } = renderCarousel(2)
    expect(screen.getByRole('button', { name: 'next' })).toBeInTheDocument()
    viewportWidth = 1000
    resize()
    expect(screen.queryByRole('button')).not.toBeInTheDocument()
    tick()
    expect(scrollTo).not.toHaveBeenCalled()
  })

  it('expands only truncated comments and keeps the same disclosure control', () => {
    commentOverflows = true
    const { scroller, scrollTo } = renderCarousel(2)
    const button = screen.getAllByRole('button', { name: 'readMore' })[0]
    const comment = screen.getByText('Review 1')
    expect(button).toHaveAttribute('aria-expanded', 'false')
    expect(button).toHaveAttribute('aria-controls', comment.id)
    expect(comment).toHaveClass('line-clamp-6')
    fireEvent.pointerDown(scroller)
    fireEvent.click(button)
    expect(button).toHaveAccessibleName('readLess')
    expect(button).toHaveAttribute('aria-expanded', 'true')
    expect(comment).not.toHaveClass('line-clamp-6')
    resize()
    expect(button).toBeInTheDocument()
    tick()
    expect(scrollTo).not.toHaveBeenCalled()
    fireEvent.click(button)
    expect(button).toHaveAccessibleName('readMore')
    expect(comment).toHaveClass('line-clamp-6')
    commentOverflows = false
    resize()
    expect(
      screen.queryByRole('button', { name: 'readMore' })
    ).not.toBeInTheDocument()
  })

  it('does not offer expansion for comments that fit', () => {
    renderCarousel(1)
    expect(
      screen.queryByRole('button', { name: 'readMore' })
    ).not.toBeInTheDocument()
  })

  it('cleans up timers, resize observers and media listeners on unmount', () => {
    const { unmount, scrollTo } = renderCarousel()
    unmount()
    tick()
    expect(scrollTo).not.toHaveBeenCalled()
    expect(resizeCallbacks.size).toBe(0)
    expect(media.removeEventListener).toHaveBeenCalledWith(
      'change',
      vi.mocked(media.addEventListener).mock.calls[0][1]
    )
    expect(vi.getTimerCount()).toBe(0)
  })
})
