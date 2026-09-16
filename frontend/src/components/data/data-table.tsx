import type { PropsWithChildren } from 'react'

import { cn } from '@/lib/utils'

export function DataPanel({ children, className }: PropsWithChildren<{ className?: string }>) {
  return <div className={cn('overflow-hidden rounded-xl border border-border bg-surface', className)}>{children}</div>
}

export function TableScroll({ children, className }: PropsWithChildren<{ className?: string }>) {
  return <div className={cn('overflow-x-auto', className)}>{children}</div>
}

export const tableClassName = 'w-full text-left text-[13px]'
export const tableHeadClassName = 'border-b border-border bg-surface-subtle text-[10px] font-semibold uppercase tracking-[0.08em] text-muted'
export const tableBodyClassName = 'divide-y divide-border [&>tr]:transition-colors [&>tr:hover]:bg-surface-subtle/70'
export const tableHeaderCellClassName = 'whitespace-nowrap px-5 py-2.5'
export const tableCellClassName = 'px-5 py-3.5 align-middle'
