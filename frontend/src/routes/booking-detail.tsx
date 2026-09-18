import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { EllipsisVertical, FileText, Pencil, XCircle } from 'lucide-react'
import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'

import { ErrorState, LoadingState } from '@/components/data/query-state'
import { TextAreaField } from '@/components/forms/form-field'
import { Page } from '@/components/layout/page'
import { QuotationHistory } from '@/components/quotations/quotation-history'
import { Button } from '@/components/ui/button'
import { buttonVariants } from '@/components/ui/button-variants'
import { Modal } from '@/components/ui/modal'
import { BookingStatusBadge } from '@/components/ui/status-badge'
import { cancelBooking, getBooking, type Booking } from '@/lib/api'
import { durationLabel, formatMoney, sumMoney } from '@/lib/booking-format'
import { bookingDetailQueryKey, bookingListsQueryKey } from '@/lib/bookings-query'
import { formatBusinessDate, formatManilaTime } from '@/lib/quotation-format'

function Detail({ label, value }: { label: string; value: string | null }) {
  return <div className="min-w-0"><dt className="text-xs font-medium text-muted">{label}</dt><dd className="mt-1 break-words text-sm font-medium text-foreground">{value || '—'}</dd></div>
}

function Schedule({ booking }: { booking: Booking }) {
  const start = `${formatBusinessDate(booking.event_date)} · ${formatManilaTime(booking.start_time)}`
  if (!booking.end_at) return <p className="text-sm font-medium text-foreground">{start}</p>

  const endDate = booking.end_at.split(' ')[0] ?? booking.event_date
  if (endDate === booking.event_date) {
    return <><p className="text-sm font-medium text-foreground">{formatBusinessDate(booking.event_date)}</p><p className="mt-0.5 text-sm text-muted">{formatManilaTime(booking.start_time)} – {formatManilaTime(booking.end_at)}</p></>
  }

  return <><p className="text-sm font-medium text-foreground">{start}</p><p className="mt-0.5 text-sm text-muted">→ {formatBusinessDate(endDate)} · {formatManilaTime(booking.end_at)}</p></>
}

export function BookingDetailRoute() {
  const bookingId = Number(useParams().bookingId)
  const queryClient = useQueryClient()
  const [cancelling, setCancelling] = useState(false)
  const [reason, setReason] = useState('')
  const [message, setMessage] = useState<string>()
  const booking = useQuery({ queryKey: bookingDetailQueryKey(bookingId), queryFn: () => getBooking(bookingId), enabled: Number.isInteger(bookingId) && bookingId > 0 })
  const mutation = useMutation({
    mutationFn: () => cancelBooking(bookingId, reason.trim() || null),
    onSuccess: (cancelled) => {
      queryClient.setQueryData(bookingDetailQueryKey(bookingId), cancelled)
      void queryClient.invalidateQueries({ queryKey: bookingListsQueryKey })
      setCancelling(false)
      setMessage('Booking cancelled. Historical details were preserved.')
    },
    onError: (error) => setMessage(error instanceof Error ? error.message : 'Unable to cancel this booking.'),
  })

  if (!Number.isInteger(bookingId) || bookingId < 1) return <Page><ErrorState title="Invalid booking." /></Page>
  if (booking.isPending) return <Page><LoadingState label="Loading booking…" /></Page>
  if (booking.isError) return <Page><ErrorState title="We could not load this booking." onRetry={() => { void booking.refetch() }} /></Page>

  const item = booking.data
  const quotations = item.quotations ?? []
  const canCreateQuotation = item.status === 'PENDING' && !quotations.some((quotation) => ['DRAFT', 'SENT', 'ACCEPTED'].includes(quotation.status))
  const currentQuotation = quotations.find((quotation) => ['DRAFT', 'SENT', 'ACCEPTED'].includes(quotation.status)) ?? quotations[0]
  const total = sumMoney(item.booking_services.map((line) => line.line_total))
  const primaryAction = canCreateQuotation
    ? { href: `/bookings/${item.id}/quotations/new`, label: 'Create Quotation', icon: FileText }
    : currentQuotation
      ? { href: `/quotations/${currentQuotation.id}`, label: 'View Quotation', icon: FileText }
      : null

  return (
    <Page>
      <Link to="/bookings" className="text-sm font-medium text-muted hover:text-primary">← Back to bookings</Link>
      <header className="mt-3 flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-3"><h1 className="break-all text-2xl font-semibold text-foreground">{item.booking_number}</h1><BookingStatusBadge status={item.status} /></div>
          <p className="mt-1 text-sm text-muted">{item.customer_snapshot.name} · {item.event_name || item.event_type_snapshot.name}</p>
        </div>
        {item.status === 'PENDING' ? <div className="flex items-center gap-2">
          <Link to={`/bookings/${item.id}/edit`} className={buttonVariants({ variant: 'secondary', size: 'small' })}><Pencil className="size-3.5" aria-hidden="true" /> Edit Booking</Link>
          <details className="group relative">
            <summary aria-label="More booking actions" className="grid size-8 cursor-pointer list-none place-items-center rounded-lg border border-border bg-surface text-muted hover:bg-surface-subtle hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring [&::-webkit-details-marker]:hidden"><EllipsisVertical className="size-4" aria-hidden="true" /></summary>
            <div className="absolute right-0 z-20 mt-1 min-w-44 rounded-lg border border-border bg-surface p-1 shadow-[0_8px_24px_rgb(35_28_31/0.12)]">
              <button type="button" onClick={() => { setReason(''); setCancelling(true); setMessage(undefined) }} className="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm font-medium text-danger hover:bg-danger-soft"><XCircle className="size-4" aria-hidden="true" /> Cancel booking</button>
            </div>
          </details>
        </div> : null}
      </header>

      {message && !cancelling && !mutation.isError ? <p role="status" className="mt-5 rounded-lg border border-success/20 bg-success-soft px-4 py-3 text-sm text-success">{message}</p> : null}

      <div className="mt-4 grid max-w-[1600px] gap-4 xl:grid-cols-[minmax(0,3fr)_minmax(18rem,1fr)] xl:items-start">
        <aside aria-labelledby="booking-summary-heading" className="rounded-xl border border-border bg-surface p-5 xl:sticky xl:top-7 xl:col-start-2 xl:row-start-1">
          <h2 id="booking-summary-heading" className="text-base font-semibold text-foreground">Booking Summary</h2>
          <dl className="mt-4 divide-y divide-border">
            <div className="pb-4"><dt className="text-xs font-medium text-muted">Status</dt><dd className="mt-1.5"><BookingStatusBadge status={item.status} /></dd></div>
            <div className="py-4"><dt className="text-xs font-medium text-muted">Schedule</dt><dd className="mt-1.5"><Schedule booking={item} /></dd></div>
            <div className="py-4"><dt className="text-xs font-medium text-muted">Services</dt><dd className="mt-1 text-sm font-medium text-foreground">{item.booking_services.length} {item.booking_services.length === 1 ? 'service' : 'services'}</dd></div>
            <div className="pt-4"><dt className="text-xs font-medium text-muted">Total</dt><dd className="mt-1 text-xl font-semibold tabular-nums text-foreground">{formatMoney(total)}</dd></div>
          </dl>
          {primaryAction ? <Link to={primaryAction.href} className={buttonVariants({ className: 'mt-5 w-full' })}><primaryAction.icon className="size-4" aria-hidden="true" /> {primaryAction.label}</Link> : null}
        </aside>

        <div className="min-w-0 space-y-4 xl:col-start-1 xl:row-start-1">
          <section aria-labelledby="event-details-heading" className="rounded-xl border border-border bg-surface p-5">
            <h2 id="event-details-heading" className="text-base font-semibold text-foreground">Event Details</h2>
            <dl className="mt-4 grid max-w-3xl gap-x-5 gap-y-3 sm:grid-cols-2">
              <Detail label="Event type" value={item.event_type_snapshot.name} />
              <Detail label="Event name" value={item.event_name} />
              <Detail label="Event date" value={formatBusinessDate(item.event_date)} />
              <Detail label="Start time" value={formatManilaTime(item.start_time)} />
              <Detail label="Effective end" value={item.end_at ? `${formatBusinessDate(item.end_at.split(' ')[0] ?? item.event_date)} · ${formatManilaTime(item.end_at)}` : null} />
              <Detail label="Venue name" value={item.venue_name} />
              <div className="sm:col-span-2"><Detail label="Venue address" value={item.venue_address} /></div>
            </dl>
          </section>

          <section aria-labelledby="customer-heading" className="rounded-xl border border-border bg-surface p-5">
            <h2 id="customer-heading" className="text-base font-semibold text-foreground">Customer</h2>
            <p className="mt-3 text-sm font-semibold text-foreground">{item.customer_snapshot.name}</p>
            <dl className="mt-3 grid max-w-3xl gap-x-5 gap-y-3 sm:grid-cols-2">
              <Detail label="Email" value={item.customer_snapshot.email} />
              <Detail label="Phone" value={item.customer_snapshot.phone} />
              {item.customer_snapshot.address ? <div className="sm:col-span-2"><Detail label="Address" value={item.customer_snapshot.address} /></div> : null}
            </dl>
            <div className="mt-4 border-t border-border pt-4">
              <h3 className="text-sm font-semibold text-foreground">Event Contact</h3>
              {item.contact_person || item.contact_number ? <dl className="mt-3 grid max-w-3xl gap-x-5 gap-y-3 sm:grid-cols-2"><Detail label="Contact person" value={item.contact_person} /><Detail label="Contact number" value={item.contact_number} /></dl> : <p className="mt-2 text-sm text-muted">No event contact provided.</p>}
            </div>
          </section>

          <section aria-labelledby="services-heading" className="rounded-xl border border-border bg-surface p-5">
            <h2 id="services-heading" className="text-base font-semibold text-foreground">Services</h2>
            <div className="mt-3 divide-y divide-border">
              {item.booking_services.map((line) => <article key={line.id} className="py-3.5 first:pt-0 last:pb-0">
                <div><h3 className="text-sm font-semibold text-foreground">{line.service.name}</h3><p className="mt-0.5 text-sm text-muted">{line.package.name}</p></div>
                <dl className="mt-2.5 grid grid-cols-2 gap-x-4 gap-y-2.5 sm:grid-cols-4">
                  <Detail label="Duration" value={durationLabel(line.duration_minutes)} />
                  <Detail label="Quantity" value={String(line.quantity)} />
                  <Detail label="Rate" value={formatMoney(line.unit_rate)} />
                  <Detail label="Line total" value={formatMoney(line.line_total)} />
                </dl>
                <div className="mt-2.5"><p className="text-xs font-medium text-muted">Staff</p><p className="mt-1 text-sm text-foreground">{line.staff.length > 0 ? line.staff.map((staff) => staff.name).join(', ') : <span className="text-muted">No staff assigned</span>}</p></div>
              </article>)}
            </div>
          </section>

          <section aria-labelledby="additional-heading" className="rounded-xl border border-border bg-surface p-5">
            <h2 id="additional-heading" className="text-base font-semibold text-foreground">Additional Details</h2>
            <p className="mt-3 whitespace-pre-wrap text-sm text-foreground">{item.internal_notes || 'No notes added.'}</p>
            {item.status === 'CANCELLED' ? <div className="mt-4 border-t border-border pt-4"><Detail label="Cancellation reason" value={item.cancellation_reason} /></div> : null}
          </section>

          <QuotationHistory booking={item} primaryQuotationId={primaryAction?.label === 'View Quotation' ? currentQuotation?.id : undefined} />
        </div>
      </div>

      {cancelling ? <Modal title={`Cancel ${item.booking_number}?`} onClose={() => { if (!mutation.isPending) setCancelling(false) }} footer={<><Button variant="secondary" disabled={mutation.isPending} onClick={() => setCancelling(false)}>Keep booking</Button><Button variant="destructive" disabled={mutation.isPending} onClick={() => mutation.mutate()}>{mutation.isPending ? 'Cancelling…' : 'Confirm cancellation'}</Button></>}>
        <p className="text-sm text-muted">This releases reserved service capacity. The booking and all historical details remain available.</p>
        <div className="mt-5"><TextAreaField label="Reason (optional)" id="booking-cancellation-reason" value={reason} onChange={(event) => setReason(event.target.value)} maxLength={1000} /></div>
        {mutation.isError && message ? <p role="alert" className="mt-4 text-sm text-danger">{message}</p> : null}
      </Modal> : null}
    </Page>
  )
}
