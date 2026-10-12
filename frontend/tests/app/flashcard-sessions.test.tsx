import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { NextIntlClientProvider } from 'next-intl'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import messages from '../../../messages/en.json'
import FlashcardsPage from '@/app/(app)/flashcards/page'
import { useLanguageStore } from '@/store/language'
import { useAuthStore } from '@/store/auth'
import { getLanguageByCode } from '@/lib/target-languages'
import { maskFlashcardExample } from '@/lib/flashcard-review'
import type { Flashcard } from '@/components/flashcards/types'

const { api, speechCallbacks } = vi.hoisted(() => ({
  api: vi.fn(),
  speechCallbacks: [] as ((text: string) => void)[],
}))
vi.mock('@/lib/api', () => ({ apiFetch: api }))
vi.mock('@/components/ui/AudioPlayer', () => ({ AudioPlayer: () => null }))
vi.mock('@/components/ui/page-loading', () => ({
  PageLoading: () => <p>Loading</p>,
}))
vi.mock('@/components/ui/VoiceRecorder', () => ({
  VoiceRecorder: ({
    onTranscription,
    disabled,
  }: {
    onTranscription: (text: string) => void
    disabled: boolean
  }) => {
    speechCallbacks.push(onTranscription)
    return (
      <div>
        {['book', 'wrong', ''].map((text) => (
          <button
            key={text}
            disabled={disabled}
            onClick={() => onTranscription(text)}
          >
            Record {text || 'empty'}
          </button>
        ))}
      </div>
    )
  },
}))

const card: Flashcard = {
  id: 1,
  study_plan_id: 42,
  word: 'book',
  definition: 'An object with pages',
  translation: 'libro',
  example_sentence: 'I read a book.',
  source: null,
  is_suspended: false,
  ease_factor: 2.5,
  interval: 0,
  repetitions: 0,
  next_review: '2026-10-12',
}
let due: Flashcard[]
const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), { status })

beforeEach(() => {
  api.mockReset()
  speechCallbacks.length = 0
  due = [card]
  useLanguageStore.setState({
    activeLanguage: getLanguageByCode('en-GB')!,
    userLanguages: [],
    isSwitching: false,
    needsRefresh: false,
    invalidationVersion: 0,
  })
  useAuthStore.setState({ sessionVersion: 0 })
  api.mockImplementation(async (url: string, options?: RequestInit) => {
    if (url === '/api/flashcards/due')
      return json({ due, total: 7, cefr_level: 'C1' })
    if (url === '/api/flashcards/preferences')
      return json({ back_content: 'definition' })
    if (url.endsWith('/review')) {
      const id = Number(url.split('/')[3])
      due = due.filter((item) => item.id !== id)
      return json({ ...card, id, xp_earned: 2 })
    }
    if (url.endsWith('/suspension'))
      return json({ ...card, is_suspended: true })
    if (options?.method === 'PUT')
      return json({ ...card, ...JSON.parse(String(options.body)) })
    throw new Error(`Unexpected request ${url}`)
  })
})

function show() {
  render(
    <NextIntlClientProvider locale="en" messages={messages} timeZone="UTC">
      <FlashcardsPage />
    </NextIntlClientProvider>
  )
}
async function start(size = '10') {
  await screen.findByRole('button', { name: 'Start review' })
  const choice = screen.getByRole('radio', {
    name: size === 'all' ? 'All due cards' : `${size} cards`,
  })
  fireEvent.click(choice)
  expect(choice).toBeChecked()
  fireEvent.click(screen.getByRole('button', { name: 'Start review' }))
}
const reviews = () => api.mock.calls.filter(([url]) => url.endsWith('/review'))
function metric(label: string) {
  return screen.getByText(label).nextElementSibling
}

describe('review sessions', () => {
  it('reviews only the selected five cards and summarises confirmed results and remaining work', async () => {
    due = Array.from({ length: 7 }, (_, index) => ({
      ...card,
      id: index + 1,
      word: `word${index}`,
      interval: index < 2 ? 0 : 6,
      repetitions: index < 2 ? 0 : 2,
    }))
    show()
    expect(
      await screen.findByText('Available: 2 new · 5 to review')
    ).toBeInTheDocument()
    expect(reviews()).toHaveLength(0)
    await start('5')
    for (let index = 0; index < 5; index++) {
      await screen.findByText(`word${index}`)
      const progress = screen.getByRole('progressbar', {
        name: 'Session progress',
      })
      expect(progress).toHaveAttribute('aria-valuenow', String(index))
      expect(progress).toHaveAttribute('aria-valuemax', '5')
      fireEvent.click(screen.getByRole('button', { name: 'tap to reveal' }))
      fireEvent.click(
        screen.getByRole('button', { name: index === 1 ? 'Again' : 'Good' })
      )
    }
    await screen.findByRole('heading', { name: 'Session summary' })
    expect(metric('Cards reviewed')).toHaveTextContent('5')
    expect(metric('XP earned')).toHaveTextContent('10')
    expect(metric('Still due today')).toHaveTextContent('2')
    expect(screen.getByText('word1')).toBeInTheDocument()
    expect(reviews()).toHaveLength(5)
    fireEvent.click(
      screen.getByRole('button', { name: 'Prepare another review' })
    )
    expect(
      await screen.findByText('Available: 0 new · 2 to review')
    ).toBeInTheDocument()
  })

  it('supports all pending cards and finishing early without crediting unreviewed cards', async () => {
    due = Array.from({ length: 12 }, (_, index) => ({ ...card, id: index + 1 }))
    show()
    await start('all')
    expect(screen.getByText('1 / 12 · New')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Finish session' }))
    expect(metric('Cards reviewed')).toHaveTextContent('0')
    expect(metric('XP earned')).toHaveTextContent('0')
    expect(metric('Still due today')).toHaveTextContent('12')
    expect(reviews()).toHaveLength(0)
  })

  it('does not count a suspended last card as a review', async () => {
    show()
    await start()
    fireEvent.click(screen.getByRole('button', { name: 'Suspend' }))
    await screen.findByRole('heading', { name: 'Session summary' })
    expect(metric('Cards reviewed')).toHaveTextContent('0')
    expect(metric('XP earned')).toHaveTextContent('0')
    expect(metric('Still due today')).toHaveTextContent('0')
    expect(
      screen.getByText(/Cards deleted or suspended during this session: 1/)
    ).toBeInTheDocument()
  })

  it('does not count failed saves and clears the summary on a language change', async () => {
    show()
    await start()
    fireEvent.click(screen.getByRole('button', { name: 'tap to reveal' }))
    api.mockResolvedValueOnce(json({}, 503))
    fireEvent.click(screen.getByRole('button', { name: 'Good' }))
    await screen.findByRole('alert')
    expect(
      screen.getByRole('progressbar', { name: 'Session progress' })
    ).toHaveAttribute('aria-valuenow', '0')
    expect(
      screen.getByRole('button', { name: 'Finish session' })
    ).toBeDisabled()
    expect(
      screen.queryByRole('heading', { name: 'Session summary' })
    ).not.toBeInTheDocument()
    act(() =>
      useLanguageStore.setState({ activeLanguage: getLanguageByCode('it-IT')! })
    )
    expect(
      await screen.findByRole('button', { name: 'Start review' })
    ).toBeInTheDocument()
    expect(
      screen.queryByRole('heading', { name: 'Session summary' })
    ).not.toBeInTheDocument()
  })
})

describe('speaking feedback', () => {
  it('hides the answer, shows transcription, retries and saves exactly one confirmed review', async () => {
    show()
    await start()
    fireEvent.click(screen.getByRole('button', { name: 'Speaking' }))
    expect(screen.getByText('I read a ____.')).toBeInTheDocument()
    expect(screen.queryByText(card.example_sentence)).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Record wrong' }))
    expect(
      screen.getByText('The transcription does not match')
    ).toBeInTheDocument()
    expect(screen.getByText('wrong')).toBeInTheDocument()
    expect(screen.getByText('book')).toBeInTheDocument()
    expect(reviews()).toHaveLength(0)
    fireEvent.click(screen.getByRole('button', { name: 'Retry' }))
    expect(screen.queryByText(card.example_sentence)).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Record book' }))
    expect(screen.getByText('The transcription matches')).toBeInTheDocument()
    expect(reviews()).toHaveLength(0)
    fireEvent.click(
      screen.getByRole('button', { name: 'Save result and continue' })
    )
    await screen.findByRole('heading', { name: 'Session summary' })
    expect(reviews()).toHaveLength(1)
    expect(JSON.parse(reviews()[0][1].body)).toMatchObject({
      quality: 5,
      review_id: expect.any(String),
    })
    expect(metric('XP earned')).toHaveTextContent('2')
  })

  it.each([
    ['Save result and continue', 2],
    ['I said it correctly', 5],
  ])('supports %s for mismatched recognition', async (label, quality) => {
    show()
    await start()
    fireEvent.click(screen.getByRole('button', { name: 'Speaking' }))
    fireEvent.click(screen.getByRole('button', { name: 'Record wrong' }))
    fireEvent.click(screen.getByRole('button', { name: label as string }))
    await screen.findByRole('heading', { name: 'Session summary' })
    expect(reviews()).toHaveLength(1)
    expect(JSON.parse(reviews()[0][1].body)).toMatchObject({
      quality,
      review_id: expect.any(String),
    })
  })

  it('retains feedback after a failed save and rejects empty transcription', async () => {
    show()
    await start()
    fireEvent.click(screen.getByRole('button', { name: 'Speaking' }))
    fireEvent.click(screen.getByRole('button', { name: 'Record empty' }))
    expect(screen.getByRole('alert')).toHaveTextContent(
      messages.flashcards.speechEmpty
    )
    expect(
      screen.queryByRole('button', { name: 'Save result and continue' })
    ).not.toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Record book' }))
    api.mockResolvedValueOnce(json({}, 503))
    fireEvent.click(
      screen.getByRole('button', { name: 'Save result and continue' })
    )
    expect(await screen.findByRole('alert')).toHaveTextContent(
      messages.common.errorMessage
    )
    expect(screen.getByText('The transcription matches')).toBeInTheDocument()
    expect(
      screen.getByRole('button', { name: 'Save result and continue' })
    ).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Retry saving' })).toBeEnabled()
  })

  it('discards old speech feedback after editing the card or switching language', async () => {
    show()
    await start()
    fireEvent.click(screen.getByRole('button', { name: 'Speaking' }))
    const oldTranscription = speechCallbacks.at(-1)!
    fireEvent.click(screen.getByRole('button', { name: 'Record book' }))
    fireEvent.click(screen.getByRole('button', { name: 'Edit card' }))
    fireEvent.change(screen.getByLabelText('Word'), {
      target: { value: 'novel' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
    await waitFor(() =>
      expect(screen.queryByRole('form')).not.toBeInTheDocument()
    )
    expect(
      screen.queryByText('The transcription matches')
    ).not.toBeInTheDocument()
    act(() => oldTranscription('book'))
    expect(
      screen.queryByText('The transcription matches')
    ).not.toBeInTheDocument()
    act(() =>
      useLanguageStore.setState({ activeLanguage: getLanguageByCode('it-IT')! })
    )
    await screen.findByRole('button', { name: 'Start review' })
    act(() => oldTranscription('book'))
    expect(reviews()).toHaveLength(0)
  })
})

describe('generation feedback', () => {
  it('starts at the plan level, preserves a chosen level and displays actual counts', async () => {
    show()
    fireEvent.click(await screen.findByRole('button', { name: '+ Generate' }))
    expect(screen.getByLabelText('Level')).toHaveValue('C1')
    fireEvent.change(screen.getByLabelText('Level'), {
      target: { value: 'B2' },
    })
    fireEvent.change(screen.getByLabelText('Topic'), {
      target: { value: 'travel' },
    })
    api.mockResolvedValueOnce(
      json({
        flashcards: [],
        created: 2,
        skipped_duplicates: 3,
        skipped_empty: 1,
      })
    )
    fireEvent.click(screen.getByRole('button', { name: 'Generate' }))
    expect(
      await screen.findByText('Cards created: 2. Duplicates skipped: 3.')
    ).toBeInTheDocument()
    expect(
      screen.getByText('Another 1 results without a valid word were skipped.')
    ).toBeInTheDocument()
    const generated = api.mock.calls.find(([url]) => url.endsWith('/generate'))!
    expect(JSON.parse(generated[1].body)).toMatchObject({
      topic: 'travel',
      count: 10,
      cefr_level: 'B2',
    })
    fireEvent.click(screen.getByRole('button', { name: '+ Generate' }))
    expect(screen.getByLabelText('Level')).toHaveValue('B2')
  })

  it('reports a zero-card generation without turning it into an error', async () => {
    show()
    fireEvent.click(await screen.findByRole('button', { name: '+ Generate' }))
    fireEvent.change(screen.getByLabelText('Topic'), {
      target: { value: 'travel' },
    })
    api.mockResolvedValueOnce(
      json({
        flashcards: [],
        created: 0,
        skipped_duplicates: 4,
        skipped_empty: 0,
      })
    )
    fireEvent.click(screen.getByRole('button', { name: 'Generate' }))
    expect(
      await screen.findByText('Cards created: 0. Duplicates skipped: 4.')
    ).toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })
})

describe('speaking example masking', () => {
  it.each([
    ['Book, book!', 'book', '____, ____!'],
    ['I take   off today.', 'take off', 'I ____ today.'],
    ['猫が好きです。猫です。', '猫', '____が好きです。____です。'],
    ['I use C++.', 'C++', 'I use ____.'],
    ['A cafe\u0301.', 'café', 'A ____.'],
    ['The houses are big.', 'house', null],
    ['The weather.', 'he', null],
  ])('masks safely in %s', (example, word, expected) => {
    expect(maskFlashcardExample(example, word)).toBe(expected)
  })
})
