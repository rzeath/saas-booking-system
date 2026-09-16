import 'temporal-polyfill/global'
import '@fullcalendar/react/skeleton.css'
import '@fullcalendar/react/themes/classic/theme.css'
import '@fullcalendar/react/themes/classic/palette.css'
import '@/routes/calendar.css'

import FullCalendar, {
  type CalendarRef,
  type DatesSetInfo,
  type EventClickInfo,
  type EventDisplayInfo,
} from '@fullcalendar/react'
import dayGridPlugin from '@fullcalendar/react/daygrid'
import interactionPlugin from '@fullcalendar/react/interaction'
import listPlugin from '@fullcalendar/react/list'
import timeGridPlugin from '@fullcalendar/react/timegrid'
import classicThemePlugin from '@fullcalendar/react/themes/classic'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { ArrowRight, CalendarDays, ChevronLeft, ChevronRight, MapPin } from 'lucide-react'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'

import { EmptyState, ErrorState, LoadingState } from '@/components/data/query-state'
import { SelectField } from '@/components/forms/form-field'
import { Page, PageHeader } from '@/components/layout/page'
import { Button } from '@/components/ui/button'
import { buttonVariants } from '@/components/ui/button-variants'
import { Modal } from '@/components/ui/modal'
import { BookingStatusBadge } from '@/components/ui/status-badge'
import {
  getCalendarEvents,
  getServices,
  type CalendarEvent as CalendarBooking,
  type CalendarQuery,
} from '@/lib/api'
import { bookingStatusLabel } from '@/lib/booking-format'
import {
  bookingsForCalendarDate,
  calendarEventInputs,
  compactDuration,
  formatCalendarDate,
  formatCalendarTime,
  formatCalendarTimeRange,
  fullCalendarView,
  manilaDateFromCalendarDate,
  manilaDateToday,
  MANILA_TIME_ZONE,
  MOBILE_CALENDAR_QUERY,
  serviceSummary,
  statusesForCalendarFilter,
  visibleCalendarRange,
  type CalendarDateRange,
  type CalendarStatusFilter,
  type CalendarView,
} from '@/lib/calendar'
import { calendarEventsQueryKey } from '@/lib/calendar-query'
import { serviceListQueryKey } from '@/lib/services-query'
import { cn } from '@/lib/utils'

const calendarPlugins = [dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin, classicThemePlugin]
const serviceFilterQuery = { page: 1, search: '', status: 'all' as const, per_page: 100 }
const statusOptions: { value: CalendarStatusFilter; label: string }[] = [
  { value: 'operational', label: 'Current bookings' },
  { value: 'PENDING', label: 'Pending' },
  { value: 'QUOTED', label: 'Quoted' },
  { value: 'CONFIRMED', label: 'Confirmed' },
  { value: 'COMPLETED', label: 'Completed' },
  { value: 'CANCELLED', label: 'Cancelled' },
]

export function CalendarRoute() {
  const calendarRef = useRef<CalendarRef>(null)
  const [view, setView] = useState<CalendarView>('month')
  const [isMobile, setIsMobile] = useState(() => window.matchMedia(MOBILE_CALENDAR_QUERY).matches)
  const [visibleRange, setVisibleRange] = useState<CalendarDateRange | null>(null)
  const [title, setTitle] = useState('')
  const [statusFilter, setStatusFilter] = useState<CalendarStatusFilter>('operational')
  const [serviceId, setServiceId] = useState(0)
  const [selectedDate, setSelectedDate] = useState(manilaDateToday)
  const [selectedBooking, setSelectedBooking] = useState<CalendarBooking | null>(null)

  const calendarQuery = useMemo<CalendarQuery | null>(() => visibleRange ? {
    ...visibleRange,
    statuses: statusesForCalendarFilter(statusFilter),
    ...(serviceId > 0 ? { service_id: serviceId } : {}),
  } : null, [serviceId, statusFilter, visibleRange])

  const bookings = useQuery({
    queryKey: calendarQuery ? calendarEventsQueryKey(calendarQuery) : ['calendar', 'waiting'],
    queryFn: () => getCalendarEvents(calendarQuery as CalendarQuery),
    enabled: calendarQuery !== null,
    placeholderData: keepPreviousData,
  })
  const services = useQuery({
    queryKey: serviceListQueryKey(serviceFilterQuery),
    queryFn: () => getServices(serviceFilterQuery),
    staleTime: 5 * 60_000,
  })

  const calendarEvents = useMemo(() => calendarEventInputs(bookings.data ?? []), [bookings.data])
  const selectedDateBookings = useMemo(
    () => bookingsForCalendarDate(bookings.data ?? [], selectedDate),
    [bookings.data, selectedDate],
  )
  const resolvedView = fullCalendarView(view, isMobile)

  const handleDatesSet = useCallback((info: DatesSetInfo) => {
    const range = visibleCalendarRange(info.startStr, info.endStr)
    setVisibleRange((current) => current?.start === range.start && current.end === range.end ? current : range)
    setTitle(info.view.title)
  }, [])

  const changeView = (nextView: CalendarView) => {
    setView(nextView)
    calendarRef.current?.getApi().changeView(fullCalendarView(nextView, isMobile))
  }

  useEffect(() => {
    const media = window.matchMedia(MOBILE_CALENDAR_QUERY)
    const handleChange = (event: MediaQueryListEvent) => setIsMobile(event.matches)
    media.addEventListener('change', handleChange)
    return () => media.removeEventListener('change', handleChange)
  }, [])

  useEffect(() => {
    const api = calendarRef.current?.getApi()
    if (api && api.view.type !== resolvedView) api.changeView(resolvedView)
  }, [resolvedView])

  const handleEventClick = (info: EventClickInfo) => {
    info.jsEvent.preventDefault()
    setSelectedBooking(info.event.extendedProps.booking as CalendarBooking)
  }

  return (
    <Page>
      <PageHeader eyebrow="Operations" title="Calendar" description="Review scheduled bookings across your event calendar." />

      <section className="mt-7 overflow-hidden rounded-xl border border-border bg-surface shadow-sm" aria-label="Booking calendar workspace">
        <div className="flex flex-col gap-4 border-b border-border px-4 py-4 xl:flex-row xl:items-end xl:justify-between">
          <div className="flex min-w-0 items-center justify-between gap-3 xl:flex-1">
            <div className="flex shrink-0 items-center gap-1">
              <Button variant="secondary" size="small" onClick={() => calendarRef.current?.getApi().today()}>Today</Button>
              <Button variant="ghost" size="icon" onClick={() => calendarRef.current?.getApi().prev()} aria-label="Previous period" title="Previous period" className="size-9">
                <ChevronLeft className="size-4" aria-hidden="true" />
              </Button>
              <Button variant="ghost" size="icon" onClick={() => calendarRef.current?.getApi().next()} aria-label="Next period" title="Next period" className="size-9">
                <ChevronRight className="size-4" aria-hidden="true" />
              </Button>
            </div>
            <h2 className="min-w-0 truncate text-base font-semibold text-foreground sm:text-lg" aria-live="polite">{title || 'Calendar'}</h2>
          </div>

          <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
            <div className="grid grid-cols-2 gap-3 sm:flex sm:items-end">
              <SelectField label="Status" id="calendar-status" value={statusFilter} onChange={(event) => setStatusFilter(event.target.value as CalendarStatusFilter)}>
                {statusOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
              </SelectField>
              <SelectField label="Service" id="calendar-service" value={serviceId} disabled={services.isPending} onChange={(event) => setServiceId(Number(event.target.value))}>
                <option value="0">All services</option>
                {services.data?.data.map((service) => <option key={service.id} value={service.id}>{service.name}</option>)}
              </SelectField>
            </div>
            <div className="inline-flex h-10 self-end rounded-lg border border-border bg-surface-subtle p-1" aria-label="Calendar view">
              {(['month', 'week'] as const).map((option) => (
                <button
                  key={option}
                  type="button"
                  aria-pressed={view === option}
                  onClick={() => changeView(option)}
                  className={cn(
                    'min-w-16 rounded-md px-3 text-xs font-semibold capitalize transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                    view === option ? 'bg-surface text-primary shadow-sm' : 'text-muted hover:text-foreground',
                  )}
                >
                  {option}
                </button>
              ))}
            </div>
          </div>
        </div>

        {services.isError ? <p role="alert" className="border-b border-border bg-danger-soft px-4 py-2 text-sm text-danger">Services could not be loaded. The calendar remains available without a service filter.</p> : null}
        {bookings.isError ? (
          <div className="border-b border-border">
            <ErrorState title="We could not load this calendar period." onRetry={() => { void bookings.refetch() }} />
          </div>
        ) : null}

        <div className="takda-calendar-workspace">
          <div className="takda-booking-calendar relative min-w-0" aria-busy={bookings.isFetching}>
            {bookings.isPending ? <div className="absolute inset-0 z-10 bg-surface/90"><LoadingState label="Loading calendar..." /></div> : null}
            {bookings.isFetching && !bookings.isPending ? <span role="status" className="absolute right-3 top-3 z-10 rounded-md bg-surface px-2 py-1 text-xs font-medium text-muted shadow-sm">Refreshing...</span> : null}
            <FullCalendar
              ref={calendarRef}
              plugins={calendarPlugins}
              initialView={resolvedView}
              timeZone={MANILA_TIME_ZONE}
              headerToolbar={false}
              height={isMobile ? 'auto' : '100%'}
              events={calendarEvents}
              datesSet={handleDatesSet}
              dateClick={(info) => setSelectedDate(info.dateStr.slice(0, 10))}
              eventClick={handleEventClick}
              eventContent={(info) => <CalendarEventContent info={info} />}
              eventClass={(info) => {
                const booking = info.event.extendedProps.booking as CalendarBooking
                return `takda-calendar-event takda-calendar-event--${booking.status.toLowerCase()}`
              }}
              eventDidMount={(info) => {
                const booking = info.event.extendedProps.booking as CalendarBooking
                info.el.tabIndex = 0
                info.el.setAttribute('role', 'button')
                info.el.setAttribute('aria-label', `${booking.customer_name}, ${serviceSummary(booking)}, ${bookingStatusLabel(booking.status)}`)
                info.el.onkeydown = (event) => {
                  if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault()
                    setSelectedBooking(booking)
                  }
                }
              }}
              eventWillUnmount={(info) => { info.el.onkeydown = null }}
              dayCellClass={(info) => manilaDateFromCalendarDate(info.date) === selectedDate ? 'takda-calendar-day--selected' : ''}
              dayCellDidMount={(info) => {
                const date = manilaDateFromCalendarDate(info.date)
                const target = info.el.querySelector<HTMLElement>('.fc-daygrid-day-number') ?? info.el
                target.tabIndex = 0
                target.setAttribute('role', 'button')
                target.setAttribute('aria-label', `Show bookings for ${formatCalendarDate(date).date}`)
                target.onkeydown = (event) => {
                  if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault()
                    setSelectedDate(date)
                  }
                }
              }}
              dayCellWillUnmount={(info) => {
                const target = info.el.querySelector<HTMLElement>('.fc-daygrid-day-number') ?? info.el
                target.onkeydown = null
              }}
              dayMaxEvents={2}
              moreLinkClick="popover"
              nowIndicator
              editable={false}
              selectable={false}
              eventStartEditable={false}
              eventDurationEditable={false}
              allDaySlot={false}
              scrollTime="06:00:00"
              slotMinTime="00:00:00"
              slotMaxTime="24:00:00"
              eventTimeFormat={{ hour: 'numeric', minute: '2-digit', meridiem: 'short' }}
            />
            {!bookings.isPending && !bookings.isError && bookings.data?.length === 0 ? (
              <div className="takda-calendar-empty"><EmptyState title="No bookings in this period." description="Try another period or adjust the filters." /></div>
            ) : null}
          </div>

          <SelectedDateAgenda date={selectedDate} bookings={selectedDateBookings} loading={bookings.isPending} />
        </div>
      </section>

      {selectedBooking ? <BookingSummary booking={selectedBooking} onClose={() => setSelectedBooking(null)} /> : null}
    </Page>
  )
}

function CalendarEventContent({ info }: { info: EventDisplayInfo }) {
  const booking = info.event.extendedProps.booking as CalendarBooking
  const isMonth = info.view.type === 'dayGridMonth'

  if (isMonth) {
    return (
      <span className="takda-calendar-month-event">
        <span className="takda-calendar-event-time">{formatCalendarTime(booking.start_at)}</span>
        <span className="takda-calendar-event-customer">{booking.customer_name}</span>
        <span className="takda-calendar-event-service">{serviceSummary(booking)}</span>
      </span>
    )
  }

  return (
    <span className="takda-calendar-week-event">
      <span className="takda-calendar-event-customer">{booking.customer_name}</span>
      <span className="takda-calendar-event-service">{serviceSummary(booking)}</span>
    </span>
  )
}

function SelectedDateAgenda({ date, bookings, loading }: { date: string; bookings: CalendarBooking[]; loading: boolean }) {
  const label = formatCalendarDate(date)

  return (
    <aside className="takda-calendar-agenda" aria-label={`Bookings for ${label.date}`} aria-live="polite">
      <header className="border-b border-border px-5 py-4">
        <h2 className="font-semibold text-foreground">{label.date}</h2>
        <p className="mt-0.5 text-sm text-muted">{label.weekday}</p>
      </header>
      <div className="min-h-0 flex-1 overflow-y-auto px-5">
        {loading ? <p role="status" className="py-5 text-sm text-muted">Loading bookings...</p> : null}
        {!loading && bookings.length === 0 ? <p className="py-5 text-sm text-muted">No bookings scheduled for this date.</p> : null}
        {!loading && bookings.length > 0 ? (
          <ol className="divide-y divide-border">
            {bookings.map((booking) => (
              <li key={booking.id}>
                <article className="py-5">
                  <div className="flex items-start justify-between gap-3">
                    <p className="text-sm font-semibold text-foreground">{formatCalendarTimeRange(booking.start_at, booking.end_at)}</p>
                    <BookingStatusBadge status={booking.status} />
                  </div>
                  <p className="mt-3 font-semibold text-foreground">{booking.customer_name}</p>
                  <p className="mt-0.5 text-sm text-muted">{booking.event_name}</p>
                  <p className="mt-2 text-sm text-foreground">{serviceSummary(booking)}</p>
                  <Link to={`/bookings/${booking.id}`} className="mt-3 inline-flex items-center gap-1 text-sm font-semibold text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                    View Booking <ArrowRight className="size-3.5" aria-hidden="true" />
                  </Link>
                </article>
              </li>
            ))}
          </ol>
        ) : null}
      </div>
    </aside>
  )
}

function BookingSummary({ booking, onClose }: { booking: CalendarBooking; onClose: () => void }) {
  const date = formatCalendarDate(booking.start_at.slice(0, 10))

  return (
    <Modal
      title={booking.customer_name}
      description={booking.booking_number}
      onClose={onClose}
      footer={<Link to={`/bookings/${booking.id}`} className={buttonVariants({ variant: 'primary' })}>View Booking <ArrowRight className="size-4" aria-hidden="true" /></Link>}
    >
      <div className="flex flex-wrap items-center gap-3">
        <BookingStatusBadge status={booking.status} />
        <span className="text-sm text-muted">{booking.event_type_name}</span>
      </div>
      <dl className="mt-5 grid gap-4 sm:grid-cols-2">
        <div>
          <dt className="text-xs font-semibold uppercase text-muted">Schedule</dt>
          <dd className="mt-1 text-sm text-foreground">{date.date}, {date.weekday}<br />{formatCalendarTimeRange(booking.start_at, booking.end_at)}</dd>
        </div>
        <div>
          <dt className="text-xs font-semibold uppercase text-muted">Event</dt>
          <dd className="mt-1 text-sm text-foreground">{booking.event_name}</dd>
        </div>
        <div className="sm:col-span-2">
          <dt className="flex items-center gap-1.5 text-xs font-semibold uppercase text-muted"><MapPin className="size-3.5" aria-hidden="true" /> Venue</dt>
          <dd className="mt-1 text-sm text-foreground">{booking.venue_name}</dd>
        </div>
      </dl>
      <div className="mt-6 border-t border-border pt-5">
        <h3 className="flex items-center gap-2 text-sm font-semibold text-foreground"><CalendarDays className="size-4 text-primary" aria-hidden="true" /> Services</h3>
        <ul className="mt-3 divide-y divide-border rounded-lg border border-border">
          {booking.services.map((service) => (
            <li key={service.id} className="px-4 py-3">
              <p className="font-semibold text-foreground">{service.service_name}</p>
              <p className="mt-0.5 text-sm text-muted">{service.package_name}</p>
              <p className="mt-2 text-xs text-muted">{compactDuration(service.duration_minutes)} · {formatCalendarTime(service.start_at)}–{formatCalendarTime(service.end_at)} · Qty {service.quantity}</p>
            </li>
          ))}
        </ul>
      </div>
    </Modal>
  )
}
