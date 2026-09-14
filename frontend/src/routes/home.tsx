import { useQuery } from '@tanstack/react-query'
import { BriefcaseBusiness, CalendarClock, CheckCircle2, Clock3, Plus } from 'lucide-react'
import { useMemo } from 'react'
import { Link, useOutletContext } from 'react-router-dom'

import {
  DataPanel,
  tableBodyClassName,
  tableCellClassName,
  tableClassName,
  tableHeaderCellClassName,
  tableHeadClassName,
  TableScroll,
} from '@/components/data/data-table'
import { EmptyState, ErrorState, LoadingState } from '@/components/data/query-state'
import { Page, PageHeader } from '@/components/layout/page'
import { buttonVariants } from '@/components/ui/button-variants'
import { BookingStatusBadge } from '@/components/ui/status-badge'
import {
  getBookings,
  getServices,
  type AuthContext,
  type Booking,
  type BookingQuery,
  type BookingStatus,
} from '@/lib/api'
import { bookingListQueryKey } from '@/lib/bookings-query'
import { serviceListQueryKey } from '@/lib/services-query'

function currentManilaDate(): string {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone: 'Asia/Manila',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(new Date())
  const value = Object.fromEntries(parts.map((part) => [part.type, part.value]))
  return `${value.year}-${value.month}-${value.day}`
}

function bookingQuery(status: BookingStatus, dateFrom: string): BookingQuery {
  return {
    page: 1,
    search: '',
    status,
    event_date_from: dateFrom,
    event_date_to: '',
    customer_id: 0,
    event_type_id: 0,
    per_page: 8,
  }
}

function formatEventDate(date: string): string {
  const [year, month, day] = date.split('-').map(Number)
  return new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' })
    .format(new Date(Date.UTC(year, month - 1, day)))
}

function formatScheduleTime(startAt?: string): string {
  if (!startAt) return 'Time not set'
  const time = startAt.split(' ')[1]
  const [hours, minutes] = time.split(':').map(Number)
  return new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit' })
    .format(new Date(2000, 0, 1, hours, minutes))
}

function SummaryCard({ label, value, helper, icon: Icon, tone }: { label: string; value: number; helper: string; icon: typeof CalendarClock; tone: 'primary' | 'warning' | 'success' | 'info' }) {
  const toneClass = {
    primary: 'bg-primary-soft text-primary',
    warning: 'bg-warning-soft text-warning',
    success: 'bg-success-soft text-success',
    info: 'bg-info-soft text-info',
  }[tone]

  return (
    <article className="rounded-xl border border-border bg-surface p-5 shadow-sm">
      <div className="flex items-start justify-between gap-4">
        <div>
          <p className="text-sm font-medium text-muted">{label}</p>
          <p className="mt-2 text-3xl font-bold tracking-tight text-foreground">{value}</p>
        </div>
        <span className={`grid size-10 place-items-center rounded-lg ${toneClass}`} aria-hidden="true"><Icon className="size-5" /></span>
      </div>
      <p className="mt-3 text-xs text-muted">{helper}</p>
    </article>
  )
}

export function HomeRoute() {
  const auth = useOutletContext<AuthContext>()
  const today = currentManilaDate()
  const pendingQuery = bookingQuery('PENDING', today)
  const quotedQuery = bookingQuery('QUOTED', today)
  const confirmedQuery = bookingQuery('CONFIRMED', today)
  const pending = useQuery({ queryKey: bookingListQueryKey(pendingQuery), queryFn: () => getBookings(pendingQuery), enabled: Boolean(today) })
  const quoted = useQuery({ queryKey: bookingListQueryKey(quotedQuery), queryFn: () => getBookings(quotedQuery), enabled: Boolean(today) })
  const confirmed = useQuery({ queryKey: bookingListQueryKey(confirmedQuery), queryFn: () => getBookings(confirmedQuery), enabled: Boolean(today) })
  const serviceQuery = { page: 1, search: '', status: 'active' as const, per_page: 1 }
  const services = useQuery({ queryKey: serviceListQueryKey(serviceQuery), queryFn: () => getServices(serviceQuery) })

  const upcoming = useMemo(() => {
    const items = [pending.data?.data ?? [], quoted.data?.data ?? [], confirmed.data?.data ?? []].flat()
    return items
      .filter((booking, index, all) => all.findIndex((candidate) => candidate.id === booking.id) === index)
      .sort((left, right) => {
        const leftTime = left.booking_services[0]?.start_at ?? `${left.event_date} 23:59`
        const rightTime = right.booking_services[0]?.start_at ?? `${right.event_date} 23:59`
        return leftTime.localeCompare(rightTime)
      })
      .slice(0, 8)
  }, [confirmed.data, pending.data, quoted.data])

  const bookingQueries = [pending, quoted, confirmed]
  const isPending = services.isPending || bookingQueries.some((query) => query.isPending)
  const isError = services.isError || bookingQueries.some((query) => query.isError)
  const upcomingTotal = bookingQueries.reduce((total, query) => total + (query.data?.meta.total ?? 0), 0)

  const retry = () => {
    void services.refetch()
    void pending.refetch()
    void quoted.refetch()
    void confirmed.refetch()
  }

  return (
    <Page>
      <PageHeader
        eyebrow="Overview"
        title="Dashboard"
        description={`A current view of ${auth.organization.name}'s upcoming work and booking pipeline.`}
        actions={<Link to="/bookings/new" className={buttonVariants()}><Plus className="size-4" aria-hidden="true" />New booking</Link>}
      />

      {isPending ? <div className="mt-8 rounded-xl border border-border bg-surface"><LoadingState label="Loading your dashboard…" /></div> : null}
      {isError ? <div className="mt-8 rounded-xl border border-border bg-surface"><ErrorState title="We could not load your dashboard." description="Your booking data has not been changed." onRetry={retry} /></div> : null}

      {!isPending && !isError ? (
        <>
          <div className="mt-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <SummaryCard label="Upcoming bookings" value={upcomingTotal} helper="Pending, quoted, and confirmed" icon={CalendarClock} tone="primary" />
            <SummaryCard label="Pending bookings" value={pending.data?.meta.total ?? 0} helper="Awaiting quotation progress" icon={Clock3} tone="warning" />
            <SummaryCard label="Confirmed bookings" value={confirmed.data?.meta.total ?? 0} helper="Upcoming confirmed events" icon={CheckCircle2} tone="success" />
            <SummaryCard label="Active services" value={services.data?.meta.total ?? 0} helper="Currently available in your catalog" icon={BriefcaseBusiness} tone="info" />
          </div>

          <div className="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_20rem]">
            <DataPanel>
              <div className="flex items-center justify-between gap-4 border-b border-border px-5 py-4">
                <div><h2 className="font-semibold text-foreground">Upcoming bookings</h2><p className="mt-0.5 text-xs text-muted">Next events across active booking statuses</p></div>
                <Link to="/bookings" className="text-sm font-semibold text-primary hover:text-primary-hover">View all</Link>
              </div>
              {upcoming.length === 0 ? (
                <EmptyState title="No upcoming bookings" description="New pending, quoted, or confirmed bookings will appear here." action={<Link to="/bookings/new" className={buttonVariants({ variant: 'secondary', size: 'small' })}>Create a booking</Link>} />
              ) : (
                <TableScroll>
                  <table className={tableClassName}>
                    <thead className={tableHeadClassName}><tr><th className={tableHeaderCellClassName}>Booking</th><th className={tableHeaderCellClassName}>Event</th><th className={tableHeaderCellClassName}>Customer</th><th className={tableHeaderCellClassName}>Schedule</th><th className={tableHeaderCellClassName}>Status</th><th className={`${tableHeaderCellClassName} text-right`}>Action</th></tr></thead>
                    <tbody className={tableBodyClassName}>
                      {upcoming.map((booking) => <UpcomingRow key={booking.id} booking={booking} />)}
                    </tbody>
                  </table>
                </TableScroll>
              )}
            </DataPanel>

            <DataPanel className="self-start">
              <div className="border-b border-border px-5 py-4"><h2 className="font-semibold text-foreground">Confirmed next</h2><p className="mt-0.5 text-xs text-muted">Events ready for delivery</p></div>
              {confirmed.data?.data.length ? (
                <ul className="divide-y divide-border">
                  {confirmed.data.data.slice(0, 4).map((booking) => (
                    <li key={booking.id} className="p-4">
                      <div className="flex items-start justify-between gap-3"><div className="min-w-0"><Link to={`/bookings/${booking.id}`} className="truncate text-sm font-semibold text-foreground hover:text-primary">{booking.event_name}</Link><p className="mt-1 truncate text-xs text-muted">{booking.customer_snapshot.name}</p></div><BookingStatusBadge status={booking.status} /></div>
                      <p className="mt-3 text-xs font-medium text-muted">{formatEventDate(booking.event_date)} · {formatScheduleTime(booking.booking_services[0]?.start_at)}</p>
                    </li>
                  ))}
                </ul>
              ) : <EmptyState title="No confirmed events" description="Accepted and paid bookings will be listed here." />}
            </DataPanel>
          </div>
        </>
      ) : null}
    </Page>
  )
}

function UpcomingRow({ booking }: { booking: Booking }) {
  return (
    <tr className="hover:bg-surface-subtle/70">
      <td className={`${tableCellClassName} whitespace-nowrap`}><Link to={`/bookings/${booking.id}`} className="font-semibold text-primary hover:text-primary-hover">{booking.booking_number}</Link></td>
      <td className={tableCellClassName}><p className="min-w-36 font-medium text-foreground">{booking.event_name}</p><p className="mt-0.5 text-xs text-muted">{booking.venue_name}</p></td>
      <td className={`${tableCellClassName} whitespace-nowrap text-muted`}>{booking.customer_snapshot.name}</td>
      <td className={`${tableCellClassName} whitespace-nowrap`}><p className="font-medium text-foreground">{formatEventDate(booking.event_date)}</p><p className="mt-0.5 text-xs text-muted">{formatScheduleTime(booking.booking_services[0]?.start_at)}</p></td>
      <td className={`${tableCellClassName} whitespace-nowrap`}><BookingStatusBadge status={booking.status} /></td>
      <td className={`${tableCellClassName} whitespace-nowrap text-right`}><Link to={`/bookings/${booking.id}`} className="text-sm font-semibold text-primary hover:text-primary-hover">Open</Link></td>
    </tr>
  )
}
