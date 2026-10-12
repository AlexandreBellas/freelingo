export function normalizeFlashcardSpeech(value: string): string {
  return value
    .trim()
    .toLowerCase()
    .replace(/[\p{P}\p{S}\s]+/gu, '')
}

// If the example uses an inflection we cannot mask reliably, keep it hidden until
// feedback rather than exposing a possible answer. CJK terms need no word spaces.
export function maskFlashcardExample(
  example: string,
  word: string
): string | null {
  const term = word.trim().normalize('NFC')
  if (!term || !example.trim()) return null
  const escaped = term
    .split(/\s+/u)
    .map((part) => part.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
    .join('\\s+')
  const cjk =
    /[\p{Script=Han}\p{Script=Hiragana}\p{Script=Katakana}\p{Script=Hangul}]/u.test(
      term
    )
  const pattern = cjk
    ? escaped
    : `(?<![\\p{L}\\p{M}\\p{N}])${escaped}(?![\\p{L}\\p{M}\\p{N}])`
  let matched = false
  const masked = example
    .normalize('NFC')
    .replace(new RegExp(pattern, 'giu'), () => {
      matched = true
      return '____'
    })
  return matched ? masked : null
}
