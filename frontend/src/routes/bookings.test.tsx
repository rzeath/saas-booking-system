import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, expect, test, vi } from 'vitest'

import App from '@/App'
import { AppProviders } from '@/app/providers'

const auth = { user: { id: 7, name: 'Admin', email: 'admin@example.com' }, organization: { id: 1, name: 'Studio', status: 'active' } }
const settings = { display_name: 'Studio', email: null, phone: null, address: null, logo_path: null, logo_url: null, theme_accent: 'plum', booking_prefix: 'BK', quotation_prefix: 'QT', billing_prefix: 'INV' }
const customer = { id: 2, name: 'Ana Cruz', email: 'ana@example.com', phone: '09171234567', address: 'Makati', notes: null, is_active: true, created_at: '2026-01-01', updated_at: '2026-01-01' }
const eventType = { id: 3, name: 'Wedding', is_active: true, created_at: '2026-01-01', updated_at: '2026-01-01' }
const service = { id: 4, name: 'Mirror Booth', total_units: 2, is_active: true, created_at: '2026-01-01', updated_at: '2026-01-01' }
const packageItem = { id: 5, services: [{ id: 4, name: 'Mirror Booth', is_active: true }], name: 'Premium', is_active: true, created_at: '2026-01-01', updated_at: '2026-01-01' }
const rate = { id: 6, event_type: { id: 3, name: 'Wedding', is_active: true }, service: { id: 4, name: 'Mirror Booth', is_active: true }, package: { id: 5, name: 'Premium', is_active: true }, duration_minutes: 180, unit_rate: '8000.00', is_active: true, is_available: true, created_at: '2026-01-01', updated_at: '2026-01-01' }
const staffAvailability = { staff: [{ id: 10, name: 'Mia Santos', available: true }, { id: 11, name: 'Carlo Reyes', available: false }] }
const booking = {
  id: 8, booking_number: 'BK-2027-000001', status: 'PENDING',
  customer: { id: 2, name: 'Ana Cruz', is_active: true }, customer_snapshot: { name: 'Ana Cruz', email: 'ana@example.com', phone: '09171234567', address: 'Makati' },
  event_type: { id: 3, name: 'Wedding', is_active: true }, event_type_snapshot: { name: 'Wedding' }, event_name: 'Ana & Leo', start_at: '2027-06-15 18:00', event_date: '2027-06-15', start_time: '18:00', end_at: '2027-06-15 21:00', venue_name: 'The Glass House', venue_address: 'Makati', contact_person: 'Ana Cruz', contact_number: '09171234567', internal_notes: 'Load in early.',
  booking_services: [{ id: 9, service: { id: 4, name: 'Mirror Booth' }, package: { id: 5, name: 'Premium' }, start_at: '2027-06-15 18:00', end_at: '2027-06-15 21:00', duration_minutes: 180, quantity: 1, unit_rate: '8000.00', line_total: '8000.00', sort_order: 0, staff: [{ id: 10, name: 'Mia Santos', is_active: true }] }],
  cancelled_at: null, cancellation_reason: null, created_at: '2026-01-01', updated_at: '2026-01-01',
}

function response(body: unknown, status = 200) { return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }) }
function page(data: unknown[], currentPage = 1, lastPage = 1, total = data.length) { return { data, links: { prev: null, next: lastPage > currentPage ? 'next' : null }, meta: { current_page: currentPage, last_page: lastPage, per_page: 15, total } } }

function fetchApi(overrides?: (url: string, init?: RequestInit) => Response | Promise<Response> | undefined) {
  return vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)
    const overridden = overrides?.(url, init)
    if (overridden) return overridden
    if (url.endsWith('/api/me')) return response(auth)
    if (url.endsWith('/sanctum/csrf-cookie')) return new Response(null, { status: 204 })
    if (url.endsWith('/api/v1/business-settings')) return response(settings)
    if (url.includes('/api/v1/customers?')) return response(page([customer]))
    if (url.includes('/api/v1/event-types?')) return response(page([eventType]))
    if (url.includes('/api/v1/services/4/package-mappings?')) return response(page([packageItem]))
    if (url.includes('/api/v1/services/4/packages/5/rates?')) return response(page([rate]))
    if (url.includes('/api/v1/services?')) return response(page([service]))
    if (url.endsWith('/api/v1/bookings/staff-availability')) return response(staffAvailability)
    if (url.endsWith('/api/v1/bookings/8')) return response(booking)
    if (url.includes('/api/v1/bookings?')) return response(page([booking]))
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

test('renders the responsive booking list with the shared shell hierarchy', async () => {
  const listBooking = { ...booking, start_at: '2027-06-15 17:30', start_time: '17:30' }
  renderRoute('/bookings', fetchApi((url) => url.includes('/api/v1/bookings?') ? response(page([listBooking])) : undefined))

  expect(await screen.findByRole('heading', { name: 'Bookings' })).toBeInTheDocument()
  expect(screen.getByText('Manage your event bookings')).toBeInTheDocument()
  expect(screen.getByRole('link', { name: 'New Booking' })).toHaveAttribute('href', '/bookings/new')
  expect(screen.getByPlaceholderText('Booking number, customer, event, or venue')).toBeInTheDocument()
  const reference = await screen.findByRole('link', { name: 'BK-2027-000001' })
  expect(screen.getAllByRole('columnheader').map((heading) => heading.textContent)).toEqual([
    'Reference',
    'Customer / Event',
    'Schedule',
    'Venue',
    'Total',
    'Status',
    'Actions',
  ])
  expect(screen.queryByRole('columnheader', { name: 'Staff' })).not.toBeInTheDocument()

  expect(reference).toHaveAttribute('href', '/bookings/8')
  const row = reference.closest('tr')
  expect(row).toHaveClass('grid', 'md:table-row')
  expect(within(row!).getByText('Ana Cruz')).toBeInTheDocument()
  expect(within(row!).getByText('Ana & Leo')).toBeInTheDocument()
  expect(within(row!).getByText('The Glass House')).toBeInTheDocument()
  expect(within(row!).getByText('Jun 15, 2027')).toBeInTheDocument()
  expect(within(row!).getByText('5:30 PM – 9:00 PM')).toBeInTheDocument()
  expect(within(row!).getByText('₱8,000.00')).toBeInTheDocument()
  expect(within(row!).getAllByText('Pending')).not.toHaveLength(0)
  expect(within(row!).getByLabelText('Actions for BK-2027-000001')).toBeInTheDocument()
})

test('applies only the supported search, status, and date filters', async () => {
  const fetchMock = renderRoute('/bookings')
  await screen.findByRole('link', { name: 'BK-2027-000001' })

  fireEvent.change(screen.getByLabelText('Search'), { target: { value: 'Ana' } })
  fireEvent.change(screen.getByLabelText('Status'), { target: { value: 'CONFIRMED' } })
  fireEvent.change(screen.getByLabelText('Date'), { target: { value: '2027-06-15' } })
  fireEvent.click(screen.getByRole('button', { name: 'Search' }))

  await waitFor(() => expect(fetchMock.mock.calls.some(([input]) => {
    const url = String(input)
    return url.includes('/api/v1/bookings?') && url.includes('search=Ana') && url.includes('status=CONFIRMED') && url.includes('event_date_from=2027-06-15') && url.includes('event_date_to=2027-06-15')
  })).toBe(true))
  expect(screen.queryByLabelText('Customer')).not.toBeInTheDocument()
  expect(screen.queryByLabelText('Event type')).not.toBeInTheDocument()
})

test('paginates the booking list and opens a booking', async () => {
  const fetchMock = fetchApi((url) => url.includes('/api/v1/bookings?') ? response(page([booking], url.includes('page=2') ? 2 : 1, 2, 30)) : undefined)
  renderRoute('/bookings', fetchMock)
  expect(await screen.findByText('Showing 1–15 of 30')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Go to page 1' })).toHaveAttribute('aria-current', 'page')
  expect(screen.getByRole('button', { name: 'Go to page 2' })).toBeInTheDocument()
  fireEvent.click(await screen.findByRole('button', { name: 'Next' }))
  await waitFor(() => expect(fetchMock.mock.calls.some(([input]) => String(input).includes('/api/v1/bookings?page=2'))).toBe(true))
  fireEvent.click(await screen.findByRole('link', { name: 'BK-2027-000001' }))
  expect(await screen.findByRole('heading', { name: 'BK-2027-000001' })).toBeInTheDocument()
})

test('renders an overnight booking from the shared booking schedule', async () => {
  const overnight = {
    ...booking,
    start_at: '2027-06-15 23:00',
    start_time: '23:00',
    end_at: '2027-06-16 02:00',
  }
  renderRoute('/bookings', fetchApi((url) => url.includes('/api/v1/bookings?') ? response(page([overnight])) : undefined))
  const row = (await screen.findByRole('link', { name: 'BK-2027-000001' })).closest('tr')
  expect(within(row!).getByText('Jun 15, 2027 · 11:00 PM')).toBeInTheDocument()
  expect(within(row!).getByText('→ Jun 16, 2027 · 2:00 AM')).toBeInTheDocument()
})

test('shows the filtered empty state', async () => {
  const fetchMock = fetchApi((url) => {
    if (url.includes('/api/v1/bookings?')) return url.includes('status=CONFIRMED') ? response(page([])) : response(page([booking]))
  })
  renderRoute('/bookings', fetchMock)
  await screen.findByRole('link', { name: 'BK-2027-000001' })
  fireEvent.change(screen.getByLabelText('Status'), { target: { value: 'CONFIRMED' } })
  expect(await screen.findByText('No bookings match your filters.')).toBeInTheDocument()
  expect(screen.getByText('Try changing your search or filters.')).toBeInTheDocument()
})

test('shows the first-booking empty state and action', async () => {
  renderRoute('/bookings', fetchApi((url) => url.includes('/api/v1/bookings?') ? response(page([])) : undefined))
  expect(await screen.findByText('No bookings yet.')).toBeInTheDocument()
  expect(screen.getByText('Create your first booking to start managing events.')).toBeInTheDocument()
  expect(screen.getAllByRole('link', { name: 'New Booking' })).toHaveLength(2)
})

test('shows a recoverable list error state', async () => {
  let failed = true
  const fetchMock = fetchApi((url) => {
    if (url.includes('/api/v1/bookings?')) return failed ? response({ message: 'Failed' }, 500) : response(page([]))
  })
  renderRoute('/bookings', fetchMock)
  expect(await screen.findByText('We could not load bookings.')).toBeInTheDocument()
  failed = false
  fireEvent.click(screen.getByRole('button', { name: 'Try again' }))
  expect(await screen.findByText('No bookings yet.')).toBeInTheDocument()
})

test('renders the create workflow structure without unsupported schedule or commercial controls', async () => {
  renderRoute('/bookings/new')
  expect(await screen.findByRole('heading', { name: 'New Booking' })).toBeInTheDocument()
  expect(screen.getByText('Create and schedule an event booking.')).toBeInTheDocument()
  await screen.findByRole('option', { name: 'Ana Cruz' })

  const sectionNames = ['Customer', 'Event Details', 'Services', 'Additional Details', 'Booking Summary']
  sectionNames.forEach((name) => expect(screen.getByRole('heading', { name })).toBeInTheDocument())
  expect(screen.getAllByLabelText('Event date')).toHaveLength(1)
  expect(screen.getAllByLabelText('Start time')).toHaveLength(1)
  expect(screen.getAllByLabelText('Duration')).toHaveLength(1)
  expect(screen.queryByLabelText(/end date/i)).not.toBeInTheDocument()
  expect(screen.queryByLabelText(/end time/i)).not.toBeInTheDocument()
  expect(screen.queryByLabelText(/booking duration/i)).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: /draft|quotation/i })).not.toBeInTheDocument()
  expect(screen.getAllByRole('button', { name: 'Create Booking' })).toHaveLength(1)

  const summary = screen.getByRole('heading', { name: 'Booking Summary' }).closest('aside')
  expect(summary).toHaveClass('xl:sticky', 'xl:top-7')
  expect(summary?.closest('form')).toHaveClass('xl:grid-cols-[minmax(0,1fr)_20rem]')
  expect(within(summary!).getByText('No services yet')).toBeInTheDocument()
  expect(within(summary!).getByText('—')).toBeInTheDocument()
  expect(within(summary!).getByRole('link', { name: 'Cancel' })).toHaveAttribute('href', '/bookings')
  expect(screen.getByLabelText('Venue address')).toHaveAttribute('rows', '2')
  expect(screen.getByLabelText('Venue address')).toHaveClass('!min-h-20')
  expect(screen.getByRole('heading', { name: 'Service availability' })).toBeInTheDocument()
})

test('derives the overnight Booking Summary and total from the shared start and service duration', async () => {
  renderRoute('/bookings/new')
  await screen.findByRole('option', { name: 'Wedding' })
  fireEvent.change(screen.getByLabelText('Event type'), { target: { value: '3' } })
  fireEvent.change(screen.getByLabelText('Event date'), { target: { value: '2027-06-15' } })
  fireEvent.change(screen.getByLabelText('Start time'), { target: { value: '23:00' } })
  fireEvent.change(screen.getByLabelText('Service'), { target: { value: '4' } })
  await screen.findByRole('option', { name: 'Premium' })
  fireEvent.change(screen.getByLabelText('Package'), { target: { value: '5' } })
  await screen.findByRole('option', { name: '3 hours' })
  fireEvent.change(screen.getByLabelText('Duration'), { target: { value: '180' } })

  const summary = screen.getByRole('heading', { name: 'Booking Summary' }).closest('aside')
  expect(await within(summary!).findByText('Jun 15, 2027 · 11:00 PM')).toBeInTheDocument()
  expect(within(summary!).getByText('→ Jun 16, 2027 · 2:00 AM')).toBeInTheDocument()
  expect(within(summary!).getByText('1 service')).toBeInTheDocument()
  expect(await within(summary!).findByText('₱8,000.00')).toBeInTheDocument()
})

test('hydrates Edit Booking and derives its effective end from the longest service', async () => {
  const bookingWithTwoDurations = {
    ...booking,
    end_at: '2027-06-15 22:00',
    booking_services: [
      booking.booking_services[0],
      { ...booking.booking_services[0], id: 10, duration_minutes: 240, end_at: '2027-06-15 22:00', sort_order: 1 },
    ],
  }
  renderRoute('/bookings/8/edit', fetchApi((url) => url.endsWith('/api/v1/bookings/8') ? response(bookingWithTwoDurations) : undefined))

  expect(await screen.findByRole('heading', { name: 'Edit Booking' })).toBeInTheDocument()
  expect(screen.getByText('Update booking details and services.')).toBeInTheDocument()
  expect(screen.getByLabelText('Event date')).toHaveValue('2027-06-15')
  expect(screen.getByLabelText('Start time')).toHaveValue('18:00')
  expect(screen.getAllByLabelText('Duration')).toHaveLength(2)
  const summary = screen.getByRole('heading', { name: 'Booking Summary' }).closest('aside')
  expect(within(summary!).getByText('6:00 PM – 10:00 PM')).toBeInTheDocument()
  expect(within(summary!).getByText('2 services')).toBeInTheDocument()
})

test('creates a booking with backend-authoritative pricing and availability preview', async () => {
  let submitted: Record<string, unknown> | undefined
  let availabilityPayload: Record<string, unknown> | undefined
  const staffPayloads: Record<string, unknown>[] = []
  const fetchMock = fetchApi((url, init) => {
    if (url.endsWith('/api/v1/bookings/staff-availability') && init?.method === 'POST') {
      staffPayloads.push(JSON.parse(String(init.body)) as Record<string, unknown>)
      return response(staffAvailability)
    }
    if (url.endsWith('/api/v1/bookings/availability') && init?.method === 'POST') {
      availabilityPayload = JSON.parse(String(init.body)) as Record<string, unknown>
      return response({ available: true, services: [{ service_id: 4, available: true, total_units: 2, requested_quantity: 1, required_quantity: 1, over_capacity_by: 0 }] })
    }
    if (url.endsWith('/api/v1/bookings') && init?.method === 'POST') { submitted = JSON.parse(String(init.body)) as Record<string, unknown>; return response(booking, 201) }
  })
  renderRoute('/bookings/new', fetchMock)
  await screen.findByRole('heading', { name: 'New Booking' })
  await screen.findByRole('option', { name: 'Ana Cruz' })

  fireEvent.click(screen.getByRole('option', { name: 'Ana Cruz' }))
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
  const availableStaff = await screen.findByRole('checkbox', { name: 'Mia Santos' })
  expect(screen.getByRole('checkbox', { name: /Carlo Reyes.*Unavailable/ })).toBeDisabled()
  fireEvent.click(availableStaff)
  fireEvent.change(screen.getByLabelText('Start time'), { target: { value: '18:30' } })
  await waitFor(() => expect(staffPayloads.some((payload) => payload.start_time === '18:30')).toBe(true))
  expect(screen.getByRole('checkbox', { name: 'Mia Santos' })).toBeChecked()
  expect((await screen.findByText('Line Total')).parentElement).toHaveTextContent('₱8,000.00')
  fireEvent.click(screen.getByRole('button', { name: 'Check availability' }))
  expect(await screen.findByText('All requested services are available.')).toBeInTheDocument()
  fireEvent.change(screen.getByLabelText('Start time'), { target: { value: '19:00' } })
  expect(await screen.findByText(/Availability is stale because the schedule changed/)).toBeInTheDocument()
  await waitFor(() => expect(staffPayloads.some((payload) => payload.start_time === '19:00')).toBe(true))
  expect(screen.getByRole('checkbox', { name: 'Mia Santos' })).toBeChecked()
  fireEvent.click(screen.getByRole('button', { name: 'Check availability' }))
  await waitFor(() => expect(screen.queryByText(/Availability is stale because the schedule changed/)).not.toBeInTheDocument())
  fireEvent.change(screen.getByLabelText('Quantity'), { target: { value: '2' } })
  expect(await screen.findByText(/Availability is stale because the schedule changed/)).toBeInTheDocument()
  fireEvent.change(screen.getByLabelText('Quantity'), { target: { value: '1' } })
  fireEvent.click(screen.getByRole('button', { name: 'Create Booking' }))

  await waitFor(() => expect(submitted).toBeDefined())
  const payload = submitted as { start_time: string; booking_services: Record<string, unknown>[] }
  expect(payload.start_time).toBe('19:00')
  expect(payload.booking_services[0]).toEqual({ service_id: 4, package_id: 5, duration_minutes: 180, quantity: 1, staff_ids: [10] })
  expect(availabilityPayload).toMatchObject({ event_date: '2027-06-15', start_time: '19:00' })
  expect((availabilityPayload?.booking_services as Record<string, unknown>[])[0]).not.toHaveProperty('start_time')
  expect(payload.booking_services[0]).not.toHaveProperty('unit_rate')
  expect(payload.booking_services[0]).not.toHaveProperty('line_total')
})

test('supports repeated service lines and clears dependent choices', async () => {
  renderRoute('/bookings/new')
  await screen.findByRole('heading', { name: 'New Booking' })
  await screen.findByRole('option', { name: 'Wedding' })
  fireEvent.change(screen.getByLabelText('Event type'), { target: { value: '3' } })
  fireEvent.change(screen.getByLabelText('Service'), { target: { value: '4' } })
  await screen.findByRole('option', { name: 'Premium' })
  fireEvent.change(screen.getByLabelText('Package'), { target: { value: '5' } })
  await screen.findByRole('option', { name: '3 hours' })
  fireEvent.change(screen.getByLabelText('Duration'), { target: { value: '180' } })
  fireEvent.change(screen.getByLabelText('Package'), { target: { value: '0' } })
  expect(screen.getByLabelText('Duration')).toHaveValue('0')
  fireEvent.click(screen.getByRole('button', { name: 'Add Service' }))
  expect(screen.getAllByLabelText('Service')).toHaveLength(2)
  expect(screen.getAllByLabelText('Event date')).toHaveLength(1)
  expect(screen.getAllByLabelText('Start time')).toHaveLength(1)
  expect(screen.getAllByLabelText('Duration')).toHaveLength(2)
  fireEvent.change(screen.getAllByLabelText('Service')[0], { target: { value: '0' } })
  expect(screen.getAllByLabelText('Package')[0]).toHaveValue('0')
  expect(screen.getAllByLabelText('Duration')[0]).toHaveValue('0')
  fireEvent.click(screen.getAllByRole('button', { name: 'Remove' })[1])
  expect(screen.getAllByLabelText('Service')).toHaveLength(1)
})

test('shows an empty result when customer search has no matches', async () => {
  const fetchMock = fetchApi((url) => {
    if (url.includes('/api/v1/customers?') && url.includes('search=missing')) return response(page([]))
  })
  renderRoute('/bookings/new', fetchMock)
  await screen.findByRole('option', { name: 'Ana Cruz' })

  fireEvent.change(screen.getByLabelText('Search customer'), { target: { value: 'missing' } })
  fireEvent.click(screen.getByRole('button', { name: 'Search' }))

  expect(await screen.findByText('No customers found.')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Add New Customer' })).toBeInTheDocument()
})

test('creates and selects a customer inline without resetting booking values', async () => {
  const createdCustomer = {
    ...customer,
    id: 12,
    name: 'Bea Ramos',
    email: 'bea@example.com',
    phone: '09178889999',
  }
  let bookingPayload: Record<string, unknown> | undefined
  const fetchMock = fetchApi((url, init) => {
    if (url.endsWith('/api/v1/customers') && init?.method === 'POST') return response(createdCustomer, 201)
    if (url.endsWith('/api/v1/bookings') && init?.method === 'POST') {
      bookingPayload = JSON.parse(String(init.body)) as Record<string, unknown>
      return response({ ...booking, customer: { id: 12, name: 'Bea Ramos', is_active: true } }, 201)
    }
  })
  renderRoute('/bookings/new', fetchMock)
  await screen.findByRole('option', { name: 'Ana Cruz' })

  fireEvent.change(screen.getByLabelText('Event type'), { target: { value: '3' } })
  fireEvent.change(screen.getByLabelText('Event name / occasion'), { target: { value: 'Bea Birthday' } })
  fireEvent.change(screen.getByLabelText('Event date'), { target: { value: '2027-08-20' } })
  fireEvent.change(screen.getByLabelText('Venue name'), { target: { value: 'Garden Hall' } })
  fireEvent.change(screen.getByLabelText('Venue address'), { target: { value: 'Quezon City' } })
  fireEvent.change(screen.getByLabelText('Contact person'), { target: { value: 'Lia Coordinator' } })
  fireEvent.change(screen.getByLabelText('Contact number'), { target: { value: '09990000000' } })
  fireEvent.change(screen.getByLabelText('Notes'), { target: { value: 'Keep this setup note.' } })
  fireEvent.change(screen.getByLabelText('Service'), { target: { value: '4' } })
  await screen.findByRole('option', { name: 'Premium' })
  fireEvent.change(screen.getByLabelText('Package'), { target: { value: '5' } })
  await screen.findByRole('option', { name: '3 hours' })
  fireEvent.change(screen.getByLabelText('Duration'), { target: { value: '180' } })
  fireEvent.change(screen.getByLabelText('Start time'), { target: { value: '19:30' } })
  fireEvent.change(screen.getByLabelText('Quantity'), { target: { value: '2' } })
  fireEvent.click(await screen.findByRole('checkbox', { name: 'Mia Santos' }))

  fireEvent.click(screen.getByRole('button', { name: 'Add New Customer' }))
  fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Bea Ramos' } })
  fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'bea@example.com' } })
  fireEvent.change(screen.getByLabelText('Phone'), { target: { value: '09178889999' } })
  fireEvent.click(screen.getByRole('button', { name: 'Create customer' }))

  expect(await screen.findByText('Bea Ramos')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Change' })).toBeInTheDocument()
  expect(screen.getByLabelText('Event name / occasion')).toHaveValue('Bea Birthday')
  expect(screen.getByLabelText('Event date')).toHaveValue('2027-08-20')
  expect(screen.getByLabelText('Venue name')).toHaveValue('Garden Hall')
  expect(screen.getByLabelText('Venue address')).toHaveValue('Quezon City')
  expect(screen.getByLabelText('Contact person')).toHaveValue('Lia Coordinator')
  expect(screen.getByLabelText('Contact number')).toHaveValue('09990000000')
  expect(screen.getByLabelText('Notes')).toHaveValue('Keep this setup note.')
  expect(screen.getByLabelText('Service')).toHaveValue('4')
  expect(screen.getByLabelText('Package')).toHaveValue('5')
  expect(screen.getByLabelText('Duration')).toHaveValue('180')
  expect(screen.getByLabelText('Start time')).toHaveValue('19:30')
  expect(screen.getByLabelText('Quantity')).toHaveValue(2)
  expect(screen.getByRole('checkbox', { name: 'Mia Santos' })).toBeChecked()

  fireEvent.click(screen.getByRole('button', { name: 'Create Booking' }))
  await waitFor(() => expect(bookingPayload).toBeDefined())
  expect(bookingPayload).toMatchObject({
    customer_id: 12,
    event_name: 'Bea Birthday',
    event_date: '2027-08-20',
    start_time: '19:30',
    venue_name: 'Garden Hall',
    contact_person: 'Lia Coordinator',
    contact_number: '09990000000',
    booking_services: [{ service_id: 4, package_id: 5, duration_minutes: 180, quantity: 2, staff_ids: [10] }],
  })
})

test('validates required fields and at least one service before create', async () => {
  const fetchMock = renderRoute('/bookings/new')
  await screen.findByRole('option', { name: 'Ana Cruz' })
  fireEvent.click(screen.getByRole('button', { name: 'Remove' }))
  fireEvent.click(screen.getByRole('button', { name: 'Create Booking' }))
  expect(await screen.findByText('Select a customer.')).toBeInTheDocument()
  expect(screen.getByText('Event name is required.')).toBeInTheDocument()
  expect(screen.getByText('Add at least one service.')).toBeInTheDocument()
  expect(fetchMock.mock.calls.some(([input, init]) => String(input).endsWith('/api/v1/bookings') && init?.method === 'POST')).toBe(false)
})

test('shows a capacity conflict from the availability preview', async () => {
  let previewPayload: { booking_id?: number } | undefined
  const fetchMock = fetchApi((url, init) => {
    if (url.endsWith('/api/v1/bookings/availability') && init?.method === 'POST') {
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
    if (url.endsWith('/api/v1/bookings') && init?.method === 'POST') return response({ message: 'Validation failed.', errors: { booking_services: ['The requested schedule exceeds available service capacity.'] } }, 422)
  })
  renderRoute('/bookings/new', fetchMock)
  await screen.findByRole('option', { name: 'Ana Cruz' })
  fireEvent.click(screen.getByRole('option', { name: 'Ana Cruz' }))
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
  fireEvent.click(screen.getByRole('button', { name: 'Create Booking' }))
  expect(await screen.findByText('The requested schedule exceeds available service capacity.')).toBeInTheDocument()
})

test('updates a pending booking shared time and retains service line state', async () => {
  let updatePayload: { event_name: string; start_time: string; booking_services: { id?: number; duration_minutes: number; start_time?: string }[] } | undefined
  const fetchMock = fetchApi((url, init) => {
    if (url.endsWith('/api/v1/bookings/8') && init?.method === 'PUT') {
      updatePayload = JSON.parse(String(init.body)) as typeof updatePayload
      return response({ ...booking, event_name: updatePayload?.event_name ?? booking.event_name })
    }
  })
  renderRoute('/bookings/8/edit', fetchMock)
  await screen.findByRole('option', { name: '3 hours' })
  expect(screen.getByLabelText('Event date')).toHaveValue('2027-06-15')
  expect(screen.getByLabelText('Start time')).toHaveValue('18:00')
  expect(screen.getByLabelText('Duration')).toHaveValue('180')
  fireEvent.change(screen.getByLabelText('Event name / occasion'), { target: { value: 'Updated occasion' } })
  fireEvent.change(screen.getByLabelText('Start time'), { target: { value: '20:00' } })
  expect(screen.getByLabelText('Service')).toHaveValue('4')
  expect(screen.getByLabelText('Package')).toHaveValue('5')
  expect(screen.getByLabelText('Duration')).toHaveValue('180')
  fireEvent.click(screen.getByRole('button', { name: 'Save Changes' }))
  expect(await screen.findByRole('heading', { name: 'BK-2027-000001' })).toBeInTheDocument()
  expect(updatePayload?.event_name).toBe('Updated occasion')
  expect(updatePayload?.start_time).toBe('20:00')
  expect(updatePayload?.booking_services[0].id).toBe(9)
  expect(updatePayload?.booking_services[0].duration_minutes).toBe(180)
  expect(updatePayload?.booking_services[0]).not.toHaveProperty('start_time')
})

test('maps backend booking conflicts and represents inactive current dependencies', async () => {
  const inactiveBooking = { ...booking, customer: { ...booking.customer, is_active: false }, event_type: { ...booking.event_type, is_active: false } }
  const fetchMock = fetchApi((url, init) => {
    if (url.includes('/api/v1/customers?') || url.includes('/api/v1/event-types?') || url.includes('/api/v1/services?') || url.includes('/api/v1/services/4/package-mappings?') || url.includes('/api/v1/services/4/packages/5/rates?')) return response(page([]))
    if (url.endsWith('/api/v1/bookings/8') && init?.method === 'PUT') return response({ message: 'Validation failed.', errors: { booking_services: ['The requested schedule exceeds available service capacity.'] } }, 422)
    if (url.endsWith('/api/v1/bookings/8')) return response(inactiveBooking)
  })
  renderRoute('/bookings/8/edit', fetchMock)
  expect(await screen.findByText('Ana Cruz')).toBeInTheDocument()
  expect(screen.getByText('Inactive customer')).toBeInTheDocument()
  expect(screen.getByRole('option', { name: 'Wedding (current; inactive)' })).toBeInTheDocument()
  expect(screen.getByRole('option', { name: 'Mirror Booth (current; inactive)' })).toBeInTheDocument()
  expect(await screen.findByRole('option', { name: 'Premium (current; inactive)' })).toBeInTheDocument()
  expect(screen.getByRole('option', { name: '3 hours (saved; unavailable)' })).toBeInTheDocument()
  fireEvent.click(screen.getByRole('button', { name: 'Save Changes' }))
  expect(await screen.findByText('The requested schedule exceeds available service capacity.')).toBeInTheDocument()
})

test('renders snapshots, total, and pending actions on booking detail', async () => {
  const bookingWithDifferentDurations = {
    ...booking,
    end_at: '2027-06-15 22:00',
    booking_services: [
      booking.booking_services[0],
      { ...booking.booking_services[0], id: 10, service: { id: 7, name: '360 Video Booth' }, package: { id: 8, name: 'Basic' }, end_at: '2027-06-15 20:00', duration_minutes: 120, unit_rate: '4000.00', line_total: '4000.00', sort_order: 1, staff: [] },
    ],
  }
  renderRoute('/bookings/8', fetchApi((url) => url.endsWith('/api/v1/bookings/8') ? response(bookingWithDifferentDurations) : undefined))
  expect(await screen.findByRole('heading', { name: 'BK-2027-000001' })).toBeInTheDocument()
  expect(screen.getByText('Customer snapshot')).toBeInTheDocument()
  expect(screen.getByText('All services begin at the shared event start; each duration determines its effective end.')).toBeInTheDocument()
  expect(screen.getByText('Jun 15, 2027')).toBeInTheDocument()
  expect(screen.getAllByText('6:00 PM').length).toBeGreaterThan(0)
  expect(screen.getByText('6:00 PM – 9:00 PM')).toBeInTheDocument()
  expect(screen.getByText('6:00 PM – 8:00 PM')).toBeInTheDocument()
  expect(screen.getByText('10:00 PM')).toBeInTheDocument()
  expect(screen.getByText('Mia Santos')).toBeInTheDocument()
  expect(screen.getAllByText('₱8,000.00')).toHaveLength(2)
  expect(screen.getByRole('link', { name: /Edit/ })).toHaveAttribute('href', '/bookings/8/edit')
  expect(screen.getByRole('button', { name: /Cancel booking/ })).toBeInTheDocument()
})

test('cancels a pending booking only after confirmation', async () => {
  const cancelled = { ...booking, status: 'CANCELLED', cancelled_at: '2026-09-13', cancellation_reason: 'Client request' }
  let cancelCalls = 0
  const fetchMock = fetchApi((url, init) => {
    if (url.endsWith('/api/v1/bookings/8/cancel') && init?.method === 'POST') { cancelCalls += 1; return response(cancelled) }
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
  renderRoute('/bookings', fetchApi((url) => url.includes('/api/v1/bookings?') ? pending : undefined))
  expect(await screen.findByText('Loading bookings…')).toBeInTheDocument()
  resolveBookings(response(page([])))
  expect(await screen.findByText('No bookings yet.')).toBeInTheDocument()
})

test('blocks the edit UI for a non-pending booking', async () => {
  const confirmed = { ...booking, status: 'CONFIRMED' }
  renderRoute('/bookings/8/edit', fetchApi((url) => url.endsWith('/api/v1/bookings/8') ? response(confirmed) : undefined))
  expect(await screen.findByRole('heading', { name: 'This booking is read-only' })).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Save Changes' })).not.toBeInTheDocument()
})
