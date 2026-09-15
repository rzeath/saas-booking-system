import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft } from 'lucide-react'
import { Link, useNavigate, useParams } from 'react-router-dom'

import { QuotationAdjustmentsForm } from '@/components/quotations/quotation-adjustments-form'
import { QuotationItemsTable } from '@/components/quotations/quotation-items-table'
import { createQuotation, getBooking } from '@/lib/api'
import { formatMoney, sumMoney } from '@/lib/booking-format'
import { bookingDetailQueryKey } from '@/lib/bookings-query'
import { quotationDetailQueryKey, quotationListsQueryKey } from '@/lib/quotations-query'

function Detail({ label, value }: { label: string; value: string | null }) {
  return <div><dt className="text-xs font-semibold uppercase text-muted">{label}</dt><dd className="mt-1 text-sm">{value || 'Not provided'}</dd></div>
}

export function QuotationNewRoute() {
  const bookingId = Number(useParams().bookingId)
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const booking = useQuery({ queryKey: bookingDetailQueryKey(bookingId), queryFn: () => getBooking(bookingId), enabled: Number.isInteger(bookingId) && bookingId > 0 })
  const mutation = useMutation({
    mutationFn: (input: Parameters<typeof createQuotation>[1]) => createQuotation(bookingId, input),
    onSuccess: (quotation) => {
      queryClient.setQueryData(quotationDetailQueryKey(quotation.id), quotation)
      void queryClient.invalidateQueries({ queryKey: quotationListsQueryKey })
      void queryClient.invalidateQueries({ queryKey: bookingDetailQueryKey(bookingId) })
      navigate(`/quotations/${quotation.id}`)
    },
  })

  if (!Number.isInteger(bookingId) || bookingId < 1) return <p role="alert" className="text-danger">Invalid Booking.</p>
  if (booking.isPending) return <p role="status" className="text-muted">Loading Booking...</p>
  if (booking.isError) return <div><p role="alert" className="text-danger">We could not prepare this quotation.</p><button type="button" onClick={() => { void booking.refetch() }} className="mt-4 rounded-lg border border-border px-4 py-2 text-sm">Try again</button></div>

  const item = booking.data
  const servicesTotal = sumMoney(item.booking_services.map((line) => line.line_total))

  return (
    <section>
      <Link to={`/bookings/${item.id}`} className="inline-flex items-center gap-2 text-sm font-medium text-primary"><ArrowLeft className="size-4" aria-hidden="true" /> Back to Booking</Link>
      <div className="mt-4">
        <p className="text-sm font-semibold text-primary">{item.booking_number}</p>
        <h1 className="mt-2 text-3xl font-semibold">Create Quotation</h1>
        <p className="mt-2 text-sm text-muted">New commercial snapshot from the saved Booking.</p>
      </div>

      <div className="mt-8 grid gap-6 lg:grid-cols-2">
        <section className="rounded-lg border border-border bg-surface p-6">
          <h2 className="text-lg font-semibold">Customer and event</h2>
          <dl className="mt-5 grid gap-5 sm:grid-cols-2">
            <Detail label="Customer" value={item.customer_snapshot.name} />
            <Detail label="Event type" value={item.event_type_snapshot.name} />
            <Detail label="Event" value={item.event_name} />
            <Detail label="Event date" value={item.event_date} />
            <Detail label="Venue" value={item.venue_name} />
            <Detail label="Contact" value={item.contact_person} />
          </dl>
        </section>
        <section className="rounded-lg border border-border bg-surface p-6">
          <h2 className="text-lg font-semibold">Commercial adjustments</h2>
          <p className="mt-1 text-sm text-muted">Services subtotal: <strong className="text-foreground">{formatMoney(servicesTotal)}</strong></p>
          <div className="mt-5">
            <QuotationAdjustmentsForm submitLabel="Create Quotation" isPending={mutation.isPending} error={mutation.error} onSubmit={(input) => mutation.mutate(input)} />
          </div>
        </section>
      </div>

      <section className="mt-6 rounded-lg border border-border bg-surface">
        <div className="border-b border-border p-6"><h2 className="text-lg font-semibold">Booking Services</h2><p className="mt-1 text-sm text-muted">Saved schedule and pricing.</p></div>
        <QuotationItemsTable items={item.booking_services.map((line) => ({ id: line.id, serviceName: line.service.name, packageName: line.package.name, startAt: line.start_at, endAt: line.end_at, durationMinutes: line.duration_minutes, quantity: line.quantity, unitRate: line.unit_rate, lineTotal: line.line_total }))} />
      </section>
    </section>
  )
}
