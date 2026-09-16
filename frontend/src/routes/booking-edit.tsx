import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'

import { BookingForm } from '@/components/bookings/booking-form'
import { ErrorState, LoadingState } from '@/components/data/query-state'
import { Page, PageHeader } from '@/components/layout/page'
import { buttonVariants } from '@/components/ui/button-variants'
import { getBooking } from '@/lib/api'
import { bookingDetailQueryKey } from '@/lib/bookings-query'

export function BookingEditRoute() {
  const bookingId = Number(useParams().bookingId)
  const booking = useQuery({
    queryKey: bookingDetailQueryKey(bookingId),
    queryFn: () => getBooking(bookingId),
    enabled: Number.isInteger(bookingId) && bookingId > 0,
  })

  if (!Number.isInteger(bookingId) || bookingId < 1) return <ErrorState title="Invalid booking." />
  if (booking.isPending) return <LoadingState label="Loading booking…" />
  if (booking.isError) return <ErrorState title="We could not load this booking." onRetry={() => { void booking.refetch() }} />
  if (booking.data.status !== 'PENDING') {
    return <Page><div className="rounded-xl border border-border bg-surface p-6"><h1 className="text-2xl font-semibold text-foreground">This booking is read-only</h1><p className="mt-1 text-sm text-muted">Only pending bookings may be edited.</p><Link to={`/bookings/${bookingId}`} className={buttonVariants({ variant: 'secondary', className: 'mt-5' })}>Return to booking</Link></div></Page>
  }

  return <Page><PageHeader eyebrow={booking.data.booking_number} title="Edit Booking" description="Update booking details and services." /><BookingForm booking={booking.data} /></Page>
}
