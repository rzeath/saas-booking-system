import type { PropsWithChildren } from 'react'

import type { BookingStatus } from '@/lib/api'
import { cn } from '@/lib/utils'

type StatusTone = 'neutral' | 'success' | 'warning' | 'danger' | 'info'

const toneClasses: Record<StatusTone, string> = {
  neutral: 'bg-surface-subtle text-muted ring-border',
  success: 'bg-success-soft text-success ring-green-200',
  warning: 'bg-warning-soft text-warning ring-amber-200',
  danger: 'bg-danger-soft text-danger ring-red-200',
  info: 'bg-info-soft text-info ring-sky-200',
}

export function StatusBadge({ children, tone = 'neutral', className }: PropsWithChildren<{ tone?: StatusTone; className?: string }>) {
  return (
    <span className={cn('inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset', toneClasses[tone], className)}>
      {children}
    </span>
  )
}

const bookingTone: Record<BookingStatus, StatusTone> = {
  PENDING: 'warning',
  QUOTED: 'info',
  CONFIRMED: 'success',
  COMPLETED: 'neutral',
  CANCELLED: 'danger',
}

export function BookingStatusBadge({ status }: { status: BookingStatus }) {
  const label = status.charAt(0) + status.slice(1).toLowerCase()
  return <StatusBadge tone={bookingTone[status]}>{label}</StatusBadge>
}
