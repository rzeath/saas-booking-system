import { render, screen, within } from '@testing-library/react'
import { afterEach, expect, test, vi } from 'vitest'

import App from '@/App'
import { AppProviders } from '@/app/providers'
import type { BookingStatus } from '@/lib/api'

const auth = { user: { id: 7, name: 'Erica Admin', email: 'erica@example.com' }, organization: { id: 12, name: 'Rzeath Events', status: 'active' } }

function response(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

function booking(id: number, status: BookingStatus, eventName: string, eventDate: string) {
  return {
    id,
    booking_number: `BK-2027-00000${id}`,
    status,
    customer: { id: 2, name: 'Ana Cruz', is_active: true },
    customer_snapshot: { name: 'Ana Cruz', email: 'ana@example.com', phone: '09171234567', address: 'Makati' },
    event_type: { id: 3, name: 'Wedding', is_active: true },
    event_type_snapshot: { name: 'Wedding' },
    event_name: eventName,
    event_date: eventDate,
    venue_name: 'The Glass House',
    venue_address: 'Makati',
    contact_person: 'Ana Cruz',
    contact_number: '09171234567',
    internal_notes: null,
    booking_services: [{ id: id + 20, service: { id: 4, name: 'Mirror Booth' }, package: { id: 5, name: 'Premium' }, start_at: `${eventDate} 18:00`, end_at: `${eventDate} 21:00`, duration_minutes: 180, quantity: 1, unit_rate: '8000.00', line_total: '8000.00', sort_order: 0, staff: [] }],
    cancelled_at: null,
    cancellation_reason: null,
    created_at: '2026-01-01',
    updated_at: '2026-01-01',
  }
}

function page(data: unknown[], total = data.length) {
  return { data, links: { prev: null, next: null }, meta: { current_page: 1, last_page: 1, per_page: 8, total } }
}

function dashboardFetch(empty = false) {
  return vi.fn(async (input: RequestInfo | URL) => {
    const url = String(input)
    if (url.endsWith('/api/me')) return response(auth)
    if (url.includes('/api/services?')) return response(page(empty ? [] : [{ id: 4, name: 'Mirror Booth', total_units: 2, is_active: true, created_at: '2026-01-01', updated_at: '2026-01-01' }], empty ? 0 : 6))
    if (url.includes('/api/bookings?') && url.includes('status=PENDING')) return response(page(empty ? [] : [booking(1, 'PENDING', 'Ana & Leo', '2027-06-15')], empty ? 0 : 3))
    if (url.includes('/api/bookings?') && url.includes('status=QUOTED')) return response(page(empty ? [] : [booking(2, 'QUOTED', 'Product Launch', '2027-06-18')], empty ? 0 : 2))
    if (url.includes('/api/bookings?') && url.includes('status=CONFIRMED')) return response(page(empty ? [] : [booking(3, 'CONFIRMED', 'Company Night', '2027-06-20')], empty ? 0 : 4))
    return response({ message: 'Not found.' }, 404)
  })
}

function renderDashboard(fetchMock = dashboardFetch()) {
  window.history.pushState({}, '', '/')
  vi.stubGlobal('fetch', fetchMock)
  render(<AppProviders><App /></AppProviders>)
  return fetchMock
}

afterEach(() => {
  vi.unstubAllGlobals()
  window.history.pushState({}, '', '/')
})

test('renders operational dashboard metrics and upcoming booking data from existing APIs', async () => {
  const fetchMock = renderDashboard()

  expect(await screen.findByRole('heading', { name: 'Dashboard' })).toBeInTheDocument()
  const pendingLabel = await screen.findByText('Pending bookings')
  const upcomingLabel = screen.getAllByText('Upcoming bookings').find((element) => element.closest('article'))
  expect(within(upcomingLabel!.closest('article')!).getByText('9')).toBeInTheDocument()
  expect(within(pendingLabel.closest('article')!).getByText('3')).toBeInTheDocument()
  expect(within(screen.getByText('Confirmed bookings').closest('article')!).getByText('4')).toBeInTheDocument()
  expect(within(screen.getByText('Active services').closest('article')!).getByText('6')).toBeInTheDocument()
  expect(screen.getByRole('link', { name: 'BK-2027-000001' })).toHaveAttribute('href', '/bookings/1')
  expect(screen.getAllByText('Company Night')).toHaveLength(2)
  expect(fetchMock.mock.calls.some(([input]) => String(input).includes('event_date_from='))).toBe(true)
})

test('renders a useful dashboard empty state without inventing financial data', async () => {
  renderDashboard(dashboardFetch(true))

  expect(await screen.findByText('No upcoming bookings')).toBeInTheDocument()
  expect(screen.getByText('No confirmed events')).toBeInTheDocument()
  expect(screen.queryByText(/revenue|payment|balance/i)).not.toBeInTheDocument()
})
