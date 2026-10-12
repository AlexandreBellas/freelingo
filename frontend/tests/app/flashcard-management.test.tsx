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
  it.each(['reconciled', 'unreconciled', 'filtered'] as const)(
    'counts a delayed deletion once against a %s list even if its refresh fails',
    async (snapshot) => {
      const second = { ...card, id: 8, word: 'apple', source: 'from_text' }
      const deletion = deferred<Response>()
      const suspension = deferred<Response>()
      let reads = 0
      mockApiFetch.mockImplementation(
        async (url: string, options?: RequestInit) => {
          if (options?.method === 'DELETE') return deletion.promise
          if (url.endsWith('/suspension')) return suspension.promise
          if (url.startsWith('/api/flashcards/library?')) {
            reads++
            if (reads === 1) return library([card, second])
            if (reads === 2 && snapshot !== 'unreconciled')
              return library([{ ...second, is_suspended: true }])
            return json({}, 503)
          }
          throw new Error('Unexpected request')
        }
      )
      show()
      const a = await screen.findByRole('article', { name: 'book' })
      const b = screen.getByRole('article', { name: 'apple' })
      if (snapshot === 'reconciled')
        fireEvent.click(within(b).getByRole('button', { name: 'Suspend' }))
      fireEvent.click(within(a).getByRole('button', { name: 'Delete' }))
      fireEvent.click(
        within(screen.getByRole('alertdialog')).getByRole('button', {
          name: 'Delete',
        })
      )
      if (snapshot === 'reconciled') {
        await act(async () =>
          suspension.resolve(json({ ...second, is_suspended: true }))
        )
      } else if (snapshot === 'filtered') {
        fireEvent.change(screen.getByLabelText('Source'), {
          target: { value: 'from_text' },
        })
      }
      if (snapshot !== 'unreconciled') {
        await waitFor(() =>
          expect(
            screen.queryByRole('article', { name: 'book' })
          ).not.toBeInTheDocument()
        )
      }
      await act(async () =>
        deletion.resolve(new Response(null, { status: 204 }))
      )
      await screen.findByRole('alert')
      expect(screen.getAllByRole('article')).toHaveLength(1)
      expect(screen.getByRole('article', { name: 'apple' })).toBeInTheDocument()
      expect(
        screen.getByRole('heading', { name: messages.flashcards.myVocabulary })
          .nextElementSibling
      ).toHaveTextContent(/^1$/)
    }
  )

  it('preserves an unsaved editor while another card saves and the library refreshes', async () => {
    const second = { ...card, id: 8, word: 'apple' }
    let items = [card, second]
    let reads = 0
    const refresh = deferred<Response>()
    mockApiFetch.mockImplementation(
      async (url: string, options?: RequestInit) => {
        if (url.startsWith('/api/flashcards/library?')) {
          reads++
          return reads === 2 ? refresh.promise : library(items)
        }
        if (url === '/api/flashcards/8' && options?.method === 'PUT') {
          const updated = { ...second, ...JSON.parse(String(options.body)) }
          items = [card, updated]
          return json(updated)
        }
        throw new Error('Unexpected request')
      }
    )
    show()
    const firstRow = await screen.findByRole('article', { name: 'book' })
    const secondRow = screen.getByRole('article', { name: 'apple' })
    fireEvent.click(within(firstRow).getByRole('button', { name: 'Edit card' }))
    fireEvent.change(within(firstRow).getByLabelText('Definition'), {
      target: { value: 'Unsaved first draft' },
    })
    fireEvent.click(
      within(secondRow).getByRole('button', { name: 'Edit card' })
    )
    fireEvent.change(within(secondRow).getByLabelText('Definition'), {
      target: { value: 'Saved second definition' },
    })
    fireEvent.click(
      within(secondRow).getByRole('button', { name: 'Save changes' })
    )
    await waitFor(() => expect(reads).toBe(2))
    expect(within(firstRow).getByLabelText('Definition')).toHaveValue(
      'Unsaved first draft'
    )
    await act(async () => refresh.resolve(library(items)))
    expect(
      within(screen.getByRole('article', { name: 'book' })).getByLabelText(
        'Definition'
      )
    ).toHaveValue('Unsaved first draft')
    expect(screen.getByText('Saved second definition')).toBeInTheDocument()
    expect(screen.getAllByRole('form', { name: 'Edit card' })).toHaveLength(1)
  })

  it('reconciles an operation even if its row leaves the filtered page while saving', async () => {
    const second = { ...card, id: 8, word: 'apple', source: 'from_text' }
    const pending = deferred<Response>()
    let reads = 0
    mockApiFetch.mockImplementation(
      async (url: string, options?: RequestInit) => {
        if (url.startsWith('/api/flashcards/library?')) {
          reads++
          return library(
            url.includes('source=from_text') ? [second] : [card, second]
          )
        }
        if (options?.method === 'PUT') return pending.promise
        throw new Error('Unexpected request')
      }
    )
    show()
    const row = await screen.findByRole('article', { name: 'book' })
    fireEvent.click(within(row).getByRole('button', { name: 'Edit card' }))
    fireEvent.change(within(row).getByLabelText('Definition'), {
      target: { value: 'Keep this draft' },
    })
    fireEvent.click(within(row).getByRole('button', { name: 'Save changes' }))
    fireEvent.change(screen.getByLabelText('Source'), {
      target: { value: 'from_text' },
    })
    await waitFor(() =>
      expect(
        screen.queryByRole('article', { name: 'book' })
      ).not.toBeInTheDocument()
    )
    const before = reads
    await act(async () => pending.resolve(json({}, 503)))
    await waitFor(() => expect(reads).toBe(before + 1))
    fireEvent.change(screen.getByLabelText('Source'), {
      target: { value: 'all' },
    })
    const returned = await screen.findByRole('article', { name: 'book' })
    expect(within(returned).getByLabelText('Definition')).toHaveValue(
      'Keep this draft'
    )
    expect(within(returned).getByRole('alert')).toHaveTextContent(
      messages.common.errorMessage
    )
    await waitFor(() =>
      expect(
        within(returned).getByRole('button', { name: 'Save changes' })
      ).toBeEnabled()
    )
  })

  it('discards a library snapshot captured before a second mutation completes', async () => {
    const second = { ...card, id: 8, word: 'apple' }
    let items = [card, second]
    const pendingFirst = deferred<Response>()
    const oldRead = deferred<unknown>()
    let reads = 0
    mockApiFetch.mockImplementation(
      async (url: string, options?: RequestInit) => {
        if (url.startsWith('/api/flashcards/library?')) {
          reads++
          return reads === 2
            ? { ok: true, json: () => oldRead.promise }
            : library(items)
        }
        if (url === '/api/flashcards/7' && options?.method === 'PUT')
          return pendingFirst.promise
        if (url === '/api/flashcards/8' && options?.method === 'PUT') {
          items = [items[0], { ...second, definition: 'Second saved' }]
          return json(items[1])
        }
        throw new Error('Unexpected request')
      }
    )
    show()
    for (const [word, definition] of [
      ['book', 'First saved'],
      ['apple', 'Second saved'],
    ]) {
      const row = await screen.findByRole('article', { name: word })
      fireEvent.click(within(row).getByRole('button', { name: 'Edit card' }))
      fireEvent.change(within(row).getByLabelText('Definition'), {
        target: { value: definition },
      })
      fireEvent.click(within(row).getByRole('button', { name: 'Save changes' }))
    }
    await waitFor(() => expect(reads).toBe(2))
    items = [{ ...card, definition: 'First saved' }, items[1]]
    await act(async () => pendingFirst.resolve(json(items[0])))
    await waitFor(() => expect(reads).toBe(3))
    await act(async () =>
      oldRead.resolve({ items: [card, second], page: 1, pages: 1, total: 2 })
    )
    expect(screen.getByText('First saved')).toBeInTheDocument()
    expect(screen.getByText('Second saved')).toBeInTheDocument()
    expect(screen.queryByRole('form')).not.toBeInTheDocument()
  })

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
    expect(
      JSON.parse(
        mockApiFetch.mock.calls.findLast(
          ([, options]) => options?.method === 'PUT'
        )![1].body
      )
    ).toEqual({
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
  it.each(['language', 'plan', 'navigation'] as const)(
    'reconciles a preference save after a %s remount without a competing GET',
    async (change) => {
      const save = deferred<Response>()
      let stored = 'definition'
      let reads = 0
      mockApiFetch.mockImplementation(
        async (url: string, options?: RequestInit) => {
          if (url === '/api/flashcards/due')
            return json({ due: [card], total: 1, cefr_level: 'A1' })
          if (url === '/api/flashcards/preferences') {
            if (options?.method === 'PUT') return save.promise
            reads++
            return json({ back_content: stored })
          }
          throw new Error('Unexpected request')
        }
      )
      const view = show('review')
      fireEvent.click(
        await screen.findByRole('button', { name: 'Start review' })
      )
      const selector = screen.getByLabelText('Main answer on the back')
      await waitFor(() => expect(selector).toBeEnabled())
      fireEvent.change(selector, { target: { value: 'translation' } })
      if (change === 'navigation') {
        view.unmount()
        show('review')
      } else {
        act(() =>
          useLanguageStore.setState(
            change === 'language'
              ? { activeLanguage: getLanguageByCode('it-IT')! }
              : { invalidationVersion: 1 }
          )
        )
      }
      fireEvent.click(
        await screen.findByRole('button', { name: 'Start review' })
      )
      expect(screen.getByLabelText('Main answer on the back')).toBeDisabled()
      expect(reads).toBe(1)
      stored = 'translation'
      await act(async () => save.resolve(json({ back_content: stored })))
      expect(screen.getByLabelText('Main answer on the back')).toHaveValue(
        stored
      )
      expect(screen.getByLabelText('Main answer on the back')).toBeEnabled()
      fireEvent.click(screen.getByRole('button', { name: 'Speaking' }))
      fireEvent.click(screen.getByRole('button', { name: 'Standard' }))
      expect(screen.getByLabelText('Main answer on the back')).toHaveValue(
        stored
      )
    }
  )

  it('retries a lost review response with the same identifier and credits the summary once', async () => {
    await startReview()
    fireEvent.click(screen.getByRole('button', { name: 'tap to reveal' }))
    mockApiFetch.mockRejectedValueOnce(
      new TypeError('Response lost after commit')
    )
    fireEvent.click(screen.getByRole('button', { name: 'Good' }))
    await screen.findByRole('alert')
    expect(screen.getByRole('button', { name: 'Easy' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Edit card' })).toBeDisabled()
    mockApiFetch.mockResolvedValueOnce(json({ ...card, xp_earned: 2 }))
    fireEvent.click(screen.getByRole('button', { name: 'Retry saving' }))
    await screen.findByRole('heading', { name: 'Session summary' })
    const calls = mockApiFetch.mock.calls.filter(([url]) =>
      url.endsWith('/review')
    )
    expect(calls).toHaveLength(2)
    expect(JSON.parse(calls[0][1].body)).toEqual(JSON.parse(calls[1][1].body))
    expect(JSON.parse(calls[0][1].body)).toMatchObject({
      quality: 4,
      review_id: expect.stringMatching(/^[0-9a-f-]{36}$/),
    })
    expect(
      screen.getByText('Cards reviewed').nextElementSibling
    ).toHaveTextContent('1')
    expect(screen.getByText('XP earned').nextElementSibling).toHaveTextContent(
      '2'
    )
  })

  it('keeps preference writes alive across speaking and standard mode switches', async () => {
    const save = deferred<Response>()
    let reads = 0
    mockApiFetch.mockImplementation(
      async (url: string, options?: RequestInit) => {
        if (url === '/api/flashcards/due')
          return json({ due: [card], total: 1, cefr_level: 'A1' })
        if (url === '/api/flashcards/preferences') {
          if (options?.method === 'PUT') return save.promise
          reads++
          return json({ back_content: 'definition' })
        }
        throw new Error('Unexpected request')
      }
    )
    await startReview()
    const selector = screen.getByLabelText('Main answer on the back')
    await waitFor(() => expect(selector).toBeEnabled())
    fireEvent.change(selector, { target: { value: 'translation' } })
    fireEvent.click(screen.getByRole('button', { name: 'Speaking' }))
    fireEvent.click(screen.getByRole('button', { name: 'Standard' }))
    expect(screen.getByLabelText('Main answer on the back')).toBeDisabled()
    expect(reads).toBe(1)
    await act(async () => save.resolve(json({ back_content: 'translation' })))
    expect(screen.getByLabelText('Main answer on the back')).toHaveValue(
      'translation'
    )
    fireEvent.click(screen.getByRole('button', { name: 'tap to reveal' }))
    expect(
      within(screen.getByRole('button', { name: 'tap to hide' })).getByText(
        'libro'
      )
    ).toHaveClass('text-xl')
  })

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
      screen.getByRole('progressbar', { name: 'Session progress' })
    ).toHaveAttribute('aria-valuenow', '1')
    expect(
      screen.getByRole('progressbar', { name: 'Session progress' })
    ).toHaveAttribute('aria-valuemax', '2')
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
    expect(screen.getByRole('button', { name: 'Good' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Retry saving' })).toBeEnabled()
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
