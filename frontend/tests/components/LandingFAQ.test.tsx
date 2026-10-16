import React from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { renderToStaticMarkup } from 'react-dom/server'
import { NextIntlClientProvider } from 'next-intl'
import { LandingFAQ } from '@/components/ui/landing-faq'
import { trackBrowserEvent } from '@/lib/analytics'
import messages from '../../../messages/en.json'

vi.mock('@/lib/analytics', () => ({ trackBrowserEvent: vi.fn() }))

function FAQ() {
  return (
    <NextIntlClientProvider locale="en" messages={messages}>
      <LandingFAQ />
    </NextIntlClientProvider>
  )
}

afterEach(() => {
  cleanup()
  vi.clearAllMocks()
})

describe('Landing FAQ', () => {
  it('includes answers in the initial HTML without requiring interaction', () => {
    const html = renderToStaticMarkup(<FAQ />)
    const document = new DOMParser().parseFromString(html, 'text/html')
    const answer = document.querySelector('[role="region"]')

    expect(answer?.textContent).toContain(messages.landing.faq.modes.answer)
    expect(answer?.hasAttribute('hidden')).toBe(true)
    expect(document.body.textContent).toContain(
      messages.landing.faq.data.answer
    )
  })

  it('exposes the open answer accessibly and tracks opens, not closes', () => {
    render(<FAQ />)
    const modes = screen.getByRole('button', {
      name: messages.landing.faq.modes.question,
    })
    const free = screen.getByRole('button', {
      name: messages.landing.faq.free.question,
    })

    expect(screen.queryByRole('region')).toBeNull()
    fireEvent.click(modes)
    const firstAnswer = screen.getByRole('region', {
      name: messages.landing.faq.modes.question,
    })
    expect(modes).toHaveAttribute('aria-expanded', 'true')
    expect(modes).toHaveAttribute('aria-controls', firstAnswer.id)
    expect(firstAnswer).toBeVisible()

    fireEvent.click(free)
    expect(modes).toHaveAttribute('aria-expanded', 'false')
    expect(firstAnswer).not.toBeVisible()
    expect(free).toHaveAttribute('aria-expanded', 'true')
    expect(screen.getByRole('region')).toHaveTextContent(
      messages.landing.faq.free.answer
    )

    fireEvent.click(free)
    expect(free).toHaveAttribute('aria-expanded', 'false')
    expect(screen.queryByRole('region')).toBeNull()
    expect(trackBrowserEvent).toHaveBeenCalledTimes(2)
    expect(trackBrowserEvent).toHaveBeenCalledWith('faq_viewed')
  })
})
