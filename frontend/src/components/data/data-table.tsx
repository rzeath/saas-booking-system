import type { PropsWithChildren } from 'react'

import { cn } from '@/lib/utils'

export function DataPanel({ children, className }: PropsWithChildren<{ className?: string }>) {
  return <div className={cn('overflow-hidden rounded-xl border border-border bg-surface shadow-sm', className)}>{children}</div>
}

export function TableScroll({ children, className }: PropsWithChildren<{ className?: string }>) {
  return <div className={cn('overflow-x-auto', className)}>{children}</div>
}

export const tableClassName = 'w-full text-left text-sm'
export const tableHeadClassName = 'border-b border-border bg-surface-subtle text-xs font-semibold uppercase tracking-wide text-muted'
export const tableBodyClassName = 'divide-y divide-border'
export const tableHeaderCellClassName = 'whitespace-nowrap px-5 py-3'
export const tableCellClassName = 'px-5 py-4 align-middle'
