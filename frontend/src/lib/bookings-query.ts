import type { BookingQuery, StaffAvailabilityInput } from '@/lib/api'

export const bookingsQueryKey = ['bookings'] as const
export const bookingListsQueryKey = [...bookingsQueryKey, 'list'] as const

export function bookingListQueryKey(query: BookingQuery) {
  return [...bookingListsQueryKey, query] as const
}

export function bookingDetailQueryKey(id: number) {
  return [...bookingsQueryKey, 'detail', id] as const
}

export function staffAvailabilityQueryKey(input: StaffAvailabilityInput) {
  return [...bookingsQueryKey, 'staff-availability', input] as const
}
