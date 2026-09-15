import { Eye, FilePlus2 } from 'lucide-react'
import { Link } from 'react-router-dom'

import { QuotationStatusBadge } from '@/components/ui/status-badge'
import type { Booking } from '@/lib/api'
import { formatMoney } from '@/lib/booking-format'
import { formatBusinessDate, relevantQuotationDate } from '@/lib/quotation-format'

export function QuotationHistory({ booking, currency }: { booking: Booking; currency: string }) {
  const quotations = booking.quotations ?? []
  const hasBlockingQuotation = quotations.some((quotation) => ['DRAFT', 'SENT', 'ACCEPTED'].includes(quotation.status))
  const canOfferCreate = booking.status === 'PENDING' && !hasBlockingQuotation

  return (
    <section className="mt-6 rounded-lg border border-border bg-surface" aria-labelledby="quotation-history-heading">
      <div className="flex flex-wrap items-center justify-between gap-4 border-b border-border p-6">
        <div>
          <h2 id="quotation-history-heading" className="text-lg font-semibold">Quotations</h2>
          <p className="mt-1 text-sm text-muted">Commercial document history for this Booking.</p>
        </div>
        {canOfferCreate ? (
          <Link to={`/bookings/${booking.id}/quotations/new`} className="inline-flex min-h-10 items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:bg-primary-hover">
            <FilePlus2 className="size-4" aria-hidden="true" /> Create Quotation
          </Link>
        ) : null}
      </div>

      {quotations.length === 0 ? <p className="p-6 text-sm text-muted">No quotations have been created for this Booking.</p> : (
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-border text-xs uppercase text-muted">
              <tr><th className="px-5 py-3">Quotation</th><th className="px-5 py-3">Status</th><th className="px-5 py-3">Total</th><th className="px-5 py-3">Valid until</th><th className="px-5 py-3">Lifecycle</th><th className="px-5 py-3 text-right">Action</th></tr>
            </thead>
            <tbody className="divide-y divide-border">
              {quotations.map((quotation) => {
                const lifecycle = relevantQuotationDate(quotation)
                return (
                  <tr key={quotation.id}>
                    <td className="px-5 py-4 font-semibold">{quotation.quotation_number}</td>
                    <td className="px-5 py-4"><QuotationStatusBadge status={quotation.status} /></td>
                    <td className="px-5 py-4 tabular-nums">{formatMoney(quotation.total, currency)}</td>
                    <td className="px-5 py-4">{formatBusinessDate(quotation.valid_until)}</td>
                    <td className="px-5 py-4"><span className="block text-xs text-muted">{lifecycle.label}</span>{lifecycle.value}</td>
                    <td className="px-5 py-4 text-right"><Link to={`/quotations/${quotation.id}`} className="inline-flex items-center gap-2 rounded-lg border border-border px-3 py-2 font-medium hover:bg-surface-subtle"><Eye className="size-4" aria-hidden="true" /> View</Link></td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}
