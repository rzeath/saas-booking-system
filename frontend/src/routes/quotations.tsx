import { useQuery } from '@tanstack/react-query'
import { Eye, Search } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'

import { PaginationControls } from '@/components/data/pagination-controls'
import { FormField, SelectField } from '@/components/forms/form-field'
import { QuotationStatusBadge } from '@/components/ui/status-badge'
import { getQuotations, type QuotationQuery, type QuotationStatus } from '@/lib/api'
import { formatMoney } from '@/lib/booking-format'
import { formatBusinessDate, relevantQuotationDate } from '@/lib/quotation-format'
import { quotationListQueryKey } from '@/lib/quotations-query'

const initialQuery: QuotationQuery = { page: 1, search: '', status: '' }
const statuses: QuotationStatus[] = ['DRAFT', 'SENT', 'ACCEPTED', 'REJECTED', 'CANCELLED', 'EXPIRED', 'OUTDATED']

export function QuotationsRoute() {
  const [query, setQuery] = useState<QuotationQuery>(initialQuery)
  const [search, setSearch] = useState('')
  const quotations = useQuery({
    queryKey: quotationListQueryKey(query),
    queryFn: () => getQuotations(query),
  })

  return (
    <section>
      <div>
        <p className="text-sm font-semibold text-primary">Operations</p>
        <h1 className="mt-2 text-3xl font-semibold">Quotations</h1>
        <p className="mt-2 text-sm text-muted">Review commercial revisions and their current lifecycle status.</p>
      </div>

      <div className="mt-8 rounded-lg border border-border bg-surface">
        <form
          role="search"
          className="grid gap-4 border-b border-border p-5 md:grid-cols-[minmax(0,1fr)_14rem_auto]"
          onSubmit={(event) => {
            event.preventDefault()
            setQuery((current) => ({ ...current, page: 1, search: search.trim() }))
          }}
        >
          <FormField label="Search quotations" id="quotation-search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Quotation number or customer" />
          <SelectField label="Status" id="quotation-status-filter" value={query.status} onChange={(event) => setQuery((current) => ({ ...current, page: 1, status: event.target.value as QuotationStatus | '' }))}>
            <option value="">All statuses</option>
            {statuses.map((status) => <option key={status} value={status}>{status.charAt(0) + status.slice(1).toLowerCase()}</option>)}
          </SelectField>
          <div className="flex items-end gap-2">
            <button type="submit" className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-border px-4 py-2 text-sm font-semibold hover:bg-surface-subtle"><Search className="size-4" aria-hidden="true" /> Search</button>
            <button type="button" onClick={() => { setSearch(''); setQuery(initialQuery) }} className="min-h-10 rounded-lg border border-border px-4 py-2 text-sm font-semibold hover:bg-surface-subtle">Clear</button>
          </div>
        </form>

        {quotations.isPending ? <p role="status" className="p-8 text-center text-muted">Loading quotations...</p> : null}
        {quotations.isError ? <div className="p-8 text-center"><p role="alert" className="text-danger">We could not load quotations.</p><button type="button" onClick={() => { void quotations.refetch() }} className="mt-3 rounded-lg border border-border px-3 py-2 text-sm">Try again</button></div> : null}
        {quotations.data?.data.length === 0 ? <p className="p-8 text-center text-muted">No quotations match these filters.</p> : null}
        {quotations.data && quotations.data.data.length > 0 ? (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-border text-xs uppercase text-muted">
                <tr><th className="px-5 py-3">Quotation</th><th className="px-5 py-3">Booking</th><th className="px-5 py-3">Customer</th><th className="px-5 py-3">Total</th><th className="px-5 py-3">Valid until</th><th className="px-5 py-3">Status</th><th className="px-5 py-3">Lifecycle</th><th className="px-5 py-3 text-right">Action</th></tr>
              </thead>
              <tbody className="divide-y divide-border">
                {quotations.data.data.map((quotation) => {
                  const lifecycle = relevantQuotationDate(quotation)
                  return (
                    <tr key={quotation.id}>
                      <td className="px-5 py-4 font-semibold text-primary">{quotation.quotation_number}</td>
                      <td className="px-5 py-4">{quotation.booking ? <Link to={`/bookings/${quotation.booking.id}`} className="font-medium hover:text-primary">{quotation.booking.booking_number}</Link> : <span className="text-muted">Unavailable</span>}</td>
                      <td className="px-5 py-4">{quotation.customer_name}</td>
                      <td className="px-5 py-4 font-medium tabular-nums">{formatMoney(quotation.total)}</td>
                      <td className="px-5 py-4">{formatBusinessDate(quotation.valid_until)}</td>
                      <td className="px-5 py-4"><QuotationStatusBadge status={quotation.status} /></td>
                      <td className="px-5 py-4"><span className="block text-xs text-muted">{lifecycle.label}</span>{lifecycle.value}</td>
                      <td className="px-5 py-4 text-right"><Link to={`/quotations/${quotation.id}`} className="inline-flex items-center gap-2 rounded-lg border border-border px-3 py-2 font-medium hover:bg-surface-subtle"><Eye className="size-4" aria-hidden="true" /> View</Link></td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        ) : null}
        {quotations.data ? <PaginationControls page={quotations.data.meta.current_page} lastPage={quotations.data.meta.last_page} total={quotations.data.meta.total} onPageChange={(page) => setQuery((current) => ({ ...current, page }))} /> : null}
      </div>
    </section>
  )
}
