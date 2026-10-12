import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { apiFetch, requestAccessToken, restoreAccessToken } from '@/lib/api'
import { loadAvatar, clearAvatarCache } from '@/lib/avatar-cache'
import { getFlashcardPreferences } from '@/store/flashcard-preferences'
import { useAuthStore, type User } from '@/store/auth'
import { useLoadingStore } from '@/store/loading'

const user: User = {
  id: 1,
  username: 'account-a',
  displayName: 'A',
  role: 'user',
  conversation_max_duration: 300,
  conversation_inactivity_timeout: 30,
}
const json = (value: unknown, status = 200) =>
  new Response(JSON.stringify(value), { status })
function deferred() {
  let resolve!: (response: Response) => void
  const promise = new Promise<Response>((done) => {
    resolve = done
  })
  return { promise, resolve }
}

beforeEach(() => {
  vi.stubGlobal('fetch', vi.fn())
  window.history.pushState({}, '', '/login')
  useAuthStore.getState().startSession('access-a')
  useAuthStore.getState().setUser(user)
  useLoadingStore.setState({ count: 0 })
  clearAvatarCache()
})
afterEach(() => {
  clearAvatarCache()
  vi.unstubAllGlobals()
})

it('does not retarget an A preference write when only the shared cookie changes to B', async () => {
  const preferences = { a: 'definition', b: 'definition' }
  let cookieAccount = 'a'
  const initial = useAuthStore.getState().sessionVersion
  vi.mocked(fetch).mockImplementation(async (url, options) => {
    const headers = new Headers(options?.headers)
    if (url === '/api/auth/refresh') {
      expect(options?.credentials).toBe('include')
      // Model the backend's cookie/expected-account contract, not a local login.
      if (
        cookieAccount === 'b' &&
        (headers.get('Authorization') === 'Bearer access-a' ||
          headers.get('X-Expected-User-ID') === '1')
      ) {
        return json({ detail: 'auth_context_changed' }, 409)
      }
      return json({ access_token: `access-${cookieAccount}` })
    }
    if (url === '/api/flashcards/preferences') {
      if (options?.method !== 'PUT')
        return json({ back_content: preferences.a })
      if (headers.get('Authorization') === 'Bearer access-a')
        return json({}, 401)
      preferences.b = JSON.parse(String(options?.body)).back_content
      return json({ back_content: preferences.b })
    }
    throw new Error(`Unexpected request ${String(url)}`)
  })
  const store = getFlashcardPreferences(initial)
  await store.getState().load()
  cookieAccount = 'b' // Another tab logs in; this tab's session and profile remain A.
  await store.getState().save('translation')
  expect(preferences).toEqual({ a: 'definition', b: 'definition' })
  expect(cookieAccount).toBe('b')
  expect(useAuthStore.getState()).toMatchObject({
    sessionVersion: initial + 1,
    user: null,
    accessToken: null,
  })
  expect(
    vi
      .mocked(fetch)
      .mock.calls.filter(
        ([url, options]) =>
          url === '/api/flashcards/preferences' && options?.method === 'PUT'
      )
  ).toHaveLength(1)
  expect(fetch).toHaveBeenCalledWith(
    '/api/auth/refresh',
    expect.objectContaining({
      headers: { Authorization: 'Bearer access-a', 'X-Expected-User-ID': '1' },
    })
  )
  expect(
    vi.mocked(fetch).mock.calls.some(([url]) => url === '/api/auth/logout')
  ).toBe(false)
  expect(useLoadingStore.getState().count).toBe(0)
})

it('binds renewal to the bearer before the profile has loaded', async () => {
  useAuthStore.setState({ user: null })
  vi.mocked(fetch).mockResolvedValueOnce(
    json({ detail: 'auth_context_changed' }, 409)
  )
  await expect(requestAccessToken()).rejects.toMatchObject({
    name: 'AbortError',
  })
  expect(fetch).toHaveBeenCalledWith('/api/auth/refresh', {
    method: 'POST',
    credentials: 'include',
    headers: { Authorization: 'Bearer access-a' },
  })
  expect(useAuthStore.getState().accessToken).toBeNull()
})

it('binds restoration to a known profile even when its access token is absent', async () => {
  useAuthStore.setState({ accessToken: null })
  vi.mocked(fetch).mockResolvedValueOnce(
    json({ detail: 'auth_context_changed' }, 409)
  )
  await expect(restoreAccessToken()).rejects.toMatchObject({
    name: 'AbortError',
  })
  expect(fetch).toHaveBeenCalledWith('/api/auth/refresh', {
    method: 'POST',
    credentials: 'include',
    headers: { 'X-Expected-User-ID': '1' },
  })
  expect(useAuthStore.getState().user).toBeNull()
})

it('permits cookie restoration on a fresh page with no previous identity', async () => {
  useAuthStore.setState({ accessToken: null, user: null })
  vi.mocked(fetch).mockResolvedValueOnce(json({ access_token: 'access-b' }))
  expect(await restoreAccessToken()).toBe('access-b')
  expect(fetch).toHaveBeenCalledWith('/api/auth/refresh', {
    method: 'POST',
    credentials: 'include',
  })
  expect(useAuthStore.getState().accessToken).toBe('access-b')
})

it('shares the identity rejection across consumers without repeating any mutation', async () => {
  const initial = useAuthStore.getState().sessionVersion
  const pending = deferred()
  vi.mocked(fetch).mockImplementation(async (url) =>
    url === '/api/auth/refresh' ? pending.promise : json({}, 401)
  )
  const first = apiFetch('/api/first', { method: 'POST' })
  const second = apiFetch('/api/second', { method: 'DELETE' })
  const rejectedFirst = expect(first).rejects.toMatchObject({
    name: 'AbortError',
  })
  const rejectedSecond = expect(second).rejects.toMatchObject({
    name: 'AbortError',
  })
  await vi.waitFor(() =>
    expect(
      vi.mocked(fetch).mock.calls.filter(([url]) => url === '/api/auth/refresh')
    ).toHaveLength(1)
  )
  const direct = requestAccessToken()
  const rejectedDirect = expect(direct).rejects.toMatchObject({
    name: 'AbortError',
  })
  pending.resolve(json({ detail: 'auth_context_changed' }, 409))
  await Promise.all([rejectedFirst, rejectedSecond, rejectedDirect])
  expect(useAuthStore.getState().sessionVersion).toBe(initial + 1)
  expect(fetch).toHaveBeenCalledTimes(3)
  expect(useLoadingStore.getState().count).toBe(0)
})

it('does not invalidate a newer local login when an old identity conflict arrives', async () => {
  const pending = deferred()
  vi.mocked(fetch).mockReturnValueOnce(pending.promise)
  const old = requestAccessToken()
  const rejected = expect(old).rejects.toMatchObject({ name: 'AbortError' })
  useAuthStore.getState().startSession('new-local-login')
  const current = useAuthStore.getState().sessionVersion
  pending.resolve(json({ detail: 'auth_context_changed' }, 409))
  await rejected
  expect(useAuthStore.getState().sessionVersion).toBe(current)
  expect(useAuthStore.getState().accessToken).toBe('new-local-login')
})

it('blocks avatar retry under another account through the same renewal contract', async () => {
  vi.mocked(fetch)
    .mockResolvedValueOnce(json({}, 401))
    .mockResolvedValueOnce(json({ detail: 'auth_context_changed' }, 409))
  expect(await loadAvatar('/avatar-a', 'access-a')).toBeNull()
  expect(fetch).toHaveBeenCalledTimes(2)
  expect(useAuthStore.getState().user).toBeNull()
  expect(useAuthStore.getState().accessToken).toBeNull()
})
