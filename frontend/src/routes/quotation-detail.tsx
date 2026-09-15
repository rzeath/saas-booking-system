import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft, Check, Pencil, Send, XCircle } from 'lucide-react'
import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'

import { QuotationAdjustmentsForm } from '@/components/quotations/quotation-adjustments-form'
import { QuotationCommercialSummary } from '@/components/quotations/quotation-commercial-summary'
import { QuotationItemsTable } from '@/components/quotations/quotation-items-table'
import { Button } from '@/components/ui/button'
import { Modal } from '@/components/ui/modal'
import { BookingStatusBadge, QuotationStatusBadge, StatusBadge } from '@/components/ui/status-badge'
import { ApiError, getQuotation, transitionQuotation, type QuotationTransition, updateQuotation } from '@/lib/api'
import { bookingDetailQueryKey, bookingListsQueryKey } from '@/lib/bookings-query'
import { formatBusinessDate, formatLifecycleTimestamp } from '@/lib/quotation-format'
import { quotationDetailQueryKey, quotationListsQueryKey } from '@/lib/quotations-query'

function Detail({ label, value }: { label: string; value: string | null }) {
  return <div><dt className="text-xs font-semibold uppercase text-muted">{label}</dt><dd className="mt-1 whitespace-pre-wrap text-sm">{value || 'Not provided'}</dd></div>
}

const transitionCopy: Record<QuotationTransition, { title: string; description: string; confirm: string }> = {
  send: { title: 'Send this quotation?', description: 'This records the commercial document as Sent. No email will be delivered.', confirm: 'Confirm Send' },
  accept: { title: 'Accept this quotation?', description: 'Acceptance locks normal commercial Booking edits. The Booking remains Quoted until the later payment workflow.', confirm: 'Confirm Acceptance' },
  reject: { title: 'Reject this quotation?', description: 'The quotation will become read-only and the Booking will return to Pending.', confirm: 'Confirm Rejection' },
  cancel: { title: 'Cancel this quotation?', description: 'The quotation will become read-only. Historical snapshots will remain available.', confirm: 'Confirm Cancellation' },
}

function errorMessage(error: unknown, fallback: string): string {
  if (error instanceof ApiError) {
    const fieldMessage = Object.values(error.fieldErrors).flat()[0]
    return fieldMessage ?? error.message
  }

  return error instanceof Error ? error.message : fallback
}

export function QuotationDetailRoute() {
  const quotationId = Number(useParams().quotationId)
  const queryClient = useQueryClient()
  const [editing, setEditing] = useState(false)
  const [pendingTransition, setPendingTransition] = useState<QuotationTransition>()
  const [message, setMessage] = useState<string>()
  const quotation = useQuery({ queryKey: quotationDetailQueryKey(quotationId), queryFn: () => getQuotation(quotationId), enabled: Number.isInteger(quotationId) && quotationId > 0 })
  const updateMutation = useMutation({
    mutationFn: (input: Parameters<typeof updateQuotation>[1]) => updateQuotation(quotationId, input),
    onSuccess: (updated) => {
      queryClient.setQueryData(quotationDetailQueryKey(quotationId), updated)
      void queryClient.invalidateQueries({ queryKey: quotationListsQueryKey })
      void queryClient.invalidateQueries({ queryKey: bookingDetailQueryKey(updated.booking.id) })
      setEditing(false)
      setMessage('Draft adjustments saved.')
    },
  })
  const transitionMutation = useMutation({
    mutationFn: (transition: QuotationTransition) => transitionQuotation(quotationId, transition),
    onSuccess: (updated, transition) => {
      queryClient.setQueryData(quotationDetailQueryKey(quotationId), updated)
      void queryClient.invalidateQueries({ queryKey: quotationListsQueryKey })
      void queryClient.invalidateQueries({ queryKey: bookingDetailQueryKey(updated.booking.id) })
      void queryClient.invalidateQueries({ queryKey: bookingListsQueryKey })
      setPendingTransition(undefined)
      setMessage(`Quotation ${transition === 'send' ? 'sent' : `${transition}ed`}.`)
    },
    onError: (error) => {
      setPendingTransition(undefined)
      setMessage(errorMessage(error, 'Unable to update the quotation status.'))
    },
  })

  if (!Number.isInteger(quotationId) || quotationId < 1) return <p role="alert" className="text-danger">Invalid quotation.</p>
  if (quotation.isPending) return <p role="status" className="text-muted">Loading quotation...</p>
  if (quotation.isError) return <div><p role="alert" className="text-danger">{errorMessage(quotation.error, 'We could not load this quotation.')}</p><button type="button" onClick={() => { void quotation.refetch() }} className="mt-4 rounded-lg border border-border px-4 py-2 text-sm">Try again</button></div>

  const item = quotation.data
  const isDraft = item.status === 'DRAFT'
  const isSent = item.status === 'SENT'
  const terminal = !isDraft && !isSent

  return (
    <section>
      <Link to="/quotations" className="inline-flex items-center gap-2 text-sm font-medium text-primary"><ArrowLeft className="size-4" aria-hidden="true" /> Back to Quotations</Link>
      <div className="mt-4 flex flex-wrap items-start justify-between gap-5">
        <div>
          <div className="flex flex-wrap items-center gap-3"><h1 className="text-3xl font-semibold">{item.quotation_number}</h1><QuotationStatusBadge status={item.status} />{terminal ? <StatusBadge>Read-only</StatusBadge> : null}</div>
          <div className="mt-3 flex flex-wrap items-center gap-2 text-sm text-muted">
            <span>Related Booking</span>
            <Link to={`/bookings/${item.booking.id}`} className="font-semibold text-primary">{item.booking.booking_number}</Link>
            <BookingStatusBadge status={item.booking.status} />
          </div>
        </div>
        <div className="flex flex-wrap gap-2">
          {isDraft ? <Button variant="secondary" onClick={() => { setEditing(true); setMessage(undefined) }}><Pencil className="size-4" aria-hidden="true" /> Edit adjustments</Button> : null}
          {isDraft ? <Button onClick={() => { setPendingTransition('send'); setMessage(undefined) }}><Send className="size-4" aria-hidden="true" /> Send</Button> : null}
          {isSent ? <Button onClick={() => { setPendingTransition('accept'); setMessage(undefined) }}><Check className="size-4" aria-hidden="true" /> Accept</Button> : null}
          {isSent ? <Button variant="secondary" onClick={() => { setPendingTransition('reject'); setMessage(undefined) }}><XCircle className="size-4" aria-hidden="true" /> Reject</Button> : null}
          {isDraft || isSent ? <Button variant="destructive" onClick={() => { setPendingTransition('cancel'); setMessage(undefined) }}><XCircle className="size-4" aria-hidden="true" /> Cancel Quotation</Button> : null}
        </div>
      </div>

      {message ? <p role={transitionMutation.isError ? 'alert' : 'status'} className={`mt-5 rounded-lg border p-4 text-sm ${transitionMutation.isError ? 'border-red-200 bg-danger-soft text-danger' : 'border-green-200 bg-success-soft text-success'}`}>{message}</p> : null}
      {item.status === 'OUTDATED' ? <p className="mt-5 rounded-lg border border-amber-200 bg-warning-soft p-4 text-sm text-warning">This quotation no longer reflects the current Booking details. Create a new quotation from the Booking to issue an updated revision.</p> : null}
      {item.status === 'ACCEPTED' ? <p className="mt-5 rounded-lg border border-green-200 bg-success-soft p-4 text-sm text-success">Accepted on {formatLifecycleTimestamp(item.accepted_at)}. The related Booking remains {item.booking.status === 'QUOTED' ? 'Quoted' : item.booking.status.charAt(0) + item.booking.status.slice(1).toLowerCase()}.</p> : null}

      {editing ? (
        <section className="mt-6 rounded-lg border border-border bg-surface p-6">
          <h2 className="text-lg font-semibold">Edit Draft adjustments</h2>
          <div className="mt-5"><QuotationAdjustmentsForm initialValues={{ transportation_fee: item.transportation_fee, crew_meal_fee: item.crew_meal_fee, discount_amount: item.discount_amount, valid_until: item.valid_until ?? '' }} submitLabel="Save adjustments" isPending={updateMutation.isPending} error={updateMutation.error} onSubmit={(input) => updateMutation.mutate(input)} onCancel={() => setEditing(false)} /></div>
        </section>
      ) : null}

      <div className="mt-8 grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div className="space-y-6">
          <section className="rounded-lg border border-border bg-surface p-6">
            <div className="flex flex-wrap items-center justify-between gap-3"><h2 className="text-lg font-semibold">Customer and event snapshot</h2><StatusBadge>Quotation snapshot</StatusBadge></div>
            <dl className="mt-5 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
              <Detail label="Customer" value={item.customer_snapshot.name} />
              <Detail label="Customer email" value={item.customer_snapshot.email} />
              <Detail label="Customer phone" value={item.customer_snapshot.phone} />
              <Detail label="Event" value={item.event_snapshot.event_name} />
              <Detail label="Event type" value={item.event_snapshot.event_type_name} />
              <Detail label="Event date" value={formatBusinessDate(item.event_snapshot.event_date)} />
              <Detail label="Venue" value={item.event_snapshot.venue_name} />
              <Detail label="Contact person" value={item.event_snapshot.contact_person} />
              <Detail label="Contact number" value={item.event_snapshot.contact_number} />
              <div className="sm:col-span-2 lg:col-span-3"><Detail label="Venue address" value={item.event_snapshot.venue_address} /></div>
            </dl>
          </section>

          <section className="rounded-lg border border-border bg-surface">
            <div className="border-b border-border p-6"><h2 className="text-lg font-semibold">Quotation Items</h2></div>
            <QuotationItemsTable items={item.items.map((line) => ({ id: line.id, serviceName: line.service_name, packageName: line.package_name, startAt: line.start_at, endAt: line.end_at, durationMinutes: line.duration_minutes, quantity: line.quantity, unitRate: line.unit_rate, lineTotal: line.line_total }))} />
          </section>
        </div>

        <div className="space-y-6">
          <section className="rounded-lg border border-border bg-surface p-6">
            <h2 className="text-lg font-semibold">Commercial Summary</h2>
            <div className="mt-4"><QuotationCommercialSummary quotation={item} /></div>
          </section>
          <section className="rounded-lg border border-border bg-surface p-6">
            <h2 className="text-lg font-semibold">Lifecycle</h2>
            <dl className="mt-5 grid gap-5">
              <Detail label="Valid until" value={formatBusinessDate(item.valid_until)} />
              <Detail label="Created" value={formatLifecycleTimestamp(item.created_at)} />
              <Detail label="Sent" value={formatLifecycleTimestamp(item.sent_at)} />
              <Detail label="Accepted" value={formatLifecycleTimestamp(item.accepted_at)} />
              <Detail label="Closed" value={formatLifecycleTimestamp(item.closed_at)} />
            </dl>
          </section>
          <section className="rounded-lg border border-border bg-surface p-6">
            <h2 className="text-lg font-semibold">Seller snapshot</h2>
            <dl className="mt-5 grid gap-5"><Detail label="Business" value={item.seller_snapshot.display_name} /><Detail label="Email" value={item.seller_snapshot.email} /><Detail label="Phone" value={item.seller_snapshot.phone} /><Detail label="Address" value={item.seller_snapshot.address} /></dl>
          </section>
        </div>
      </div>

      {pendingTransition ? (
        <Modal
          title={transitionCopy[pendingTransition].title}
          description={transitionCopy[pendingTransition].description}
          onClose={() => { if (!transitionMutation.isPending) setPendingTransition(undefined) }}
          footer={<><Button variant="secondary" disabled={transitionMutation.isPending} onClick={() => setPendingTransition(undefined)}>Keep Current Status</Button><Button variant={pendingTransition === 'cancel' ? 'destructive' : 'primary'} disabled={transitionMutation.isPending} onClick={() => transitionMutation.mutate(pendingTransition)}>{transitionMutation.isPending ? 'Updating...' : transitionCopy[pendingTransition].confirm}</Button></>}
        >
          <p className="text-sm text-muted">{item.quotation_number}</p>
        </Modal>
      ) : null}
    </section>
  )
}
