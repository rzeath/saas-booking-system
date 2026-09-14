import type { PropsWithChildren, ReactNode } from 'react'

import { cn } from '@/lib/utils'

export function Page({ children, className }: PropsWithChildren<{ className?: string }>) {
  return <section className={cn('mx-auto w-full max-w-[1440px]', className)}>{children}</section>
}

export function PageHeader({
  eyebrow,
  title,
  description,
  actions,
}: {
  eyebrow?: string
  title: string
  description?: string
  actions?: ReactNode
}) {
  return (
    <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div className="min-w-0">
        {eyebrow ? <p className="text-xs font-semibold uppercase tracking-[0.12em] text-primary">{eyebrow}</p> : null}
        <h1 className={cn('text-2xl font-bold tracking-tight text-foreground sm:text-3xl', eyebrow && 'mt-2')}>{title}</h1>
        {description ? <p className="mt-2 max-w-3xl text-sm leading-6 text-muted">{description}</p> : null}
      </div>
      {actions ? <div className="flex shrink-0 flex-wrap gap-2">{actions}</div> : null}
    </header>
  )
}
