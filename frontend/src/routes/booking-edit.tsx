import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'

import { BookingForm } from '@/components/bookings/booking-form'
import { getBooking } from '@/lib/api'
import { bookingDetailQueryKey } from '@/lib/bookings-query'

export function BookingEditRoute() {
  const bookingId = Number(useParams().bookingId)
  const booking = useQuery({
    queryKey: bookingDetailQueryKey(bookingId),
    queryFn: () => getBooking(bookingId),
    enabled: Number.isInteger(bookingId) && bookingId > 0,
  })

  if (!Number.isInteger(bookingId) || bookingId < 1) return <p role="alert" className="text-rose-300">Invalid booking.</p>
  if (booking.isPending) return <p role="status" className="text-slate-400">Loading booking…</p>
  if (booking.isError) return <p role="alert" className="text-rose-300">We could not load this booking.</p>
  if (booking.data.status !== 'PENDING') {
    return <div className="rounded-2xl border border-slate-800 bg-slate-900 p-8"><h1 className="text-2xl font-semibold">This booking is read-only</h1><p className="mt-2 text-slate-400">Only pending bookings may be edited.</p><Link to={`/bookings/${bookingId}`} className="mt-5 inline-block rounded-lg border border-slate-700 px-4 py-2 text-sm">Return to booking</Link></div>
  }

  return <section><p className="text-sm font-semibold tracking-[0.08em] text-cyan-400">{booking.data.booking_number}</p><h1 className="mt-2 text-3xl font-semibold tracking-tight">Edit booking</h1><p className="mt-2 text-sm text-slate-400">Update this pending booking. Authoritative pricing and availability are checked again on save.</p><BookingForm booking={booking.data} /></section>
}
