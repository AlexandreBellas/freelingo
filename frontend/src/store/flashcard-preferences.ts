import { createStore } from 'zustand/vanilla'
import { apiFetch } from '@/lib/api'
import { useAuthStore } from '@/store/auth'
import type { BackContent } from '@/components/flashcards/types'

interface FlashcardPreferences {
  value: BackContent
  loading: boolean
  loaded: boolean
  error: boolean
  load: () => Promise<void>
  save: (value: BackContent) => Promise<void>
}

function createPreferences(sessionVersion: number) {
  // Shared by all views in this account session, including while none are mounted.
  let pending = false
  return createStore<FlashcardPreferences>((set, get) => {
    const isCurrent = () =>
      useAuthStore.getState().sessionVersion === sessionVersion
    async function request(value?: BackContent) {
      if (pending || !isCurrent()) return
      pending = true
      set({ loading: true, error: false })
      try {
        const response = await apiFetch(
          '/api/flashcards/preferences',
          value === undefined
            ? {}
            : {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ back_content: value }),
              }
        )
        if (!response.ok) throw new Error('Preferences unavailable')
        const data = (await response.json()) as { back_content: BackContent }
        if (isCurrent()) set({ value: data.back_content, loaded: true })
      } catch {
        if (isCurrent()) set({ error: true })
      } finally {
        pending = false
        if (isCurrent()) set({ loading: false })
      }
    }
    return {
      value: 'definition',
      loading: true,
      loaded: false,
      error: false,
      load: () => request(),
      save: async (value) => {
        if (get().loaded) await request(value)
      },
    }
  })
}

let current:
  | { sessionVersion: number; store: ReturnType<typeof createPreferences> }
  | undefined

export function getFlashcardPreferences(sessionVersion: number) {
  if (!current || current.sessionVersion !== sessionVersion) {
    current = { sessionVersion, store: createPreferences(sessionVersion) }
  }
  return current.store
}
