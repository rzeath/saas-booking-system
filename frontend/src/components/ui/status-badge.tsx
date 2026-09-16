import type { PropsWithChildren } from 'react'

import type { BillingPaymentStatus, BookingStatus, PaymentStatus, QuotationStatus } from '@/lib/api'
import { paymentStatusLabel } from '@/lib/billing-format'
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
    <span className={cn('inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset before:size-1.5 before:rounded-full before:bg-current', toneClasses[tone], className)}>
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

const quotationTone: Record<QuotationStatus, StatusTone> = {
  DRAFT: 'neutral',
  SENT: 'info',
  ACCEPTED: 'success',
  REJECTED: 'danger',
  CANCELLED: 'danger',
  EXPIRED: 'warning',
  OUTDATED: 'warning',
}

export function QuotationStatusBadge({ status }: { status: QuotationStatus }) {
  const label = status.charAt(0) + status.slice(1).toLowerCase()
  return <StatusBadge tone={quotationTone[status]}>{label}</StatusBadge>
}

const billingPaymentTone: Record<BillingPaymentStatus, StatusTone> = {
  UNPAID: 'warning',
  PARTIALLY_PAID: 'info',
  PAID: 'success',
}

export function BillingPaymentStatusBadge({ status }: { status: BillingPaymentStatus }) {
  return <StatusBadge tone={billingPaymentTone[status]}>{paymentStatusLabel(status)}</StatusBadge>
}

export function PaymentStatusBadge({ status }: { status: PaymentStatus }) {
  return <StatusBadge tone={status === 'POSTED' ? 'success' : 'neutral'}>{status === 'POSTED' ? 'Posted' : 'Voided'}</StatusBadge>
}
