import { useMutation, useQuery } from '@tanstack/react-query'
import { ArrowLeft, Ban, CreditCard, Download } from 'lucide-react'
import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'

import { DataPanel, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, TableScroll } from '@/components/data/data-table'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/query-state'
import { PaginationControls } from '@/components/data/pagination-controls'
import { RecordPaymentDialog } from '@/components/payments/record-payment-dialog'
import { VoidPaymentDialog } from '@/components/payments/void-payment-dialog'
import { Button } from '@/components/ui/button'
import { BillingPaymentStatusBadge, BookingStatusBadge, PaymentStatusBadge, StatusBadge } from '@/components/ui/status-badge'
import { ApiError, getBilling, getBillingPayments, getBillingPdf, type Payment, type PaymentQuery } from '@/lib/api'
import { moneyToCents, paymentMethodLabel } from '@/lib/billing-format'
import { billingDetailQueryKey, billingPaymentsQueryKey } from '@/lib/billings-query'
import { durationLabel, formatMoney } from '@/lib/booking-format'
import { formatBusinessDate, formatLifecycleTimestamp, formatManilaWallClock } from '@/lib/quotation-format'

const historyQuery: PaymentQuery = { page: 1, search: '', status: '', payment_method: '', paid_from: '', paid_to: '' }

function Detail({ label, value }: { label: string; value: string | null }) {
  return <div><dt className="text-xs font-semibold uppercase text-muted">{label}</dt><dd className="mt-1 whitespace-pre-wrap text-sm">{value || 'Not provided'}</dd></div>
}

export function BillingDetailRoute() {
  const billingId = Number(useParams().billingId)
  const [paymentPage, setPaymentPage] = useState(1)
  const [recording, setRecording] = useState(false)
  const [voiding, setVoiding] = useState<Payment>()
  const [message, setMessage] = useState<string>()
  const [pdfError, setPdfError] = useState<string>()
  const billing = useQuery({ queryKey: billingDetailQueryKey(billingId), queryFn: () => getBilling(billingId), enabled: Number.isInteger(billingId) && billingId > 0 })
  const payments = useQuery({ queryKey: billingPaymentsQueryKey(billingId, { ...historyQuery, page: paymentPage }), queryFn: () => getBillingPayments(billingId, { ...historyQuery, page: paymentPage }), enabled: Number.isInteger(billingId) && billingId > 0 })
  const pdfMutation = useMutation({
    mutationFn: () => getBillingPdf(billingId),
    onSuccess: (pdf) => {
      const objectUrl = URL.createObjectURL(pdf)
      const link = document.createElement('a')
      link.href = objectUrl
      link.download = `${billing.data?.billing_number ?? `billing-${billingId}`}.pdf`
      link.click()
      URL.revokeObjectURL(objectUrl)
    },
    onError: (error) => {
      const message = error instanceof ApiError
        ? Object.values(error.fieldErrors).flat()[0] ?? error.message
        : error instanceof Error ? error.message : 'Unable to download the Billing PDF.'
      setPdfError(message)
    },
  })

  if (!Number.isInteger(billingId) || billingId < 1) return <p role="alert" className="text-danger">Invalid Billing.</p>
  if (billing.isPending) return <LoadingState label="Loading Billing..." />
  if (billing.isError) return <ErrorState title="We could not load this Billing." onRetry={() => { void billing.refetch() }} />

  const item = billing.data
  const remainingCents = moneyToCents(item.payment_summary.remaining_balance)
  const canRecord = remainingCents !== null
    && remainingCents > 0n
    && (item.booking.status === 'CONFIRMED' || item.booking.status === 'COMPLETED')

  return (
    <section>
      <Link to="/billings" className="inline-flex items-center gap-2 text-sm font-medium text-primary"><ArrowLeft className="size-4" aria-hidden="true" /> Back to Billings</Link>
      <div className="mt-4 flex flex-wrap items-start justify-between gap-5">
        <div>
          <div className="flex flex-wrap items-center gap-3"><h1 className="text-3xl font-semibold">{item.billing_number}</h1><BillingPaymentStatusBadge status={item.payment_summary.payment_status} /></div>
          <div className="mt-3 flex flex-wrap items-center gap-3 text-sm text-muted">
            <Link to={`/quotations/${item.quotation.id}`} className="font-semibold text-primary">{item.quotation.quotation_number}</Link>
            <Link to={`/bookings/${item.booking.id}`} className="font-semibold text-primary">{item.booking.booking_number}</Link>
            <BookingStatusBadge status={item.booking.status} />
          </div>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button variant="secondary" disabled={pdfMutation.isPending} onClick={() => { setPdfError(undefined); pdfMutation.mutate() }}><Download className="size-4" aria-hidden="true" /> {pdfMutation.isPending ? 'Preparing PDF...' : 'Download PDF'}</Button>
          {canRecord ? <Button onClick={() => { setRecording(true); setMessage(undefined) }}><CreditCard className="size-4" aria-hidden="true" /> Record Payment</Button> : null}
        </div>
      </div>

      {message ? <p role="status" className="mt-5 rounded-lg border border-green-200 bg-success-soft p-4 text-sm text-success">{message}</p> : null}
      {pdfError ? <p role="alert" className="mt-4 text-sm text-danger">{pdfError}</p> : null}

      <div className="mt-8 grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div className="space-y-6">
          <section className="rounded-lg border border-border bg-surface p-6">
            <div className="flex items-center justify-between gap-3"><h2 className="text-lg font-semibold">Customer and event</h2><StatusBadge>Billing snapshot</StatusBadge></div>
            <dl className="mt-5 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
              <Detail label="Customer" value={item.customer_snapshot.name} /><Detail label="Customer email" value={item.customer_snapshot.email} /><Detail label="Customer phone" value={item.customer_snapshot.phone} />
              <Detail label="Event" value={item.event_snapshot.event_name} /><Detail label="Event type" value={item.event_snapshot.event_type_name} /><Detail label="Event date" value={formatBusinessDate(item.event_snapshot.event_date)} />
              <Detail label="Venue" value={item.event_snapshot.venue_name} /><Detail label="Contact person" value={item.event_snapshot.contact_person} /><Detail label="Contact number" value={item.event_snapshot.contact_number} />
              <div className="sm:col-span-2 lg:col-span-3"><Detail label="Venue address" value={item.event_snapshot.venue_address} /></div>
            </dl>
          </section>

          <DataPanel>
            <div className="border-b border-border p-6"><h2 className="text-lg font-semibold">Billing Items</h2></div>
            <TableScroll>
              <table className={tableClassName}>
                <thead className={tableHeadClassName}><tr><th className={tableHeaderCellClassName}>Service</th><th className={tableHeaderCellClassName}>Package</th><th className={tableHeaderCellClassName}>Schedule</th><th className={tableHeaderCellClassName}>Duration</th><th className={tableHeaderCellClassName}>Qty</th><th className={`${tableHeaderCellClassName} text-right`}>Unit rate</th><th className={`${tableHeaderCellClassName} text-right`}>Line total</th></tr></thead>
                <tbody className={tableBodyClassName}>{item.items?.map((line) => <tr key={line.id}><td className={`${tableCellClassName} font-medium`}>{line.service_name}</td><td className={tableCellClassName}>{line.package_name}</td><td className={tableCellClassName}><span className="block whitespace-nowrap">{formatManilaWallClock(line.start_at)}</span><span className="block whitespace-nowrap text-xs text-muted">to {formatManilaWallClock(line.end_at)}</span></td><td className={tableCellClassName}>{durationLabel(line.duration_minutes)}</td><td className={tableCellClassName}>{line.quantity}</td><td className={`${tableCellClassName} text-right tabular-nums`}>{formatMoney(line.unit_rate)}</td><td className={`${tableCellClassName} text-right font-semibold tabular-nums`}>{formatMoney(line.line_total)}</td></tr>)}</tbody>
              </table>
            </TableScroll>
          </DataPanel>
        </div>

        <div className="space-y-6">
          <section className="rounded-lg border border-border bg-surface p-6"><h2 className="text-lg font-semibold">Commercial Summary</h2><dl className="mt-4 space-y-3 text-sm"><SummaryRow label="Subtotal" value={item.subtotal} /><SummaryRow label="Transportation" value={item.transportation_fee} /><SummaryRow label="Crew meals" value={item.crew_meal_fee} /><SummaryRow label="Discount" value={item.discount_amount} negative /><SummaryRow label="Total" value={item.total} total /></dl></section>
          <section className="rounded-lg border border-border bg-surface p-6"><h2 className="text-lg font-semibold">Payment Summary</h2><dl className="mt-4 space-y-3 text-sm"><SummaryRow label="Amount paid" value={item.payment_summary.amount_paid} /><SummaryRow label="Remaining" value={item.payment_summary.remaining_balance} total /></dl><div className="mt-4"><BillingPaymentStatusBadge status={item.payment_summary.payment_status} /></div></section>
          <section className="rounded-lg border border-border bg-surface p-6"><h2 className="text-lg font-semibold">Seller snapshot</h2><dl className="mt-5 grid gap-5"><Detail label="Business" value={item.seller_snapshot.display_name} /><Detail label="Email" value={item.seller_snapshot.email} /><Detail label="Phone" value={item.seller_snapshot.phone} /><Detail label="Address" value={item.seller_snapshot.address} /><Detail label="Created" value={formatLifecycleTimestamp(item.created_at)} /></dl></section>
        </div>
      </div>

      <DataPanel className="mt-6">
        <div className="border-b border-border p-6"><h2 className="text-lg font-semibold">Payment History</h2><p className="mt-1 text-sm text-muted">Posted and voided payments remain visible for audit history.</p></div>
        {payments.isPending ? <LoadingState label="Loading payment history..." /> : null}
        {payments.isError ? <ErrorState title="We could not load payment history." onRetry={() => { void payments.refetch() }} /> : null}
        {payments.data?.data.length === 0 ? <EmptyState title="No payment history." /> : null}
        {payments.data && payments.data.data.length > 0 ? <TableScroll><table className={tableClassName}><thead className={tableHeadClassName}><tr><th className={tableHeaderCellClassName}>Payment date</th><th className={tableHeaderCellClassName}>Method</th><th className={tableHeaderCellClassName}>Reference</th><th className={tableHeaderCellClassName}>Amount</th><th className={tableHeaderCellClassName}>Status</th><th className={tableHeaderCellClassName}>Created</th><th className={`${tableHeaderCellClassName} text-right`}>Action</th></tr></thead><tbody className={tableBodyClassName}>{payments.data.data.map((payment) => <tr key={payment.id} className={payment.status === 'VOIDED' ? 'bg-surface-subtle text-muted' : undefined}><td className={tableCellClassName}>{formatManilaWallClock(payment.paid_at)}</td><td className={tableCellClassName}>{paymentMethodLabel(payment.payment_method)}</td><td className={tableCellClassName}>{payment.reference_number ?? 'Not provided'}</td><td className={`${tableCellClassName} font-medium tabular-nums`}>{formatMoney(payment.amount)}</td><td className={tableCellClassName}><PaymentStatusBadge status={payment.status} />{payment.void_reason ? <p className="mt-1 max-w-xs text-xs">{payment.void_reason}</p> : null}</td><td className={tableCellClassName}>{formatLifecycleTimestamp(payment.created_at)}</td><td className={`${tableCellClassName} text-right`}>{payment.status === 'POSTED' ? <Button variant="secondary" size="small" onClick={() => setVoiding(payment)}><Ban className="size-3.5" aria-hidden="true" /> Void</Button> : <span className="text-xs text-muted">Unavailable</span>}</td></tr>)}</tbody></table></TableScroll> : null}
        {payments.data ? <PaginationControls page={payments.data.meta.current_page} lastPage={payments.data.meta.last_page} total={payments.data.meta.total} onPageChange={setPaymentPage} /> : null}
      </DataPanel>

      {recording ? <RecordPaymentDialog quotationId={item.quotation.id} quotationNumber={item.quotation.quotation_number} total={item.total} summary={item.payment_summary} onClose={() => setRecording(false)} onSuccess={(result) => { setRecording(false); setMessage(`${formatMoney(result.payment.amount)} payment recorded.`) }} /> : null}
      {voiding ? <VoidPaymentDialog payment={voiding} onClose={() => setVoiding(undefined)} onSuccess={(result) => { setVoiding(undefined); setMessage(`${formatMoney(result.payment.amount)} payment voided. Billing balance updated.`) }} /> : null}
    </section>
  )
}

function SummaryRow({ label, value, negative = false, total = false }: { label: string; value: string; negative?: boolean; total?: boolean }) {
  return <div className={`flex items-center justify-between gap-4 ${total ? 'border-t border-border pt-3 text-base font-bold' : ''}`}><dt className="text-muted">{label}</dt><dd className="tabular-nums">{negative && moneyToCents(value) !== 0n ? '-' : ''}{formatMoney(value)}</dd></div>
}
