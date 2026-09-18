import { ArrowUpRight } from 'lucide-react'
import { Link } from 'react-router-dom'

import { QuotationStatusBadge } from '@/components/ui/status-badge'
import type { Booking } from '@/lib/api'
import { relevantQuotationDate } from '@/lib/quotation-format'

export function QuotationHistory({ booking, primaryQuotationId }: { booking: Booking; primaryQuotationId?: number }) {
  const quotations = booking.quotations ?? []

  return (
    <section aria-labelledby="quotation-history-heading" className="rounded-xl border border-border bg-surface p-5">
      <h2 id="quotation-history-heading" className="text-base font-semibold text-foreground">Quotations</h2>
      {quotations.length === 0 ? <p className="mt-3 text-sm text-muted">No quotation yet.</p> : (
        <ul className="mt-3 divide-y divide-border">
          {quotations.map((quotation) => {
            const lifecycle = relevantQuotationDate(quotation)
            return <li key={quotation.id} className="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2"><span className="text-sm font-semibold text-foreground">{quotation.quotation_number}</span><QuotationStatusBadge status={quotation.status} /></div>
                <p className="mt-1 text-xs text-muted">{lifecycle.label}: {lifecycle.value}</p>
              </div>
              {quotation.id !== primaryQuotationId ? <Link to={`/quotations/${quotation.id}`} className="inline-flex items-center gap-1 text-sm font-medium text-primary hover:text-primary-hover">View Quotation <ArrowUpRight className="size-4" aria-hidden="true" /></Link> : null}
            </li>
          })}
        </ul>
      )}
    </section>
  )
}
