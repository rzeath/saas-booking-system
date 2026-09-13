import { useQuery } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'

import { PaginationControls } from '@/components/data/pagination-controls'
import { FormField, SelectField } from '@/components/forms/form-field'
import {
  getBookings,
  getCustomers,
  getEventTypes,
  type BookingQuery,
  type BookingStatus,
} from '@/lib/api'
import { bookingStatusLabel } from '@/lib/booking-format'
import { bookingListQueryKey } from '@/lib/bookings-query'
import { customerListQueryKey } from '@/lib/customers-query'
import { eventTypeListQueryKey } from '@/lib/event-types-query'

const initialQuery: BookingQuery = {
  page: 1,
  search: '',
  status: '',
  event_date_from: '',
  event_date_to: '',
  customer_id: 0,
  event_type_id: 0,
}
const selectorQuery = { page: 1, search: '', status: 'all' as const, per_page: 100 }
const statuses: BookingStatus[] = ['PENDING', 'QUOTED', 'CONFIRMED', 'COMPLETED', 'CANCELLED']

export function BookingsRoute() {
  const [query, setQuery] = useState<BookingQuery>(initialQuery)
  const [search, setSearch] = useState('')
  const bookings = useQuery({
    queryKey: bookingListQueryKey(query),
    queryFn: () => getBookings(query),
  })
  const customers = useQuery({
    queryKey: customerListQueryKey(selectorQuery),
    queryFn: () => getCustomers(selectorQuery),
  })
  const eventTypes = useQuery({
    queryKey: eventTypeListQueryKey(selectorQuery),
    queryFn: () => getEventTypes(selectorQuery),
  })

  return (
    <section>
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <p className="text-sm font-semibold tracking-[0.08em] text-cyan-400">Operations</p>
          <h1 className="mt-2 text-3xl font-semibold tracking-tight">Bookings</h1>
          <p className="mt-2 text-sm text-slate-400">Manage event schedules, services, and current booking status.</p>
        </div>
        <Link to="/bookings/new" className="inline-flex items-center gap-2 rounded-lg bg-cyan-500 px-4 py-2.5 text-sm font-semibold text-slate-950 hover:bg-cyan-400">
          <Plus className="size-4" aria-hidden="true" /> Create Booking
        </Link>
      </div>

      <div className="mt-8 rounded-2xl border border-slate-800 bg-slate-900">
        <form
          role="search"
          className="grid gap-4 border-b border-slate-800 p-5 md:grid-cols-3 xl:grid-cols-4"
          onSubmit={(event) => {
            event.preventDefault()
            setQuery((current) => ({ ...current, page: 1, search: search.trim() }))
          }}
        >
          <FormField label="Search bookings" id="booking-search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Number, customer, event, contact, or venue" />
          <SelectField label="Status" id="booking-status-filter" value={query.status} onChange={(event) => setQuery((current) => ({ ...current, page: 1, status: event.target.value as BookingStatus | '' }))}>
            <option value="">All statuses</option>
            {statuses.map((status) => <option key={status} value={status}>{bookingStatusLabel(status)}</option>)}
          </SelectField>
          <FormField label="Event date from" id="booking-date-from" type="date" value={query.event_date_from} onChange={(event) => setQuery((current) => ({ ...current, page: 1, event_date_from: event.target.value }))} />
          <FormField label="Event date to" id="booking-date-to" type="date" value={query.event_date_to} onChange={(event) => setQuery((current) => ({ ...current, page: 1, event_date_to: event.target.value }))} />
          <SelectField label="Customer" id="booking-customer-filter" value={query.customer_id} onChange={(event) => setQuery((current) => ({ ...current, page: 1, customer_id: Number(event.target.value) }))}>
            <option value="0">All customers</option>
            {customers.data?.data.map((customer) => <option key={customer.id} value={customer.id}>{customer.name}</option>)}
          </SelectField>
          <SelectField label="Event type" id="booking-event-type-filter" value={query.event_type_id} onChange={(event) => setQuery((current) => ({ ...current, page: 1, event_type_id: Number(event.target.value) }))}>
            <option value="0">All event types</option>
            {eventTypes.data?.data.map((eventType) => <option key={eventType.id} value={eventType.id}>{eventType.name}</option>)}
          </SelectField>
          <div className="flex items-end gap-2 md:col-span-3 xl:col-span-2">
            <button type="submit" className="rounded-lg border border-slate-700 px-4 py-2.5 text-sm font-medium hover:bg-slate-800">Search</button>
            <button type="button" onClick={() => { setSearch(''); setQuery(initialQuery) }} className="rounded-lg border border-slate-700 px-4 py-2.5 text-sm font-medium hover:bg-slate-800">Clear filters</button>
          </div>
        </form>

        {bookings.isPending ? <p role="status" className="p-8 text-center text-slate-400">Loading bookings…</p> : null}
        {bookings.isError ? (
          <div className="p-8 text-center">
            <p role="alert" className="text-rose-300">We could not load bookings.</p>
            <button type="button" onClick={() => { void bookings.refetch() }} className="mt-3 rounded-lg border border-slate-700 px-3 py-2 text-sm">Try again</button>
          </div>
        ) : null}
        {bookings.data?.data.length === 0 ? <p className="p-8 text-center text-slate-400">No bookings match these filters.</p> : null}
        {bookings.data && bookings.data.data.length > 0 ? (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-slate-800 text-xs uppercase tracking-wide text-slate-500">
                <tr><th className="px-5 py-3">Booking Number</th><th className="px-5 py-3">Customer</th><th className="px-5 py-3">Event</th><th className="px-5 py-3">Event Date</th><th className="px-5 py-3">Event Type</th><th className="px-5 py-3">Status</th><th className="px-5 py-3">Venue</th><th className="px-5 py-3 text-right">Actions</th></tr>
              </thead>
              <tbody className="divide-y divide-slate-800">
                {bookings.data.data.map((booking) => (
                  <tr key={booking.id}>
                    <td className="px-5 py-4 font-medium text-cyan-300">{booking.booking_number}</td>
                    <td className="px-5 py-4">{booking.customer_snapshot.name}</td>
                    <td className="px-5 py-4">{booking.event_name}</td>
                    <td className="px-5 py-4">{booking.event_date}</td>
                    <td className="px-5 py-4">{booking.event_type_snapshot.name}</td>
                    <td className="px-5 py-4">{bookingStatusLabel(booking.status)}</td>
                    <td className="px-5 py-4">{booking.venue_name}</td>
                    <td className="px-5 py-4 text-right"><Link to={`/bookings/${booking.id}`} className="rounded-lg border border-slate-700 px-3 py-1.5 hover:bg-slate-800">Open</Link></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : null}
        {bookings.data ? <PaginationControls page={bookings.data.meta.current_page} lastPage={bookings.data.meta.last_page} total={bookings.data.meta.total} onPageChange={(page) => setQuery((current) => ({ ...current, page }))} /> : null}
      </div>
    </section>
  )
}
