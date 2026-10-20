'use client'

import { useEffect, useState } from 'react'
import { useTranslations } from 'next-intl'

interface Props {
  /** Local deadline derived from the backend session_warning message. */
  deadline: number
}

export default function SessionTimeoutBanner({ deadline }: Props) {
  const t = useTranslations('conversation')
  const [remaining, setRemaining] = useState(() =>
    Math.max(0, Math.ceil((deadline - Date.now()) / 1000))
  )

  useEffect(() => {
    const update = () =>
      setRemaining(Math.max(0, Math.ceil((deadline - Date.now()) / 1000)))
    update()
    const id = setInterval(update, 1000)
    return () => clearInterval(id)
  }, [deadline])

  return (
    <div className="border-fl-error/40 bg-fl-surface text-fl-error mb-4 flex items-center gap-3 border px-4 py-3 font-mono text-xs">
      <span className="animate-pulse">▲</span>
      <span>{t('warningTimeout', { seconds: remaining })}</span>
    </div>
  )
}
