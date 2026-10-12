import { Suspense } from 'react'
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { NextIntlClientProvider } from 'next-intl'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import messages from '../../../messages/en.json'
import VocabularySetPage from '@/app/(app)/vocabulary/[setId]/page'
import { useLanguageStore } from '@/store/language'
import { useAuthStore } from '@/store/auth'
import { getLanguageByCode } from '@/lib/target-languages'

const { api } = vi.hoisted(() => ({ api: vi.fn() }))
vi.mock('@/lib/api', () => ({ apiFetch: api }))
vi.mock('@/components/ui/page-loading', () => ({
  PageLoading: () => <p>Loading</p>,
}))
const originalFetch = useLanguageStore.getState().fetchLanguages
const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), { status })
let serverLanguage: string

function vocabulary(language: string) {
  return {
    set: {
      id: 'greetings_a1',
      level: 'A1',
      topic: 'Greetings',
      unit_ref: '1',
      words: [
        {
          word: language === 'en-GB' ? 'hello' : 'ciao',
          pos: 'noun',
          definition: 'greeting',
          example: 'A greeting.',
        },
      ],
    },
  }
}

async function show() {
  const params = Promise.resolve({ setId: 'greetings_a1' })
  await act(async () => {
    render(
      <NextIntlClientProvider locale="en" messages={messages} timeZone="UTC">
        <Suspense fallback={<p>Loading params</p>}>
          <VocabularySetPage params={params} />
        </Suspense>
      </NextIntlClientProvider>
    )
  })
}

beforeEach(() => {
  serverLanguage = 'en-GB'
  api.mockReset()
  useAuthStore.setState({ sessionVersion: 0, user: null })
  useLanguageStore.setState({
    activeLanguage: getLanguageByCode('en-GB')!,
    isSwitching: false,
    needsRefresh: false,
    invalidationVersion: 0,
    fetchLanguages: vi.fn(async () => {
      useLanguageStore.setState({
        activeLanguage: getLanguageByCode(serverLanguage)!,
        needsRefresh: false,
      })
      return true
    }),
  })
  api.mockImplementation(async (url: string, options?: RequestInit) => {
    if (url.startsWith('/api/vocabulary/'))
      return json(
        vocabulary(
          new URL(url, 'https://test.invalid').searchParams.get('language')!
        )
      )
    if (url === '/api/study-plan/current')
      return json({
        id: serverLanguage === 'en-GB' ? 42 : 43,
        target_language: serverLanguage,
      })
    if (url === '/api/flashcards/bulk') {
      const body = JSON.parse(String(options?.body))
      return body.target_language === serverLanguage
        ? json({ created: 1 })
        : json({ detail: 'flashcard_context_changed' }, 409)
    }
    throw new Error(`Unexpected request ${url}`)
  })
})

afterEach(() => useLanguageStore.setState({ fetchLanguages: originalFetch }))

it('offers recovery instead of loading indefinitely when no active language is returned', async () => {
  useLanguageStore.setState({
    activeLanguage: null,
    fetchLanguages: vi.fn(async () => true),
  })
  await show()
  expect(await screen.findByRole('alert')).toHaveTextContent(
    messages.common.errorMessage
  )
  expect(screen.getByRole('button', { name: 'Retry' })).toBeInTheDocument()
  expect(api).not.toHaveBeenCalled()
})

it('sends the plan and language captured with the displayed set', async () => {
  await show()
  fireEvent.click(
    await screen.findByRole('button', { name: 'Add All 1 to Flashcards' })
  )
  await screen.findByText(/1 cards added/)
  const call = api.mock.calls.find(([url]) => url === '/api/flashcards/bulk')!
  expect(JSON.parse(call[1].body)).toMatchObject({
    study_plan_id: 42,
    target_language: 'en-GB',
    flashcards: [{ word: 'hello' }],
  })
})

it('handles a different server-side language by reloading without automatically adding the new set', async () => {
  await show()
  await screen.findByText('hello')
  serverLanguage = 'it-IT' // Another tab switched the server context; this store is still English.
  fireEvent.click(
    screen.getByRole('button', { name: 'Add All 1 to Flashcards' })
  )
  expect(await screen.findByText('ciao')).toBeInTheDocument()
  expect(
    screen.getByText(messages.flashcards.contextChanged)
  ).toBeInTheDocument()
  const calls = api.mock.calls.filter(([url]) => url === '/api/flashcards/bulk')
  expect(calls).toHaveLength(1)
  expect(JSON.parse(calls[0][1].body)).toMatchObject({
    study_plan_id: 42,
    target_language: 'en-GB',
  })
  fireEvent.click(
    screen.getByRole('button', { name: 'Add All 1 to Flashcards' })
  )
  await screen.findByText(/1 cards added/)
  expect(
    JSON.parse(
      api.mock.calls.filter(([url]) => url === '/api/flashcards/bulk')[1][1]
        .body
    )
  ).toMatchObject({
    study_plan_id: 43,
    target_language: 'it-IT',
    flashcards: [{ word: 'ciao' }],
  })
})

it('ignores late set content from the previous language', async () => {
  let resolveOld!: (body: unknown) => void
  const old = new Promise((resolve) => {
    resolveOld = resolve
  })
  const first = api.getMockImplementation()!
  api.mockImplementation(async (url: string, options?: RequestInit) =>
    url.includes('language=en-GB')
      ? { ok: true, json: () => old }
      : first(url, options)
  )
  await show()
  await waitFor(() =>
    expect(api).toHaveBeenCalledWith(
      expect.stringContaining('language=en-GB'),
      expect.anything()
    )
  )
  serverLanguage = 'it-IT'
  act(() =>
    useLanguageStore.setState({ activeLanguage: getLanguageByCode('it-IT')! })
  )
  await screen.findByText('ciao')
  await act(async () => resolveOld(vocabulary('en-GB')))
  expect(screen.queryByText('hello')).not.toBeInTheDocument()
  fireEvent.click(
    screen.getByRole('button', { name: 'Add All 1 to Flashcards' })
  )
  await screen.findByText(/1 cards added/)
  const body = JSON.parse(
    api.mock.calls.find(([url]) => url === '/api/flashcards/bulk')![1].body
  )
  expect(body.flashcards[0].word).toBe('ciao')
  expect(body.study_plan_id).toBe(43)
})

it('still allows browsing a set without an active plan and does not send an unbound import', async () => {
  const first = api.getMockImplementation()!
  api.mockImplementation(async (url: string, options?: RequestInit) =>
    url === '/api/study-plan/current' ? json(null) : first(url, options)
  )
  await show()
  await screen.findByText('hello')
  fireEvent.click(
    screen.getByRole('button', { name: 'Add All 1 to Flashcards' })
  )
  expect(screen.getByText(messages.common.noActivePlan)).toBeInTheDocument()
  expect(api.mock.calls.some(([url]) => url === '/api/flashcards/bulk')).toBe(
    false
  )
})
