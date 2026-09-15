import type { BookingStatus } from '@/lib/api'
import { SYSTEM_LOCALE } from '@/lib/system-config'

export function bookingStatusLabel(status: BookingStatus): string {
  return status.charAt(0) + status.slice(1).toLowerCase()
}

export function durationLabel(minutes: number): string {
  if (minutes % 60 === 0) {
    const hours = minutes / 60
    return `${hours} ${hours === 1 ? 'hour' : 'hours'}`
  }

  return `${minutes} minutes`
}

export function formatMoney(value: string): string {
  const amount = new Intl.NumberFormat(SYSTEM_LOCALE, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(Number(value))

  return `₱${amount}`
}

export function sumMoney(values: string[]): string {
  const cents = values.reduce((total, value) => {
    const [whole = '0', fraction = ''] = value.split('.')
    return total + (Number(whole) * 100) + Number(fraction.padEnd(2, '0').slice(0, 2))
  }, 0)

  return `${Math.trunc(cents / 100)}.${String(cents % 100).padStart(2, '0')}`
}

export function multiplyMoney(value: string, quantity: number): string {
  const [whole = '0', fraction = ''] = value.split('.')
  const cents = ((Number(whole) * 100) + Number(fraction.padEnd(2, '0').slice(0, 2))) * quantity

  return `${Math.trunc(cents / 100)}.${String(cents % 100).padStart(2, '0')}`
}
