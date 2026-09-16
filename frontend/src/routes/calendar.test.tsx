import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest'

import type { CalendarEvent } from '@/lib/api'
import {
  bookingsForCalendarDate,
  calendarEventInputs,
  fullCalendarView,
  serviceSummary,
  visibleCalendarRange,
} from '@/lib/calendar'
import { CalendarRoute } from '@/routes/calendar'

const calendarHarness = vi.hoisted(() => ({
  props: null as Record<string, unknown> | null,
  changedViews: [] as string[],
}))

vi.mock('@fullcalendar/react', async () => {
  const React = await import('react')

  function dateInfo(startStr: string, endStr: string, type: string, title: string) {
    return { startStr, endStr, view: { type, title } }
  }

  const MockCalendar = React.forwardRef(function MockCalendar(props: Record<string, unknown>, ref) {
    calendarHarness.props = props
    const initialView = String(props.initialView)
    const datesSet = props.datesSet as ((info: unknown) => void) | undefined

    React.useEffect(() => {
      const isWeek = initialView.endsWith('Week')
      datesSet?.(isWeek
        ? dateInfo('2027-06-13T00:00:00+08:00', '2027-06-20T00:00:00+08:00', initialView, 'Jun 13 – 19, 2027')
        : dateInfo('2027-05-30T00:00:00+08:00', '2027-07-11T00:00:00+08:00', initialView, 'June 2027'))
    }, [datesSet, initialView])

    React.useImperativeHandle(ref, () => ({
      getApi: () => ({
        view: { type: initialView },
        getDate: () => new Date('2027-06-15T00:00:00+08:00'),
        changeView: (nextView: string) => {
          calendarHarness.changedViews.push(nextView)
          const isWeek = nextView.endsWith('Week')
          datesSet?.(isWeek
            ? dateInfo('2027-06-13T00:00:00+08:00', '2027-06-20T00:00:00+08:00', nextView, 'Jun 13 – 19, 2027')
            : dateInfo('2027-05-30T00:00:00+08:00', '2027-07-11T00:00:00+08:00', nextView, 'June 2027'))
        },
        today: () => datesSet?.(dateInfo('2027-05-30T00:00:00+08:00', '2027-07-11T00:00:00+08:00', initialView, 'June 2027')),
        prev: () => datesSet?.(dateInfo('2027-04-25T00:00:00+08:00', '2027-06-06T00:00:00+08:00', initialView, 'May 2027')),
        next: () => datesSet?.(dateInfo('2027-06-27T00:00:00+08:00', '2027-08-08T00:00:00+08:00', initialView, 'July 2027')),
      }),
    }), [datesSet, initialView])

    const events = props.events as Array<Record<string, unknown>>
    const eventContent = props.eventContent as ((info: Record<string, unknown>) => React.ReactNode) | undefined
    const eventClick = props.eventClick as ((info: Record<string, unknown>) => void) | undefined
    const dateClick = props.dateClick as ((info: Record<string, unknown>) => void) | undefined

    return (
      <div data-testid="full-calendar" data-view={initialView}>
        <button type="button" onClick={() => dateClick?.({ dateStr: '2027-06-15' })}>Select June 15</button>
        <button type="button" onClick={() => dateClick?.({ dateStr: '2027-06-16' })}>Select June 16</button>
        {events.map((event) => {
          const calendarEvent = {
            extendedProps: event.extendedProps as Record<string, unknown>,
            start: event.start,
            end: event.end,
          }
          return (
            <button
              key={String(event.id)}
              type="button"
              data-testid="calendar-event"
              onClick={(jsEvent) => eventClick?.({ event: calendarEvent, jsEvent })}
            >
              {eventContent?.({ event: calendarEvent, view: { type: initialView } })}
            </button>
          )
        })}
      </div>
    )
  })

  return { default: MockCalendar }
})

vi.mock('@fullcalendar/react/daygrid', () => ({ default: {} }))
vi.mock('@fullcalendar/react/timegrid', () => ({ default: {} }))
vi.mock('@fullcalendar/react/list', () => ({ default: {} }))
vi.mock('@fullcalendar/react/interaction', () => ({ default: {} }))
vi.mock('@fullcalendar/react/themes/classic', () => ({ default: {} }))

const mirrorService = {
  id: 11,
  service_name: 'Mirror Photobooth',
  package_name: 'Premium',
  duration_minutes: 180,
  quantity: 1,
  start_at: '2027-06-15 18:00',
  end_at: '2027-06-15 21:00',
}

const booking: CalendarEvent = {
  id: 31,
  booking_number: 'BK-2027-000031',
  status: 'CONFIRMED',
  start_at: '2027-06-15 18:00',
  end_at: '2027-06-15 22:00',
  customer_name: 'Maria Santos',
  event_name: 'Wedding Reception',
  event_type_name: 'Wedding',
  venue_name: 'Bai Hotel',
  services: [
    mirrorService,
    { ...mirrorService, id: 12, service_name: '360 Video Booth', package_name: 'Pro', duration_minutes: 240, end_at: '2027-06-15 22:00' },
  ],
}

const completedBooking: CalendarEvent = {
  ...booking,
  id: 32,
  booking_number: 'BK-2027-000032',
  status: 'COMPLETED',
  customer_name: 'Completed Customer',
}

const cancelledBooking: CalendarEvent = {
  ...booking,
  id: 33,
  booking_number: 'BK-2027-000033',
  status: 'CANCELLED',
  customer_name: 'Cancelled Customer',
}

const servicesPage = {
  data: [{ id: 9, name: 'Mirror Photobooth', total_units: 2, is_active: true, created_at: '2027-01-01', updated_at: '2027-01-01' }],
  links: { prev: null, next: null },
  meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 },
}

function jsonResponse(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

function installFetch() {
  const urls: string[] = []
  const handler = vi.fn(async (input: RequestInfo | URL) => {
    const value = String(input)
    urls.push(value)
    if (value.includes('/services?')) return jsonResponse(servicesPage)
    const url = new URL(value, 'http://localhost')
    const statuses = url.searchParams.getAll('statuses[]')
    const data = [booking, completedBooking, cancelledBooking].filter((entry) => statuses.includes(entry.status))
    return jsonResponse({ data })
  })
  vi.stubGlobal('fetch', handler)
  return { urls, handler }
}

function setMobile(matches: boolean) {
  const listeners = new Set<(event: MediaQueryListEvent) => void>()
  vi.stubGlobal('matchMedia', vi.fn(() => ({
    matches,
    media: '',
    onchange: null,
    addEventListener: (_type: string, listener: (event: MediaQueryListEvent) => void) => listeners.add(listener),
    removeEventListener: (_type: string, listener: (event: MediaQueryListEvent) => void) => listeners.delete(listener),
    addListener: vi.fn(),
    removeListener: vi.fn(),
    dispatchEvent: vi.fn(),
  })))
}

function renderCalendar() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={['/calendar']}>
        <Routes>
          <Route path="/calendar" element={<CalendarRoute />} />
          <Route path="/bookings/:bookingId" element={<p>Booking detail</p>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  calendarHarness.props = null
  calendarHarness.changedViews = []
  setMobile(false)
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('calendar helpers', () => {
  test('preserves the exclusive visible range and maps one booking to one event', () => {
    expect(visibleCalendarRange('2027-05-30T00:00:00+08:00', '2027-07-11T00:00:00+08:00')).toEqual({ start: '2027-05-30', end: '2027-07-11' })
    const events = calendarEventInputs([booking])
    expect(events).toHaveLength(1)
    expect(events[0]).toMatchObject({ start: '2027-06-15T18:00', end: '2027-06-15T22:00' })
    expect(serviceSummary(booking)).toBe('Mirror Photobooth +1 service')
  })

  test('uses half-open overlap semantics for the selected-date agenda', () => {
    const overnight = { ...booking, id: 41, start_at: '2027-06-14 23:00', end_at: '2027-06-15 02:00' }
    const endingAtStart = { ...booking, id: 42, start_at: '2027-06-14 20:00', end_at: '2027-06-15 00:00' }
    expect(bookingsForCalendarDate([booking, overnight, endingAtStart], '2027-06-15').map((item) => item.id)).toEqual([41, 31])
  })

  test('maps conceptual views to desktop grids and mobile lists', () => {
    expect(fullCalendarView('month', false)).toBe('dayGridMonth')
    expect(fullCalendarView('week', false)).toBe('timeGridWeek')
    expect(fullCalendarView('month', true)).toBe('listMonth')
    expect(fullCalendarView('week', true)).toBe('listWeek')
  })
})

describe('CalendarRoute', () => {
  test('renders /calendar and queries its exclusive range with operational statuses only', async () => {
    const { urls } = installFetch()
    renderCalendar()

    expect(screen.getByRole('heading', { name: 'Calendar' })).toBeInTheDocument()
    expect(await screen.findByText('Maria Santos')).toBeInTheDocument()
    const request = urls.find((url) => url.includes('/api/v1/calendar?'))
    expect(request).toBeDefined()
    const query = new URL(request as string, 'http://localhost').searchParams
    expect(query.get('start')).toBe('2027-05-30')
    expect(query.get('end')).toBe('2027-07-11')
    expect(query.getAll('statuses[]')).toEqual(['PENDING', 'QUOTED', 'CONFIRMED'])
    expect(query.has('staff_id')).toBe(false)
    expect(query.has('staff')).toBe(false)
    expect(screen.queryByText('Completed Customer')).not.toBeInTheDocument()
    expect(screen.queryByText('Cancelled Customer')).not.toBeInTheDocument()
  })

  test('sends explicit status and service filters and changes queried range on navigation', async () => {
    const { urls } = installFetch()
    renderCalendar()
    await screen.findByText('Maria Santos')

    fireEvent.change(screen.getByLabelText('Status'), { target: { value: 'COMPLETED' } })
    expect(await screen.findByText('Completed Customer')).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('Service'), { target: { value: '9' } })

    await waitFor(() => {
      const matching = urls.some((value) => {
        if (!value.includes('/api/v1/calendar?')) return false
        const params = new URL(value, 'http://localhost').searchParams
        return params.getAll('statuses[]').join(',') === 'COMPLETED' && params.get('service_id') === '9'
      })
      expect(matching).toBe(true)
    })

    fireEvent.click(screen.getByRole('button', { name: 'Next period' }))
    await waitFor(() => expect(urls.some((value) => value.includes('start=2027-06-27') && value.includes('end=2027-08-08'))).toBe(true))
  })

  test('updates the selected-date agenda and handles an empty date', async () => {
    installFetch()
    renderCalendar()
    await screen.findByText('Maria Santos')

    fireEvent.click(screen.getByRole('button', { name: 'Select June 15' }))
    const agenda = screen.getByRole('complementary', { name: 'Bookings for June 15' })
    expect(within(agenda).getByText('Wedding Reception')).toBeInTheDocument()
    expect(within(agenda).getByRole('link', { name: /View Booking/ })).toHaveAttribute('href', '/bookings/31')

    fireEvent.click(screen.getByRole('button', { name: 'Select June 16' }))
    expect(screen.getByText('No bookings scheduled for this date.')).toBeInTheDocument()
  })

  test('supports keyboard date selection and shows an empty filtered period', async () => {
    installFetch()
    renderCalendar()
    await screen.findByText('Maria Santos')

    const cell = document.createElement('td')
    const dateButton = document.createElement('a')
    dateButton.className = 'fc-daygrid-day-number'
    cell.append(dateButton)
    const mountDateCell = calendarHarness.props?.dayCellDidMount as ((info: { el: HTMLElement; date: Date }) => void)
    mountDateCell({ el: cell, date: new Date('2027-06-15T00:00:00+08:00') })
    fireEvent.keyDown(dateButton, { key: 'Enter' })
    expect(screen.getByRole('complementary', { name: 'Bookings for June 15' })).toBeInTheDocument()

    fireEvent.change(screen.getByLabelText('Status'), { target: { value: 'PENDING' } })
    expect(await screen.findByText('No bookings in this period.')).toBeInTheDocument()
  })

  test('opens an accessible read-only booking summary without staff or financial data', async () => {
    installFetch()
    renderCalendar()
    await screen.findByText('Maria Santos')

    fireEvent.click(screen.getAllByTestId('calendar-event')[0])
    const dialog = screen.getByRole('dialog', { name: 'Maria Santos' })
    expect(within(dialog).getByText('BK-2027-000031')).toBeInTheDocument()
    expect(within(dialog).getByText('Mirror Photobooth')).toBeInTheDocument()
    expect(within(dialog).getByText('360 Video Booth')).toBeInTheDocument()
    expect(within(dialog).queryByText(/Staff/i)).not.toBeInTheDocument()
    expect(within(dialog).queryByText(/Payment|Billing|Quotation|₱/i)).not.toBeInTheDocument()
    expect(within(dialog).getByRole('link', { name: /View Booking/ })).toHaveAttribute('href', '/bookings/31')
  })

  test('uses list views on mobile while preserving the Month and Week controls', async () => {
    setMobile(true)
    installFetch()
    renderCalendar()
    await screen.findByText('Maria Santos')

    expect(screen.getByTestId('full-calendar')).toHaveAttribute('data-view', 'listMonth')
    fireEvent.click(screen.getByRole('button', { name: 'week' }))
    expect(calendarHarness.changedViews).toContain('listWeek')
  })

  test('shows initial loading and offers a retry after a calendar error', async () => {
    let calendarCalls = 0
    vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
      const value = String(input)
      if (value.includes('/services?')) return jsonResponse(servicesPage)
      calendarCalls += 1
      if (calendarCalls === 1) return jsonResponse({ message: 'Failed' }, 500)
      return jsonResponse({ data: [booking] })
    }))
    renderCalendar()

    expect(screen.getByText('Loading calendar...')).toBeInTheDocument()
    expect(await screen.findByRole('alert')).toHaveTextContent('We could not load this calendar period')
    fireEvent.click(screen.getByRole('button', { name: 'Try again' }))
    expect(await screen.findByText('Maria Santos')).toBeInTheDocument()
  })
})
