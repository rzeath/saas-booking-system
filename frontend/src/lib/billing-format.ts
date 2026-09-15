import type { BillingPaymentStatus, PaymentMethod } from '@/lib/api'

export function paymentMethodLabel(method: PaymentMethod): string {
  return {
    CASH: 'Cash',
    GCASH: 'GCash',
    BANK_TRANSFER: 'Bank Transfer',
    CHECK: 'Check',
  }[method]
}

export function paymentStatusLabel(status: BillingPaymentStatus): string {
  return {
    UNPAID: 'Unpaid',
    PARTIALLY_PAID: 'Partially paid',
    PAID: 'Paid',
  }[status]
}

export function moneyToCents(value: string): bigint | null {
  const match = value.trim().match(/^(\d{1,11})(?:\.(\d{1,2}))?$/)
  if (!match) return null
  return (BigInt(match[1]) * 100n) + BigInt((match[2] ?? '').padEnd(2, '0'))
}

export function defaultManilaDateTime(now = new Date()): string {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).formatToParts(now)
  const part = (type: Intl.DateTimeFormatPartTypes) => parts.find((entry) => entry.type === type)?.value ?? ''

  return `${part('year')}-${part('month')}-${part('day')}T${part('hour')}:${part('minute')}`
}

export function manilaInputToApi(value: string): string {
  return `${value.replace('T', ' ')}:00`
}

export function isFutureManilaInput(value: string, now = Date.now()): boolean {
  return Date.parse(`${value}:00+08:00`) > now
}
