import type { QuotationStatus, QuotationSummary } from '@/lib/api'

const dateFormatter = new Intl.DateTimeFormat('en-PH', {
  year: 'numeric',
  month: 'short',
  day: 'numeric',
})

const timestampFormatter = new Intl.DateTimeFormat('en-PH', {
  timeZone: 'Asia/Manila',
  year: 'numeric',
  month: 'short',
  day: 'numeric',
  hour: 'numeric',
  minute: '2-digit',
})

export function quotationStatusLabel(status: QuotationStatus): string {
  return status.charAt(0) + status.slice(1).toLowerCase()
}

export function formatBusinessDate(value: string | null): string {
  if (!value) return 'Not set'
  const [year, month, day] = value.split('-').map(Number)
  return dateFormatter.format(new Date(year, month - 1, day))
}

export function formatLifecycleTimestamp(value: string | null): string {
  return value ? timestampFormatter.format(new Date(value)) : 'Not recorded'
}

export function formatManilaWallClock(value: string): string {
  const [date = '', time = ''] = value.split(' ')
  return `${formatBusinessDate(date)}, ${formatManilaTime(time)}`
}

export function formatManilaTime(value: string | null): string {
  if (!value) return 'Not set'
  const time = value.includes(' ') ? value.split(' ')[1] ?? '' : value
  const [hourValue = '0', minute = '00'] = time.split(':')
  const hour = Number(hourValue)
  const period = hour >= 12 ? 'PM' : 'AM'
  const displayHour = hour % 12 || 12

  return `${displayHour}:${minute} ${period}`
}

export function formatManilaScheduleEnd(value: string | null, startDate: string): string {
  if (!value) return 'Not set'
  const [endDate = ''] = value.split(' ')
  return endDate === startDate ? formatManilaTime(value) : formatManilaWallClock(value)
}

export function relevantQuotationDate(quotation: QuotationSummary): { label: string; value: string } {
  if (quotation.status === 'ACCEPTED' && quotation.accepted_at) {
    return { label: 'Accepted', value: formatLifecycleTimestamp(quotation.accepted_at) }
  }

  if (['REJECTED', 'CANCELLED', 'EXPIRED', 'OUTDATED'].includes(quotation.status) && quotation.closed_at) {
    return { label: 'Closed', value: formatLifecycleTimestamp(quotation.closed_at) }
  }

  if (quotation.sent_at) {
    return { label: 'Sent', value: formatLifecycleTimestamp(quotation.sent_at) }
  }

  return { label: 'Created', value: formatLifecycleTimestamp(quotation.created_at) }
}
