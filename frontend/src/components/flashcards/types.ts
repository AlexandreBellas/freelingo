export interface Flashcard {
  id: number
  study_plan_id: number
  word: string
  definition: string
  example_sentence: string
  translation: string
  source: string | null
  is_suspended: boolean
  ease_factor: number
  interval: number
  repetitions: number
  next_review: string
}

export type BackContent = 'definition' | 'translation'
