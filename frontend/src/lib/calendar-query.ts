import type { CalendarQuery } from '@/lib/api'

export const calendarQueryKey = ['calendar'] as const

export function calendarEventsQueryKey(query: CalendarQuery) {
  return [...calendarQueryKey, 'events', query] as const
}
