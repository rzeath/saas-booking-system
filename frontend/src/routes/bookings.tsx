import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { EllipsisVertical, Plus, Search, X } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'

import {
  DataPanel,
  tableBodyClassName,
  tableCellClassName,
  tableClassName,
  tableHeadClassName,
  tableHeaderCellClassName,
} from '@/components/data/data-table'
import { FilterBar } from '@/components/data/filter-bar'
import { PaginationControls } from '@/components/data/pagination-controls'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/query-state'
import { FormField, SelectField } from '@/components/forms/form-field'
import { Page, PageHeader } from '@/components/layout/page'
import { Button } from '@/components/ui/button'
import { buttonVariants } from '@/components/ui/button-variants'
import { BookingStatusBadge } from '@/components/ui/status-badge'
import {
  getBookings,
  type Booking,
  type BookingQuery,
  type BookingStatus,
} from '@/lib/api'
import { bookingStatusLabel, formatMoney, sumMoney } from '@/lib/booking-format'
import { bookingListQueryKey } from '@/lib/bookings-query'
import { formatBusinessDate, formatManilaTime } from '@/lib/quotation-format'
import { cn } from '@/lib/utils'

const initialQuery: BookingQuery = {
  page: 1,
  search: '',
  status: '',
  event_date_from: '',
  event_date_to: '',
  customer_id: 0,
  event_type_id: 0,
}

const statuses: BookingStatus[] = ['PENDING', 'QUOTED', 'CONFIRMED', 'COMPLETED', 'CANCELLED']

function bookingTotal(booking: Booking): string {
  return sumMoney(booking.booking_services.map((line) => line.line_total))
}

function BookingSchedule({ booking }: { booking: Booking }) {
  if (!booking.end_at) {
    return (
      <>
        <span className="block whitespace-nowrap font-medium text-foreground">{formatBusinessDate(booking.event_date)}</span>
        <span className="mt-0.5 block whitespace-nowrap text-xs text-muted">{formatManilaTime(booking.start_time)}</span>
      </>
    )
  }

  const endDate = booking.end_at.split(' ')[0] ?? booking.event_date
  const endTime = formatManilaTime(booking.end_at)

  if (endDate === booking.event_date) {
    return (
      <>
        <span className="block whitespace-nowrap font-medium text-foreground">{formatBusinessDate(booking.event_date)}</span>
        <span className="mt-0.5 block whitespace-nowrap text-xs text-muted">{formatManilaTime(booking.start_time)} – {endTime}</span>
      </>
    )
  }

  return (
    <>
      <span className="block whitespace-nowrap font-medium text-foreground">{formatBusinessDate(booking.event_date)} · {formatManilaTime(booking.start_time)}</span>
      <span className="mt-0.5 block whitespace-nowrap text-xs text-muted">→ {formatBusinessDate(endDate)} · {endTime}</span>
    </>
  )
}

function BookingActions({ booking }: { booking: Booking }) {
  return (
    <details className="group relative inline-block text-left">
      <summary
        aria-label={`Actions for ${booking.booking_number}`}
        className="grid size-8 cursor-pointer list-none place-items-center rounded-lg text-muted transition hover:bg-surface-subtle hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring [&::-webkit-details-marker]:hidden"
      >
        <EllipsisVertical className="size-4" aria-hidden="true" />
      </summary>
      <div className="absolute bottom-full right-0 z-20 mb-1 min-w-36 rounded-lg border border-border bg-surface p-1 shadow-[0_8px_24px_rgb(35_28_31/0.12)]">
        <Link to={`/bookings/${booking.id}`} className="block rounded-md px-3 py-2 text-sm font-medium text-foreground hover:bg-surface-subtle">
          View booking
        </Link>
      </div>
    </details>
  )
}

function BookingRows({ bookings }: { bookings: Booking[] }) {
  return (
    <table className={cn(tableClassName, 'block md:table')}>
      <thead className={cn(tableHeadClassName, 'hidden md:table-header-group')}>
        <tr>
          <th className={tableHeaderCellClassName}>Reference</th>
          <th className={tableHeaderCellClassName}>Customer / Event</th>
          <th className={tableHeaderCellClassName}>Schedule</th>
          <th className={tableHeaderCellClassName}>Venue</th>
          <th className={`${tableHeaderCellClassName} text-right`}>Total</th>
          <th className={tableHeaderCellClassName}>Status</th>
          <th className={`${tableHeaderCellClassName} text-right`}>Actions</th>
        </tr>
      </thead>
      <tbody className={cn(tableBodyClassName, 'block md:table-row-group')}>
        {bookings.map((booking) => (
          <tr key={booking.id} className="relative grid gap-3 p-4 pr-14 md:table-row md:p-0">
            <td className="block md:table-cell md:px-5 md:py-3.5">
              <div className="flex flex-wrap items-center gap-2 md:block">
                <Link to={`/bookings/${booking.id}`} className="font-semibold text-primary hover:text-primary-hover">
                  {booking.booking_number}
                </Link>
                <span className="md:hidden"><BookingStatusBadge status={booking.status} /></span>
              </div>
            </td>
            <td className="block md:table-cell md:px-5 md:py-3.5">
              <span className="block font-semibold text-foreground">{booking.customer_snapshot.name}</span>
              <span className="mt-0.5 block text-xs text-muted">{booking.event_name || booking.event_type_snapshot.name}</span>
            </td>
            <td className="block md:table-cell md:px-5 md:py-3.5">
              <span className="mb-1 block text-[10px] font-semibold uppercase tracking-[0.08em] text-muted md:hidden">Schedule</span>
              <BookingSchedule booking={booking} />
            </td>
            <td className="block min-w-0 md:table-cell md:max-w-56 md:px-5 md:py-3.5">
              <span className="mb-1 block text-[10px] font-semibold uppercase tracking-[0.08em] text-muted md:hidden">Venue</span>
              <span className="block truncate text-foreground" title={booking.venue_name}>{booking.venue_name}</span>
            </td>
            <td className="block md:table-cell md:px-5 md:py-3.5 md:text-right">
              <span className="mb-1 block text-[10px] font-semibold uppercase tracking-[0.08em] text-muted md:hidden">Total</span>
              <span className="font-semibold tabular-nums text-foreground">{formatMoney(bookingTotal(booking))}</span>
            </td>
            <td className={cn(tableCellClassName, 'hidden md:table-cell')}><BookingStatusBadge status={booking.status} /></td>
            <td className="absolute right-3 top-3 block md:static md:table-cell md:px-5 md:py-3.5 md:text-right">
              <BookingActions booking={booking} />
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}

export function BookingsRoute() {
  const [query, setQuery] = useState<BookingQuery>(initialQuery)
  const [search, setSearch] = useState('')
  const bookings = useQuery({
    queryKey: bookingListQueryKey(query),
    queryFn: () => getBookings(query),
    placeholderData: keepPreviousData,
  })

  const hasFilters = Boolean(query.search || query.status || query.event_date_from)

  return (
    <Page>
      <PageHeader
        title="Bookings"
        description="Manage your event bookings"
        actions={(
          <Link to="/bookings/new" className={buttonVariants()}>
            <Plus className="size-4" aria-hidden="true" /> New Booking
          </Link>
        )}
      />

      <DataPanel className="mt-6">
        <FilterBar
          className="items-end gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[minmax(16rem,1fr)_12rem_12rem_auto]"
          onSubmit={(event) => {
            event.preventDefault()
            setQuery((current) => ({ ...current, page: 1, search: search.trim() }))
          }}
        >
          <FormField
            label="Search"
            id="booking-search"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="Booking number, customer, event, or venue"
          />
          <SelectField
            label="Status"
            id="booking-status-filter"
            value={query.status}
            onChange={(event) => setQuery((current) => ({ ...current, page: 1, status: event.target.value as BookingStatus | '' }))}
          >
            <option value="">All</option>
            {statuses.map((status) => <option key={status} value={status}>{bookingStatusLabel(status)}</option>)}
          </SelectField>
          <FormField
            label="Date"
            id="booking-date-filter"
            type="date"
            value={query.event_date_from}
            onChange={(event) => setQuery((current) => ({
              ...current,
              page: 1,
              event_date_from: event.target.value,
              event_date_to: event.target.value,
            }))}
          />
          <div className="flex min-h-10 items-center gap-1.5">
            <Button type="submit" size="small" className="min-h-10 px-3.5">
              <Search className="size-4" aria-hidden="true" /> Search
            </Button>
            {hasFilters ? (
              <Button
                variant="ghost"
                size="small"
                className="min-h-10 px-2.5"
                onClick={() => {
                  setSearch('')
                  setQuery(initialQuery)
                }}
              >
                <X className="size-4" aria-hidden="true" /> Clear
              </Button>
            ) : null}
          </div>
        </FilterBar>

        {bookings.isFetching && !bookings.isPending ? (
          <p role="status" className="border-b border-border bg-surface-subtle px-4 py-2 text-xs text-muted">Updating bookings…</p>
        ) : null}
        {bookings.isPending ? <LoadingState label="Loading bookings…" /> : null}
        {bookings.isError ? (
          <ErrorState title="We could not load bookings." onRetry={() => { void bookings.refetch() }} />
        ) : null}
        {bookings.data?.data.length === 0 ? (
          hasFilters ? (
            <EmptyState title="No bookings match your filters." description="Try changing your search or filters." />
          ) : (
            <EmptyState
              title="No bookings yet."
              description="Create your first booking to start managing events."
              action={<Link to="/bookings/new" className={buttonVariants({ size: 'small' })}><Plus className="size-4" aria-hidden="true" /> New Booking</Link>}
            />
          )
        ) : null}
        {bookings.data && bookings.data.data.length > 0 ? <BookingRows bookings={bookings.data.data} /> : null}
        {bookings.data ? (
          <PaginationControls
            page={bookings.data.meta.current_page}
            lastPage={bookings.data.meta.last_page}
            total={bookings.data.meta.total}
            perPage={bookings.data.meta.per_page}
            showPageNumbers
            onPageChange={(page) => setQuery((current) => ({ ...current, page }))}
          />
        ) : null}
      </DataPanel>
    </Page>
  )
}
