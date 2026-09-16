import type { EventInput } from '@fullcalendar/react'

import type { BookingStatus, CalendarEvent } from '@/lib/api'

export const MANILA_TIME_ZONE = 'Asia/Manila'
export const MOBILE_CALENDAR_QUERY = '(max-width: 767px)'
export const operationalCalendarStatuses: BookingStatus[] = ['PENDING', 'QUOTED', 'CONFIRMED']

export type CalendarView = 'month' | 'week'
export type CalendarStatusFilter = 'operational' | BookingStatus

export type CalendarDateRange = {
  start: string
  end: string
}

export function statusesForCalendarFilter(filter: CalendarStatusFilter): BookingStatus[] {
  return filter === 'operational' ? operationalCalendarStatuses : [filter]
}

export function fullCalendarView(view: CalendarView, isMobile: boolean) {
  if (isMobile) return view === 'month' ? 'listMonth' : 'listWeek'
  return view === 'month' ? 'dayGridMonth' : 'timeGridWeek'
}

export function visibleCalendarRange(start: string, end: string): CalendarDateRange {
  return { start: start.slice(0, 10), end: end.slice(0, 10) }
}

export function serviceSummary(booking: Pick<CalendarEvent, 'services'>): string {
  const first = booking.services[0]?.service_name ?? 'Service unavailable'
  const additional = booking.services.length - 1
  return additional > 0 ? `${first} +${additional} service${additional === 1 ? '' : 's'}` : first
}

export function calendarEventInputs(bookings: CalendarEvent[]): EventInput[] {
  return bookings.map((booking) => ({
    id: String(booking.id),
    title: booking.customer_name,
    start: wallClockCalendarInput(booking.start_at),
    end: wallClockCalendarInput(booking.end_at),
    extendedProps: { booking },
  }))
}

export function bookingsForCalendarDate(bookings: CalendarEvent[], date: string): CalendarEvent[] {
  const dayStart = `${date} 00:00`
  const dayEnd = `${nextCalendarDate(date)} 00:00`

  return bookings
    .filter((booking) => booking.start_at < dayEnd && booking.end_at > dayStart)
    .sort((left, right) => left.start_at.localeCompare(right.start_at)
      || left.booking_number.localeCompare(right.booking_number)
      || left.id - right.id)
}

export function manilaDateToday(now = new Date()): string {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: MANILA_TIME_ZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(now)
  const part = (type: Intl.DateTimeFormatPartTypes) => parts.find((item) => item.type === type)?.value ?? ''
  return `${part('year')}-${part('month')}-${part('day')}`
}

export function manilaDateFromCalendarDate(date: Date): string {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: MANILA_TIME_ZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(date)
}

export function formatCalendarDate(date: string): { date: string; weekday: string } {
  const [year, month, day] = date.split('-').map(Number)
  const value = new Date(Date.UTC(year, month - 1, day, 12))
  return {
    date: new Intl.DateTimeFormat('en-PH', { month: 'long', day: 'numeric', timeZone: 'UTC' }).format(value),
    weekday: new Intl.DateTimeFormat('en-PH', { weekday: 'long', timeZone: 'UTC' }).format(value),
  }
}

export function formatCalendarTime(value: string): string {
  const time = value.includes(' ') ? value.split(' ')[1] ?? '' : value
  const [hourValue = '0', minute = '00'] = time.split(':')
  const hour = Number(hourValue)
  return `${hour % 12 || 12}:${minute} ${hour >= 12 ? 'PM' : 'AM'}`
}

export function formatCalendarTimeRange(start: string, end: string): string {
  const startDate = start.slice(0, 10)
  const endDate = end.slice(0, 10)
  const formattedEnd = startDate === endDate
    ? formatCalendarTime(end)
    : `${formatCalendarDate(endDate).date}, ${formatCalendarTime(end)}`
  return `${formatCalendarTime(start)} – ${formattedEnd}`
}

export function compactDuration(minutes: number): string {
  const hours = Math.floor(minutes / 60)
  const remainder = minutes % 60
  if (hours === 0) return `${remainder}m`
  return remainder === 0 ? `${hours}h` : `${hours}h ${remainder}m`
}

function wallClockCalendarInput(value: string): string {
  return value.replace(' ', 'T')
}

function nextCalendarDate(date: string): string {
  const [year, month, day] = date.split('-').map(Number)
  return new Date(Date.UTC(year, month - 1, day + 1)).toISOString().slice(0, 10)
}
