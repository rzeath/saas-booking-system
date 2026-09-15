import { useQuery } from '@tanstack/react-query'
import { Eye, Search } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'

import { DataPanel, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, TableScroll } from '@/components/data/data-table'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/query-state'
import { PaginationControls } from '@/components/data/pagination-controls'
import { FormField } from '@/components/forms/form-field'
import { Page, PageHeader } from '@/components/layout/page'
import { BillingPaymentStatusBadge } from '@/components/ui/status-badge'
import { getBillings, type BillingQuery } from '@/lib/api'
import { billingListQueryKey } from '@/lib/billings-query'
import { formatMoney } from '@/lib/booking-format'
import { formatBusinessDate, formatLifecycleTimestamp } from '@/lib/quotation-format'

const initialQuery: BillingQuery = { page: 1, search: '' }

export function BillingsRoute() {
  const [query, setQuery] = useState(initialQuery)
  const [search, setSearch] = useState('')
  const billings = useQuery({ queryKey: billingListQueryKey(query), queryFn: () => getBillings(query) })

  return (
    <Page>
      <PageHeader eyebrow="Commercial" title="Billings" description="Review issued Billing snapshots, balances, and payment status." />
      <DataPanel className="mt-7">
        <form
          role="search"
          className="grid gap-4 border-b border-border p-5 md:grid-cols-[minmax(0,1fr)_auto] md:items-end"
          onSubmit={(event) => {
            event.preventDefault()
            setQuery((current) => ({ ...current, page: 1, search: search.trim() }))
          }}
        >
          <FormField label="Search billings" id="billing-search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Billing number, quotation number, or customer" />
          <div className="flex gap-2">
            <button type="submit" className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-border px-4 py-2 text-sm font-semibold hover:bg-surface-subtle"><Search className="size-4" aria-hidden="true" /> Search</button>
            <button type="button" onClick={() => { setSearch(''); setQuery(initialQuery) }} className="min-h-10 rounded-lg border border-border px-4 py-2 text-sm font-semibold hover:bg-surface-subtle">Clear</button>
          </div>
        </form>

        {billings.isPending ? <LoadingState label="Loading billings..." /> : null}
        {billings.isError ? <ErrorState title="We could not load billings." onRetry={() => { void billings.refetch() }} /> : null}
        {billings.data?.data.length === 0 ? <EmptyState title={query.search ? 'No billings match this search.' : 'No billings yet.'} description="A Billing appears automatically after the first valid payment on an Accepted quotation." /> : null}
        {billings.data && billings.data.data.length > 0 ? (
          <TableScroll>
            <table className={tableClassName}>
              <thead className={tableHeadClassName}><tr><th className={tableHeaderCellClassName}>Billing</th><th className={tableHeaderCellClassName}>Quotation</th><th className={tableHeaderCellClassName}>Customer</th><th className={tableHeaderCellClassName}>Event date</th><th className={tableHeaderCellClassName}>Total</th><th className={tableHeaderCellClassName}>Paid</th><th className={tableHeaderCellClassName}>Remaining</th><th className={tableHeaderCellClassName}>Status</th><th className={tableHeaderCellClassName}>Created</th><th className={`${tableHeaderCellClassName} text-right`}>Action</th></tr></thead>
              <tbody className={tableBodyClassName}>
                {billings.data.data.map((billing) => (
                  <tr key={billing.id}>
                    <td className={`${tableCellClassName} font-semibold text-primary`}>{billing.billing_number}</td>
                    <td className={tableCellClassName}><Link to={`/quotations/${billing.quotation.id}`} className="font-medium hover:text-primary">{billing.quotation.quotation_number}</Link></td>
                    <td className={tableCellClassName}>{billing.customer_snapshot.name}</td>
                    <td className={tableCellClassName}>{formatBusinessDate(billing.event_snapshot.event_date)}</td>
                    <td className={`${tableCellClassName} tabular-nums`}>{formatMoney(billing.total)}</td>
                    <td className={`${tableCellClassName} tabular-nums`}>{formatMoney(billing.payment_summary.amount_paid)}</td>
                    <td className={`${tableCellClassName} tabular-nums`}>{formatMoney(billing.payment_summary.remaining_balance)}</td>
                    <td className={tableCellClassName}><BillingPaymentStatusBadge status={billing.payment_summary.payment_status} /></td>
                    <td className={tableCellClassName}>{formatLifecycleTimestamp(billing.created_at)}</td>
                    <td className={`${tableCellClassName} text-right`}><Link to={`/billings/${billing.id}`} className="inline-flex min-h-9 items-center gap-2 rounded-lg border border-border px-3 py-2 text-xs font-semibold hover:bg-surface-subtle"><Eye className="size-4" aria-hidden="true" /> View</Link></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </TableScroll>
        ) : null}
        {billings.data ? <PaginationControls page={billings.data.meta.current_page} lastPage={billings.data.meta.last_page} total={billings.data.meta.total} onPageChange={(page) => setQuery((current) => ({ ...current, page }))} /> : null}
      </DataPanel>
    </Page>
  )
}
