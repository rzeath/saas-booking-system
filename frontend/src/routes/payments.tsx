import { useQuery } from '@tanstack/react-query'
import { Eye, Search } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'

import { DataPanel, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, TableScroll } from '@/components/data/data-table'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/query-state'
import { PaginationControls } from '@/components/data/pagination-controls'
import { FormField, SelectField } from '@/components/forms/form-field'
import { Page, PageHeader } from '@/components/layout/page'
import { PaymentStatusBadge } from '@/components/ui/status-badge'
import { getPayments, type PaymentMethod, type PaymentQuery, type PaymentStatus } from '@/lib/api'
import { paymentMethodLabel } from '@/lib/billing-format'
import { formatMoney } from '@/lib/booking-format'
import { paymentListQueryKey } from '@/lib/payments-query'
import { formatBusinessDate, formatLifecycleTimestamp, formatManilaWallClock } from '@/lib/quotation-format'

const initialQuery: PaymentQuery = { page: 1, search: '', status: '', payment_method: '', paid_from: '', paid_to: '' }
const methods: PaymentMethod[] = ['CASH', 'GCASH', 'BANK_TRANSFER', 'CHECK']
const statuses: PaymentStatus[] = ['POSTED', 'VOIDED']

export function PaymentsRoute() {
  const [query, setQuery] = useState(initialQuery)
  const [search, setSearch] = useState('')
  const payments = useQuery({ queryKey: paymentListQueryKey(query), queryFn: () => getPayments(query) })

  return (
    <Page>
      <PageHeader eyebrow="Commercial" title="Payments" description="Find posted and voided owner-recorded payments across Billings." />
      <DataPanel className="mt-7">
        <form
          role="search"
          className="grid gap-4 border-b border-border p-5 md:grid-cols-2 xl:grid-cols-[minmax(15rem,1fr)_11rem_12rem_10rem_10rem_auto] xl:items-end"
          onSubmit={(event) => {
            event.preventDefault()
            setQuery((current) => ({ ...current, page: 1, search: search.trim() }))
          }}
        >
          <FormField label="Search payments" id="payment-search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Reference, Billing, or quotation number" />
          <SelectField label="Status" id="payment-status-filter" value={query.status} onChange={(event) => setQuery((current) => ({ ...current, page: 1, status: event.target.value as PaymentStatus | '' }))}><option value="">All statuses</option>{statuses.map((status) => <option key={status} value={status}>{status === 'POSTED' ? 'Posted' : 'Voided'}</option>)}</SelectField>
          <SelectField label="Method" id="payment-method-filter" value={query.payment_method} onChange={(event) => setQuery((current) => ({ ...current, page: 1, payment_method: event.target.value as PaymentMethod | '' }))}><option value="">All methods</option>{methods.map((method) => <option key={method} value={method}>{paymentMethodLabel(method)}</option>)}</SelectField>
          <FormField label="Paid from" id="payment-paid-from" type="date" value={query.paid_from} onChange={(event) => setQuery((current) => ({ ...current, page: 1, paid_from: event.target.value }))} />
          <FormField label="Paid to" id="payment-paid-to" type="date" value={query.paid_to} min={query.paid_from || undefined} onChange={(event) => setQuery((current) => ({ ...current, page: 1, paid_to: event.target.value }))} />
          <div className="flex gap-2"><button type="submit" className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-border px-4 py-2 text-sm font-semibold hover:bg-surface-subtle"><Search className="size-4" aria-hidden="true" /> Search</button><button type="button" onClick={() => { setSearch(''); setQuery(initialQuery) }} className="min-h-10 rounded-lg border border-border px-4 py-2 text-sm font-semibold hover:bg-surface-subtle">Clear</button></div>
        </form>

        {payments.isPending ? <LoadingState label="Loading payments..." /> : null}
        {payments.isError ? <ErrorState title="We could not load payments." onRetry={() => { void payments.refetch() }} /> : null}
        {payments.data?.data.length === 0 ? <EmptyState title="No payments match these filters." /> : null}
        {payments.data && payments.data.data.length > 0 ? (
          <TableScroll>
            <table className={tableClassName}>
              <thead className={tableHeadClassName}><tr><th className={tableHeaderCellClassName}>Payment date</th><th className={tableHeaderCellClassName}>Billing</th><th className={tableHeaderCellClassName}>Quotation</th><th className={tableHeaderCellClassName}>Method</th><th className={tableHeaderCellClassName}>Reference</th><th className={tableHeaderCellClassName}>Amount</th><th className={tableHeaderCellClassName}>Status</th><th className={tableHeaderCellClassName}>Created</th><th className={`${tableHeaderCellClassName} text-right`}>Action</th></tr></thead>
              <tbody className={tableBodyClassName}>
                {payments.data.data.map((payment) => (
                  <tr key={payment.id} className={payment.status === 'VOIDED' ? 'bg-surface-subtle text-muted' : undefined}>
                    <td className={tableCellClassName}><span className="whitespace-nowrap">{formatManilaWallClock(payment.paid_at)}</span></td>
                    <td className={tableCellClassName}><Link to={`/billings/${payment.billing.id}`} className="font-semibold text-primary">{payment.billing.billing_number}</Link></td>
                    <td className={tableCellClassName}><Link to={`/quotations/${payment.quotation.id}`} className="font-medium hover:text-primary">{payment.quotation.quotation_number}</Link></td>
                    <td className={tableCellClassName}>{paymentMethodLabel(payment.payment_method)}</td>
                    <td className={tableCellClassName}>{payment.reference_number ?? 'Not provided'}</td>
                    <td className={`${tableCellClassName} font-medium tabular-nums`}>{formatMoney(payment.amount)}</td>
                    <td className={tableCellClassName}><PaymentStatusBadge status={payment.status} /></td>
                    <td className={tableCellClassName}><span className="sr-only">{formatBusinessDate(payment.created_at.slice(0, 10))}</span>{formatLifecycleTimestamp(payment.created_at)}</td>
                    <td className={`${tableCellClassName} text-right`}><Link to={`/billings/${payment.billing.id}`} aria-label={`View ${payment.billing.billing_number}`} className="inline-flex size-9 items-center justify-center rounded-lg border border-border hover:bg-surface"><Eye className="size-4" aria-hidden="true" /></Link></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </TableScroll>
        ) : null}
        {payments.data ? <PaginationControls page={payments.data.meta.current_page} lastPage={payments.data.meta.last_page} total={payments.data.meta.total} onPageChange={(page) => setQuery((current) => ({ ...current, page }))} /> : null}
      </DataPanel>
    </Page>
  )
}
