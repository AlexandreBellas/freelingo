import {
  act,
  fireEvent,
  render,
  screen,
  waitFor,
  within,
} from '@testing-library/react'
import { NextIntlClientProvider } from 'next-intl'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import messages from '../../../messages/en.json'
import FlashcardsPage from '@/app/(app)/flashcards/page'
import VocabularyPage from '@/app/(app)/flashcards/vocabulary/page'
import { useLanguageStore } from '@/store/language'
import { useAuthStore } from '@/store/auth'
import { getLanguageByCode } from '@/lib/target-languages'
import type { Flashcard } from '@/components/flashcards/types'

const { mockApiFetch } = vi.hoisted(() => ({ mockApiFetch: vi.fn() }))
vi.mock('@/lib/api', () => ({ apiFetch: mockApiFetch }))
vi.mock('@/components/ui/AudioPlayer', () => ({ AudioPlayer: () => null }))
vi.mock('@/components/ui/VoiceRecorder', () => ({ VoiceRecorder: () => null }))
vi.mock('@/components/ui/page-loading', () => ({
  PageLoading: () => <p role="status">Loading</p>,
}))

const card: Flashcard = {
  id: 7,
  study_plan_id: 42,
  word: 'book',
  definition: 'An object with pages',
  translation: 'libro',
  example_sentence: 'I read a book.',
  source: null,
  is_suspended: false,
  ease_factor: 2.5,
  interval: 6,
  repetitions: 2,
  next_review: '2026-10-12',
}

function json(data: unknown, status = 200) {
  return new Response(JSON.stringify(data), { status })
}

function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((done) => {
    resolve = done
  })
  return { promise, resolve }
}

function show(page: 'review' | 'library' = 'library') {
  return render(
    <NextIntlClientProvider locale="en" messages={messages} timeZone="UTC">
      {page === 'review' ? <FlashcardsPage /> : <VocabularyPage />}
    </NextIntlClientProvider>
  )
}

function library(items: Flashcard[] = [card], page = 1, pages = 1) {
  return json({ items, total: items.length, page, pages })
}

async function startReview() {
  show('review')
  fireEvent.click(await screen.findByRole('button', { name: 'Start review' }))
}

beforeEach(() => {
  mockApiFetch.mockReset()
  useLanguageStore.setState({
    activeLanguage: getLanguageByCode('en-GB')!,
    userLanguages: [],
    needsRefresh: false,
    isSwitching: false,
    invalidationVersion: 0,
  })
  useAuthStore.setState({ sessionVersion: 0 })
  mockApiFetch.mockImplementation(async (url: string) => {
    if (url.startsWith('/api/flashcards/library?')) return library()
    if (url === '/api/flashcards/due')
      return json({ due: [card], total: 1, cefr_level: 'A1' })
    if (url === '/api/flashcards/preferences')
      return json({ back_content: 'definition' })
    throw new Error(`Unexpected request: ${url}`)
  })
})

describe('flashcard library', () => {
  it('loads every source and supports saved-word, status and translation search filters', async () => {
    show()
    expect(
      await screen.findByRole('heading', { name: 'book' })
    ).toHaveAttribute('lang', 'en-GB')
    expect(screen.getByText(/Next review:/)).toBeInTheDocument()
    expect(mockApiFetch.mock.calls[0][0]).toContain('source=all&status=all')
    fireEvent.change(screen.getByLabelText('Source'), {
      target: { value: 'from_text' },
    })
    await screen.findByRole('heading', { name: 'book' })
    expect(mockApiFetch.mock.calls.at(-1)![0]).toContain('source=from_text')
    fireEvent.change(screen.getByLabelText('Status'), {
      target: { value: 'suspended' },
    })
    await screen.findByRole('heading', { name: 'book' })
    expect(mockApiFetch.mock.calls.at(-1)![0]).toContain('status=suspended')
    fireEvent.change(screen.getByRole('searchbox'), {
      target: { value: 'libro' },
    })
    await waitFor(() =>
      expect(mockApiFetch.mock.calls.at(-1)![0]).toContain('search=libro')
    )
  })

  it('keeps the edit draft and reports duplicate words without losing content', async () => {
    show()
    await screen.findByRole('heading', { name: 'book' })
    fireEvent.click(screen.getByRole('button', { name: 'Edit card' }))
    expect(screen.getByLabelText('Word')).toHaveFocus()
    fireEvent.change(screen.getByLabelText('Word'), {
      target: { value: 'apple' },
    })
    fireEvent.change(screen.getByLabelText('Translation'), {
      target: { value: 'manzana' },
    })
    mockApiFetch.mockResolvedValueOnce(
      json({ detail: 'flashcard_duplicate' }, 409)
    )
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    expect(await screen.findByRole('alert')).toHaveTextContent(
      messages.flashcards.duplicateWord
    )
    expect(screen.getByLabelText('Translation')).toHaveValue('manzana')
    expect(screen.getByRole('heading', { name: 'book' })).toBeInTheDocument()
    expect(JSON.parse(mockApiFetch.mock.calls.at(-1)![1].body)).toEqual({
      word: 'apple',
      definition: card.definition,
      translation: 'manzana',
      example_sentence: card.example_sentence,
    })
  })

  it('requires confirmation and preserves the card when deletion fails', async () => {
    show()
    await screen.findByRole('heading', { name: 'book' })
    fireEvent.click(screen.getByRole('button', { name: 'Delete' }))
    const dialog = screen.getByRole('alertdialog')
    expect(
      mockApiFetch.mock.calls.some(
        ([, options]) => options?.method === 'DELETE'
      )
    ).toBe(false)
    fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }))
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Delete' }))
    mockApiFetch.mockResolvedValueOnce(json({}, 500))
    fireEvent.click(
      within(screen.getByRole('alertdialog')).getByRole('button', {
        name: 'Delete',
      })
    )
    expect(await screen.findByRole('alert')).toHaveTextContent(
      messages.common.errorMessage
    )
    expect(screen.getByRole('heading', { name: 'book' })).toBeInTheDocument()
  })

  it('reloads the clamped page after deleting its last item', async () => {
    mockApiFetch.mockResolvedValueOnce(library([card], 2, 2))
    show()
    await screen.findByRole('heading', { name: 'book' })
    await waitFor(() =>
      expect(screen.queryByRole('status')).not.toBeInTheDocument()
    )
    fireEvent.click(screen.getByRole('button', { name: 'Delete' }))
    mockApiFetch.mockImplementation(
      async (url: string, options?: RequestInit) => {
        if (options?.method === 'DELETE')
          return new Response(null, { status: 204 })
        if (url.startsWith('/api/flashcards/library?')) return library([], 1, 1)
        throw new Error('Unexpected request')
      }
    )
    fireEvent.click(
      within(screen.getByRole('alertdialog')).getByRole('button', {
        name: 'Delete',
      })
    )
    expect(
      await screen.findByText(messages.flashcards.libraryEmpty)
    ).toBeInTheDocument()
    expect(
      screen.queryByRole('heading', { name: 'book' })
    ).not.toBeInTheDocument()
    expect(screen.queryByText('Page 2 of 1')).not.toBeInTheDocument()
  })

  it('reactivates suspended cards and refreshes the filtered list', async () => {
    mockApiFetch.mockResolvedValueOnce(
      library([{ ...card, is_suspended: true }])
    )
    show()
    const reactivate = await screen.findByRole('button', { name: 'Reactivate' })
    mockApiFetch.mockResolvedValueOnce(json(card))
    fireEvent.click(reactivate)
    await screen.findByRole('button', { name: 'Suspend' })
    expect(mockApiFetch).toHaveBeenCalledWith(
      '/api/flashcards/7/suspension',
      expect.objectContaining({
        method: 'PUT',
        body: JSON.stringify({ is_suspended: false }),
      })
    )
  })

  it('reports a load error instead of an empty library and can retry', async () => {
    mockApiFetch.mockResolvedValueOnce(json({}, 503))
    show()
    expect(await screen.findByRole('alert')).toHaveTextContent(
      messages.common.errorMessage
    )
    expect(
      screen.queryByText(messages.flashcards.libraryEmpty)
    ).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Retry' }))
    expect(
      await screen.findByRole('heading', { name: 'book' })
    ).toBeInTheDocument()
  })

  it('ignores a late search response after filters change', async () => {
    const old = deferred<unknown>()
    const body = vi.fn(() => old.promise)
    mockApiFetch.mockResolvedValueOnce({ ok: true, json: body })
    show()
    await waitFor(() => expect(body).toHaveBeenCalled())
    fireEvent.change(screen.getByLabelText('Status'), {
      target: { value: 'suspended' },
    })
    expect(
      await screen.findByRole('heading', { name: 'book' })
    ).toBeInTheDocument()
    await act(async () =>
      old.resolve({
        items: [{ ...card, word: 'obsolete' }],
        total: 1,
        page: 1,
        pages: 1,
      })
    )
    expect(screen.queryByText('obsolete')).not.toBeInTheDocument()
  })
})

describe('review management', () => {
  it('updates the current card without reviewing it and blocks scoring while editing', async () => {
    await startReview()
    fireEvent.click(
      await screen.findByRole('button', { name: 'tap to reveal' })
    )
    fireEvent.click(screen.getByRole('button', { name: 'Edit card' }))
    expect(screen.getByRole('button', { name: 'Good' })).toBeDisabled()
    fireEvent.change(screen.getByLabelText('Definition'), {
      target: { value: 'A useful definition' },
    })
    mockApiFetch.mockResolvedValueOnce(
      json({ ...card, definition: 'A useful definition' })
    )
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    await waitFor(() =>
      expect(screen.queryByRole('form')).not.toBeInTheDocument()
    )
    expect(
      within(screen.getByRole('button', { name: 'tap to hide' })).getByText(
        'A useful definition'
      )
    ).toBeInTheDocument()
    expect(
      mockApiFetch.mock.calls.some(([url]) => url.endsWith('/review'))
    ).toBe(false)
    expect(screen.getByRole('button', { name: 'Good' })).toBeEnabled()
    expect(screen.getByRole('button', { name: 'Edit card' })).toHaveFocus()
  })

  it('removes a suspended card from the queue without reviewing or deleting it', async () => {
    mockApiFetch.mockImplementation(async (url: string) => {
      if (url === '/api/flashcards/due')
        return json({
          due: [card, { ...card, id: 8, word: 'apple' }],
          total: 2,
          cefr_level: 'A1',
        })
      if (url === '/api/flashcards/preferences')
        return json({ back_content: 'definition' })
      return json({ ...card, is_suspended: true })
    })
    await startReview()
    fireEvent.click(await screen.findByRole('button', { name: 'Suspend' }))
    expect(await screen.findByText('apple')).toBeInTheDocument()
    expect(screen.queryByText('book')).not.toBeInTheDocument()
    expect(screen.getByText('2 Total · 1 Due')).toBeInTheDocument()
    expect(
      mockApiFetch.mock.calls.some(([url]) => url.endsWith('/review'))
    ).toBe(false)
  })

  it('does not advance on a failed review and allows retry', async () => {
    await startReview()
    fireEvent.click(
      await screen.findByRole('button', { name: 'tap to reveal' })
    )
    mockApiFetch.mockResolvedValueOnce(json({}, 503))
    fireEvent.click(screen.getByRole('button', { name: 'Good' }))
    expect(await screen.findByRole('alert')).toHaveTextContent(
      messages.common.errorMessage
    )
    expect(screen.getByText(card.definition)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Good' })).toBeEnabled()
  })

  it('persists translation preference and retains definition as complementary content', async () => {
    await startReview()
    const preference = await screen.findByLabelText('Main answer on the back')
    await waitFor(() => expect(preference).toBeEnabled())
    mockApiFetch.mockResolvedValueOnce(json({ back_content: 'translation' }))
    fireEvent.change(preference, { target: { value: 'translation' } })
    await waitFor(() => expect(preference).toHaveValue('translation'))
    expect(mockApiFetch).toHaveBeenCalledWith(
      '/api/flashcards/preferences',
      expect.objectContaining({
        method: 'PUT',
        body: JSON.stringify({ back_content: 'translation' }),
      })
    )
    fireEvent.click(screen.getByRole('button', { name: 'tap to reveal' }))
    const answer = screen.getByRole('button', { name: 'tap to hide' })
    expect(within(answer).getByText('libro')).toHaveClass('text-xl')
    expect(within(answer).getByText(card.definition)).toBeInTheDocument()
    expect(within(answer).getByText(card.example_sentence)).toBeInTheDocument()
  })

  it('falls back to definition when the saved translation preference has no translation', async () => {
    mockApiFetch.mockImplementation(async (url: string) =>
      url.endsWith('/preferences')
        ? json({ back_content: 'translation' })
        : json({
            due: [{ ...card, translation: '' }],
            total: 1,
            cefr_level: 'A1',
          })
    )
    await startReview()
    await waitFor(() =>
      expect(screen.getByLabelText('Main answer on the back')).toHaveValue(
        'translation'
      )
    )
    fireEvent.click(screen.getByRole('button', { name: 'tap to reveal' }))
    expect(screen.getByText(card.definition)).toHaveClass('text-xl')
  })
})

describe.each(['review', 'library'] as const)(
  '%s context isolation',
  (page) => {
    it('discards late data after a language switch', async () => {
      const old = deferred<unknown>()
      const oldBody = vi.fn(() => old.promise)
      mockApiFetch.mockResolvedValueOnce({ ok: true, json: oldBody })
      show(page)
      await waitFor(() => expect(oldBody).toHaveBeenCalled())
      act(() =>
        useLanguageStore.setState({
          activeLanguage: getLanguageByCode('it-IT')!,
        })
      )
      if (page === 'review')
        fireEvent.click(
          await screen.findByRole('button', { name: 'Start review' })
        )
      await screen.findByText('book')
      await act(async () =>
        old.resolve(
          page === 'review'
            ? { due: [{ ...card, word: 'obsolete' }], total: 1 }
            : {
                items: [{ ...card, word: 'obsolete' }],
                total: 1,
                page: 1,
                pages: 1,
              }
        )
      )
      expect(screen.queryByText('obsolete')).not.toBeInTheDocument()
    })

    it('unmounts card actions while switching and recovers on the same language', async () => {
      show(page)
      if (page === 'review')
        fireEvent.click(
          await screen.findByRole('button', { name: 'Start review' })
        )
      await screen.findByText('book')
      act(() => useLanguageStore.setState({ isSwitching: true }))
      expect(
        screen.queryByRole('button', { name: 'Edit card' })
      ).not.toBeInTheDocument()
      act(() => useLanguageStore.setState({ isSwitching: false }))
      if (page === 'review')
        fireEvent.click(
          await screen.findByRole('button', { name: 'Start review' })
        )
      expect(
        await screen.findByRole('button', { name: 'Edit card' })
      ).toBeInTheDocument()
    })

    it('does not let an old save update a new account session', async () => {
      show(page)
      if (page === 'review')
        fireEvent.click(
          await screen.findByRole('button', { name: 'Start review' })
        )
      fireEvent.click(await screen.findByRole('button', { name: 'Edit card' }))
      const save = deferred<Response>()
      mockApiFetch.mockImplementationOnce(() => save.promise)
      fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
      act(() => useAuthStore.setState({ sessionVersion: 1 }))
      if (page === 'review')
        fireEvent.click(
          await screen.findByRole('button', { name: 'Start review' })
        )
      await screen.findByText('book')
      await act(async () => save.resolve(json({ ...card, word: 'obsolete' })))
      expect(screen.queryByText('obsolete')).not.toBeInTheDocument()
    })
  }
)
