import type { ReactNode } from 'react'

export default function LegalLayout({ children }: { children: ReactNode }) {
  return (
    <main className="bg-fl-bg min-h-screen px-4 py-12">
      <div className="mx-auto max-w-2xl">{children}</div>
    </main>
  )
}
