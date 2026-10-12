import { act, renderHook, waitFor } from '@testing-library/react'
import { beforeEach, expect, it, vi } from 'vitest'
import { useBackPreference } from '@/components/flashcards/BackPreference'
import { useAuthStore } from '@/store/auth'

const { api } = vi.hoisted(() => ({ api: vi.fn() }))
vi.mock('@/lib/api', () => ({ apiFetch: api }))
function deferred() {
  let resolve!: (response: Response) => void
  const promise = new Promise<Response>((done) => {
    resolve = done
  })
  return { promise, resolve }
}
const json = (value: string, status = 200) =>
  new Response(JSON.stringify({ back_content: value }), { status })

beforeEach(() => {
  api.mockReset()
  useAuthStore.getState().startSession('token')
})

it('shares a pending read and blocks competing writes until it settles', async () => {
  const read = deferred()
  api.mockReturnValueOnce(read.promise)
  const first = renderHook(() => useBackPreference(true))
  const second = renderHook(() => useBackPreference(true))
  await act(async () => second.result.current.save('translation'))
  expect(api).toHaveBeenCalledTimes(1)
  first.unmount()
  await act(async () => read.resolve(json('translation')))
  expect(second.result.current.value).toBe('translation')
  expect(second.result.current.loading).toBe(false)
})

it('keeps a failed write recoverable in a new view and reloads the persisted value', async () => {
  const write = deferred()
  api
    .mockResolvedValueOnce(json('definition'))
    .mockReturnValueOnce(write.promise)
    .mockResolvedValueOnce(json('translation'))
  const first = renderHook(() => useBackPreference(true))
  await waitFor(() => expect(first.result.current.loaded).toBe(true))
  act(() => {
    void first.result.current.save('translation')
  })
  first.unmount()
  const next = renderHook(() => useBackPreference(true))
  expect(api).toHaveBeenCalledTimes(2)
  // The write may have persisted despite an unsuccessful HTTP response.
  await act(async () => write.resolve(json('', 503)))
  expect(next.result.current.error).toBe(true)
  expect(next.result.current.loading).toBe(false)
  await act(async () => next.result.current.retry())
  expect(next.result.current.value).toBe('translation')
  expect(next.result.current.error).toBe(false)
})

it('does not apply a previous account write or unlock a new account request', async () => {
  const oldWrite = deferred()
  const newRead = deferred()
  api
    .mockResolvedValueOnce(json('definition'))
    .mockReturnValueOnce(oldWrite.promise)
    .mockReturnValueOnce(newRead.promise)
  const view = renderHook(() => useBackPreference(true))
  await waitFor(() => expect(view.result.current.loaded).toBe(true))
  act(() => {
    void view.result.current.save('translation')
  })
  act(() => useAuthStore.getState().startSession('other-account'))
  await act(async () => oldWrite.resolve(json('translation')))
  expect(view.result.current.loading).toBe(true)
  expect(view.result.current.loaded).toBe(false)
  expect(view.result.current.value).toBe('definition')
  await act(async () => newRead.resolve(json('definition')))
  expect(view.result.current.value).toBe('definition')
  expect(view.result.current.loaded).toBe(true)
})

it('serializes writes across views and revalidates on a later visit', async () => {
  const write = deferred()
  api
    .mockResolvedValueOnce(json('definition'))
    .mockReturnValueOnce(write.promise)
    .mockResolvedValueOnce(json('definition'))
  const first = renderHook(() => useBackPreference(true))
  await waitFor(() => expect(first.result.current.loaded).toBe(true))
  act(() => {
    void first.result.current.save('translation')
  })
  const second = renderHook(() => useBackPreference(true))
  await act(async () => second.result.current.save('definition'))
  expect(api).toHaveBeenCalledTimes(2)
  await act(async () => write.resolve(json('translation')))
  expect(first.result.current.value).toBe('translation')
  expect(second.result.current.value).toBe('translation')
  first.unmount()
  second.unmount()
  const later = renderHook(() => useBackPreference(true))
  await waitFor(() => expect(later.result.current.loading).toBe(false))
  expect(later.result.current.value).toBe('definition')
  expect(api).toHaveBeenCalledTimes(3)
})
