import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, expect, test, vi } from 'vitest'

import App from '@/App'
import { AppProviders } from '@/app/providers'

const auth = { user: { id: 7, name: 'Admin', email: 'admin@example.com' }, organization: { id: 1, name: 'Studio', status: 'active' } }
const settings = { display_name: 'Studio', email: null, phone: null, address: null, logo_path: null, currency: 'PHP', booking_prefix: 'BK', quotation_prefix: 'QT', billing_prefix: 'INV' }
const customer = { id: 2, name: 'Ana Cruz', email: 'ana@example.com', phone: '09171234567', address: 'Makati', notes: null, is_active: true, created_at: '2026-01-01', updated_at: '2026-01-01' }
const eventType = { id: 3, name: 'Wedding', is_active: true, created_at: '2026-01-01', updated_at: '2026-01-01' }
const service = { id: 4, name: 'Mirror Booth', total_units: 2, is_active: true, created_at: '2026-01-01', updated_at: '2026-01-01' }
const packageItem = { id: 5, service: { id: 4, name: 'Mirror Booth', is_active: true }, name: 'Premium', is_active: true, created_at: '2026-01-01', updated_at: '2026-01-01' }
const rate = { id: 6, event_type: { id: 3, name: 'Wedding', is_active: true }, service: { id: 4, name: 'Mirror Booth', is_active: true }, package: { id: 5, name: 'Premium', is_active: true }, duration_minutes: 180, unit_rate: '8000.00', is_active: true, is_available: true, created_at: '2026-01-01', updated_at: '2026-01-01' }
const booking = {
  id: 8, booking_number: 'BK-2027-000001', status: 'PENDING',
  customer: { id: 2, name: 'Ana Cruz', is_active: true }, customer_snapshot: { name: 'Ana Cruz', email: 'ana@example.com', phone: '09171234567', address: 'Makati' },
  event_type: { id: 3, name: 'Wedding', is_active: true }, event_type_snapshot: { name: 'Wedding' }, event_name: 'Ana & Leo', event_date: '2027-06-15', venue_name: 'The Glass House', venue_address: 'Makati', contact_person: 'Ana Cruz', contact_number: '09171234567', internal_notes: 'Load in early.',
  booking_services: [{ id: 9, service: { id: 4, name: 'Mirror Booth' }, package: { id: 5, name: 'Premium' }, start_at: '2027-06-15 18:00', end_at: '2027-06-15 21:00', duration_minutes: 180, quantity: 1, unit_rate: '8000.00', line_total: '8000.00', sort_order: 0 }],
  cancelled_at: null, cancellation_reason: null, created_at: '2026-01-01', updated_at: '2026-01-01',
}

function response(body: unknown, status = 200) { return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }) }
function page(data: unknown[], currentPage = 1, lastPage = 1) { return { data, links: { prev: null, next: lastPage > currentPage ? 'next' : null }, meta: { current_page: currentPage, last_page: lastPage, per_page: 15, total: data.length } } }

function fetchApi(overrides?: (url: string, init?: RequestInit) => Response | Promise<Response> | undefined) {
  return vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)
    const overridden = overrides?.(url, init)
    if (overridden) return overridden
    if (url.endsWith('/api/me')) return response(auth)
    if (url.endsWith('/sanctum/csrf-cookie')) return new Response(null, { status: 204 })
    if (url.endsWith('/api/business-settings')) return response(settings)
    if (url.includes('/api/customers?')) return response(page([customer]))
    if (url.includes('/api/event-types?')) return response(page([eventType]))
    if (url.includes('/api/services/4/packages?')) return response(page([packageItem]))
    if (url.includes('/api/service-rates?')) return response(page([rate]))
    if (url.includes('/api/services?')) return response(page([service]))
    if (url.endsWith('/api/bookings/8')) return response(booking)
    if (url.includes('/api/bookings?')) return response(page([booking]))
    return response({ message: 'Not found.' }, 404)
  })
}

function renderRoute(path: string, fetchMock = fetchApi()) {
  window.history.pushState({}, '', path)
  vi.stubGlobal('fetch', fetchMock)
  render(<AppProviders><App /></AppProviders>)
  return fetchMock
}

afterEach(() => { vi.unstubAllGlobals(); window.history.pushState({}, '', '/') })

test('lists bookings and applies all supported filters', async () => {
  const fetchMock = renderRoute('/bookings')
  const row = (await screen.findByText('BK-2027-000001')).closest('tr')
  expect(within(row!).getByText('Ana Cruz')).toBeInTheDocument()
  expect(within(row!).getByText('The Glass House')).toBeInTheDocument()

  fireEvent.change(screen.getByLabelText('Search bookings'), { target: { value: 'Ana' } })
  fireEvent.change(screen.getByLabelText('Status'), { target: { value: 'CONFIRMED' } })
  fireEvent.change(screen.getByLabelText('Event date from'), { target: { value: '2027-06-01' } })
  fireEvent.change(screen.getByLabelText('Event date to'), { target: { value: '2027-06-30' } })
  fireEvent.change(screen.getByLabelText('Customer'), { target: { value: '2' } })
  fireEvent.change(screen.getByLabelText('Event type'), { target: { value: '3' } })
  fireEvent.click(screen.getByRole('button', { name: 'Search' }))

  await waitFor(() => expect(fetchMock.mock.calls.some(([input]) => {
    const url = String(input)
    return url.includes('/api/bookings?') && url.includes('search=Ana') && url.includes('status=CONFIRMED') && url.includes('event_date_from=2027-06-01') && url.includes('event_date_to=2027-06-30') && url.includes('customer_id=2') && url.includes('event_type_id=3')
  })).toBe(true))
})

test('paginates the booking list and opens a booking', async () => {
  const fetchMock = fetchApi((url) => url.includes('/api/bookings?') ? response(page([booking], url.includes('page=2') ? 2 : 1, 2)) : undefined)
  renderRoute('/bookings', fetchMock)
  fireEvent.click(await screen.findByRole('button', { name: 'Next' }))
  await waitFor(() => expect(fetchMock.mock.calls.some(([input]) => String(input).includes('/api/bookings?page=2'))).toBe(true))
  fireEvent.click(await screen.findByRole('link', { name: 'Open' }))
  expect(await screen.findByRole('heading', { name: 'BK-2027-000001' })).toBeInTheDocument()
})

test('shows empty and recoverable list error states', async () => {
  let failed = true
  const fetchMock = fetchApi((url) => {
    if (url.includes('/api/bookings?')) return failed ? response({ message: 'Failed' }, 500) : response(page([]))
  })
  renderRoute('/bookings', fetchMock)
  expect(await screen.findByText('We could not load bookings.')).toBeInTheDocument()
  failed = false
  fireEvent.click(screen.getByRole('button', { name: 'Try again' }))
  expect(await screen.findByText('No bookings match these filters.')).toBeInTheDocument()
})

test('creates a booking with backend-authoritative pricing and availability preview', async () => {
  let submitted: Record<string, unknown> | undefined
  const fetchMock = fetchApi((url, init) => {
    if (url.endsWith('/api/bookings/availability') && init?.method === 'POST') return response({ available: true, services: [{ service_id: 4, available: true, total_units: 2, requested_quantity: 1, required_quantity: 1, over_capacity_by: 0 }] })
    if (url.endsWith('/api/bookings') && init?.method === 'POST') { submitted = JSON.parse(String(init.body)) as Record<string, unknown>; return response(booking, 201) }
  })
  renderRoute('/bookings/new', fetchMock)
  await screen.findByRole('heading', { name: 'Create booking' })
  await screen.findByRole('option', { name: 'Ana Cruz' })

  fireEvent.change(screen.getByLabelText('Customer'), { target: { value: '2' } })
  expect(screen.getByLabelText('Contact person')).toHaveValue('Ana Cruz')
  fireEvent.change(screen.getByLabelText('Event type'), { target: { value: '3' } })
  fireEvent.change(screen.getByLabelText('Event name / occasion'), { target: { value: 'Ana & Leo' } })
  fireEvent.change(screen.getByLabelText('Event date'), { target: { value: '2027-06-15' } })
  fireEvent.change(screen.getByLabelText('Venue name'), { target: { value: 'The Glass House' } })
  fireEvent.change(screen.getByLabelText('Service'), { target: { value: '4' } })
  await screen.findByRole('option', { name: 'Premium' })
  fireEvent.change(screen.getByLabelText('Package'), { target: { value: '5' } })
  await screen.findByRole('option', { name: '3 hours' })
  fireEvent.change(screen.getByLabelText('Duration'), { target: { value: '180' } })
  fireEvent.change(screen.getByLabelText('Start time'), { target: { value: '18:00' } })
  expect(await screen.findByText(/Estimated line total:/)).toHaveTextContent('₱8,000.00')
  fireEvent.click(screen.getByRole('button', { name: 'Check availability' }))
  expect(await screen.findByText('All requested services are available.')).toBeInTheDocument()
  fireEvent.change(screen.getByLabelText('Quantity'), { target: { value: '2' } })
  expect(await screen.findByText(/Availability is stale because the schedule changed/)).toBeInTheDocument()
  fireEvent.change(screen.getByLabelText('Quantity'), { target: { value: '1' } })
  fireEvent.click(screen.getByRole('button', { name: 'Create booking' }))

  await waitFor(() => expect(submitted).toBeDefined())
  const payload = submitted as { booking_services: Record<string, unknown>[] }
  expect(payload.booking_services[0]).toEqual({ service_id: 4, package_id: 5, start_time: '18:00', duration_minutes: 180, quantity: 1 })
  expect(payload.booking_services[0]).not.toHaveProperty('unit_rate')
  expect(payload.booking_services[0]).not.toHaveProperty('line_total')
})

test('supports repeated service lines and clears dependent choices', async () => {
  renderRoute('/bookings/new')
  await screen.findByRole('heading', { name: 'Create booking' })
  await screen.findByRole('option', { name: 'Wedding' })
  fireEvent.change(screen.getByLabelText('Event type'), { target: { value: '3' } })
  fireEvent.change(screen.getByLabelText('Service'), { target: { value: '4' } })
  await screen.findByRole('option', { name: 'Premium' })
  fireEvent.change(screen.getByLabelText('Package'), { target: { value: '5' } })
  await screen.findByRole('option', { name: '3 hours' })
  fireEvent.change(screen.getByLabelText('Duration'), { target: { value: '180' } })
  fireEvent.change(screen.getByLabelText('Package'), { target: { value: '0' } })
  expect(screen.getByLabelText('Duration')).toHaveValue('0')
  fireEvent.click(screen.getByRole('button', { name: 'Add service' }))
  expect(screen.getAllByLabelText('Service')).toHaveLength(2)
  fireEvent.change(screen.getAllByLabelText('Service')[0], { target: { value: '0' } })
  expect(screen.getAllByLabelText('Package')[0]).toHaveValue('0')
  expect(screen.getAllByLabelText('Duration')[0]).toHaveValue('0')
  fireEvent.click(screen.getAllByRole('button', { name: 'Remove service' })[1])
  expect(screen.getAllByLabelText('Service')).toHaveLength(1)
})

test('validates required fields and at least one service before create', async () => {
  const fetchMock = renderRoute('/bookings/new')
  await screen.findByRole('option', { name: 'Ana Cruz' })
  fireEvent.click(screen.getByRole('button', { name: 'Remove service' }))
  fireEvent.click(screen.getByRole('button', { name: 'Create booking' }))
  expect(await screen.findByText('Select a customer.')).toBeInTheDocument()
  expect(screen.getByText('Event name is required.')).toBeInTheDocument()
  expect(screen.getByText('Add at least one service.')).toBeInTheDocument()
  expect(fetchMock.mock.calls.some(([input, init]) => String(input).endsWith('/api/bookings') && init?.method === 'POST')).toBe(false)
})

test('shows a capacity conflict from the availability preview', async () => {
  let previewPayload: { booking_id?: number } | undefined
  const fetchMock = fetchApi((url, init) => {
    if (url.endsWith('/api/bookings/availability') && init?.method === 'POST') {
      previewPayload = JSON.parse(String(init.body)) as { booking_id?: number }
      return response({ available: false, services: [{ service_id: 4, available: false, total_units: 2, requested_quantity: 2, required_quantity: 3, over_capacity_by: 1 }] })
    }
  })
  renderRoute('/bookings/8/edit', fetchMock)
  await screen.findByRole('option', { name: '3 hours' })
  expect(screen.getByLabelText('Start time')).toHaveValue('18:00')
  expect(screen.getByText(/Saved snapshot:/)).toHaveTextContent('₱8,000.00')
  fireEvent.click(screen.getByRole('button', { name: 'Check availability' }))
  expect(await screen.findByText('One or more services exceed capacity.')).toBeInTheDocument()
  expect(screen.getByText(/1 over capacity/)).toBeInTheDocument()
  expect(previewPayload?.booking_id).toBe(8)
})

test('shows an authoritative capacity conflict when create is submitted', async () => {
  const fetchMock = fetchApi((url, init) => {
    if (url.endsWith('/api/bookings') && init?.method === 'POST') return response({ message: 'Validation failed.', errors: { booking_services: ['The requested schedule exceeds available service capacity.'] } }, 422)
  })
  renderRoute('/bookings/new', fetchMock)
  await screen.findByRole('option', { name: 'Ana Cruz' })
  fireEvent.change(screen.getByLabelText('Customer'), { target: { value: '2' } })
  fireEvent.change(screen.getByLabelText('Event type'), { target: { value: '3' } })
  fireEvent.change(screen.getByLabelText('Event name / occasion'), { target: { value: 'Ana & Leo' } })
  fireEvent.change(screen.getByLabelText('Event date'), { target: { value: '2027-06-15' } })
  fireEvent.change(screen.getByLabelText('Venue name'), { target: { value: 'The Glass House' } })
  fireEvent.change(screen.getByLabelText('Service'), { target: { value: '4' } })
  await screen.findByRole('option', { name: 'Premium' })
  fireEvent.change(screen.getByLabelText('Package'), { target: { value: '5' } })
  await screen.findByRole('option', { name: '3 hours' })
  fireEvent.change(screen.getByLabelText('Duration'), { target: { value: '180' } })
  fireEvent.change(screen.getByLabelText('Start time'), { target: { value: '18:00' } })
  fireEvent.click(screen.getByRole('button', { name: 'Create booking' }))
  expect(await screen.findByText('The requested schedule exceeds available service capacity.')).toBeInTheDocument()
})

test('updates a pending booking and retains service line identity', async () => {
  let updatePayload: { event_name: string; booking_services: { id?: number }[] } | undefined
  const fetchMock = fetchApi((url, init) => {
    if (url.endsWith('/api/bookings/8') && init?.method === 'PUT') {
      updatePayload = JSON.parse(String(init.body)) as typeof updatePayload
      return response({ ...booking, event_name: updatePayload?.event_name ?? booking.event_name })
    }
  })
  renderRoute('/bookings/8/edit', fetchMock)
  await screen.findByRole('option', { name: '3 hours' })
  fireEvent.change(screen.getByLabelText('Event name / occasion'), { target: { value: 'Updated occasion' } })
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
  expect(await screen.findByRole('heading', { name: 'BK-2027-000001' })).toBeInTheDocument()
  expect(updatePayload?.event_name).toBe('Updated occasion')
  expect(updatePayload?.booking_services[0].id).toBe(9)
})

test('maps backend booking conflicts and represents inactive current dependencies', async () => {
  const inactiveBooking = { ...booking, customer: { ...booking.customer, is_active: false }, event_type: { ...booking.event_type, is_active: false } }
  const fetchMock = fetchApi((url, init) => {
    if (url.includes('/api/customers?') || url.includes('/api/event-types?') || url.includes('/api/services?') || url.includes('/api/services/4/packages?') || url.includes('/api/service-rates?')) return response(page([]))
    if (url.endsWith('/api/bookings/8') && init?.method === 'PUT') return response({ message: 'Validation failed.', errors: { booking_services: ['The requested schedule exceeds available service capacity.'] } }, 422)
    if (url.endsWith('/api/bookings/8')) return response(inactiveBooking)
  })
  renderRoute('/bookings/8/edit', fetchMock)
  expect(await screen.findByRole('option', { name: 'Ana Cruz (current; inactive)' })).toBeInTheDocument()
  expect(screen.getByRole('option', { name: 'Wedding (current; inactive)' })).toBeInTheDocument()
  expect(screen.getByRole('option', { name: 'Mirror Booth (current; inactive)' })).toBeInTheDocument()
  expect(await screen.findByRole('option', { name: 'Premium (current; inactive)' })).toBeInTheDocument()
  expect(screen.getByRole('option', { name: '3 hours (saved; unavailable)' })).toBeInTheDocument()
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
  expect(await screen.findByText('The requested schedule exceeds available service capacity.')).toBeInTheDocument()
})

test('renders snapshots, total, and pending actions on booking detail', async () => {
  renderRoute('/bookings/8')
  expect(await screen.findByRole('heading', { name: 'BK-2027-000001' })).toBeInTheDocument()
  expect(screen.getByText('Customer snapshot')).toBeInTheDocument()
  expect(screen.getByText('Saved service, package, schedule, and price snapshots.')).toBeInTheDocument()
  expect(screen.getAllByText('₱8,000.00')).toHaveLength(3)
  expect(screen.getByRole('link', { name: /Edit/ })).toHaveAttribute('href', '/bookings/8/edit')
  expect(screen.getByRole('button', { name: /Cancel booking/ })).toBeInTheDocument()
})

test('cancels a pending booking only after confirmation', async () => {
  const cancelled = { ...booking, status: 'CANCELLED', cancelled_at: '2026-09-13', cancellation_reason: 'Client request' }
  let cancelCalls = 0
  const fetchMock = fetchApi((url, init) => {
    if (url.endsWith('/api/bookings/8/cancel') && init?.method === 'POST') { cancelCalls += 1; return response(cancelled) }
  })
  renderRoute('/bookings/8', fetchMock)
  fireEvent.click(await screen.findByRole('button', { name: /Cancel booking/ }))
  expect(cancelCalls).toBe(0)
  fireEvent.change(screen.getByLabelText('Reason (optional)'), { target: { value: 'Client request' } })
  fireEvent.click(screen.getByRole('button', { name: 'Confirm cancellation' }))
  expect(await screen.findByText('Booking cancelled. Historical details were preserved.')).toBeInTheDocument()
  await waitFor(() => expect(screen.queryByRole('button', { name: /Cancel booking/ })).not.toBeInTheDocument())
  expect(screen.getByText('Mirror Booth')).toBeInTheDocument()
})

test('shows the booking loading state', async () => {
  let resolveBookings!: (value: Response) => void
  const pending = new Promise<Response>((resolve) => { resolveBookings = resolve })
  renderRoute('/bookings', fetchApi((url) => url.includes('/api/bookings?') ? pending : undefined))
  expect(await screen.findByText('Loading bookings…')).toBeInTheDocument()
  resolveBookings(response(page([])))
  expect(await screen.findByText('No bookings match these filters.')).toBeInTheDocument()
})

test('blocks the edit UI for a non-pending booking', async () => {
  const confirmed = { ...booking, status: 'CONFIRMED' }
  renderRoute('/bookings/8/edit', fetchApi((url) => url.endsWith('/api/bookings/8') ? response(confirmed) : undefined))
  expect(await screen.findByRole('heading', { name: 'This booking is read-only' })).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Save changes' })).not.toBeInTheDocument()
})
