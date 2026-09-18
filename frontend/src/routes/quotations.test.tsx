import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, expect, test, vi } from 'vitest'

import App from '@/App'
import { AppProviders } from '@/app/providers'

const auth = { user: { id: 7, name: 'Admin', email: 'admin@example.com' }, organization: { id: 1, name: 'Studio', status: 'active' } }
const settings = { display_name: 'Studio', email: null, phone: null, address: null, logo_path: null, logo_url: null, theme_accent: 'plum', booking_prefix: 'BK', quotation_prefix: 'QT', billing_prefix: 'INV' }

const draftSummary = {
  id: 21,
  quotation_number: 'QT-2027-000003',
  status: 'DRAFT',
  booking: { id: 8, booking_number: 'BK-2027-000001', status: 'PENDING' },
  customer_name: 'Ana Cruz',
  total: '8125.25',
  valid_until: '2027-06-30',
  sent_at: null,
  accepted_at: null,
  closed_at: null,
  created_at: '2027-06-01T02:00:00.000000Z',
}

const terminalSummaries = [
  { ...draftSummary, id: 20, quotation_number: 'QT-2027-000002', status: 'OUTDATED', closed_at: '2027-06-02T02:00:00.000000Z', created_at: '2027-05-30T02:00:00.000000Z', booking: undefined },
  { ...draftSummary, id: 19, quotation_number: 'QT-2027-000001', status: 'REJECTED', closed_at: '2027-05-29T02:00:00.000000Z', created_at: '2027-05-28T02:00:00.000000Z', booking: undefined },
]

const booking = {
  id: 8,
  booking_number: 'BK-2027-000001',
  status: 'PENDING',
  customer: { id: 2, name: 'Ana Cruz', is_active: true },
  customer_snapshot: { name: 'Ana Cruz', email: 'ana@example.com', phone: '09171234567', address: 'Makati' },
  event_type: { id: 3, name: 'Wedding', is_active: true },
  event_type_snapshot: { name: 'Wedding' },
  event_name: 'Ana & Leo',
  start_at: '2027-06-15 18:00',
  event_date: '2027-06-15',
  start_time: '18:00',
  end_at: '2027-06-15 21:00',
  venue_name: 'The Glass House',
  venue_address: 'Makati',
  contact_person: 'Ana Cruz',
  contact_number: '09171234567',
  internal_notes: 'Load in early.',
  booking_services: [{ id: 9, service: { id: 4, name: 'Mirror Booth' }, package: { id: 5, name: 'Premium' }, start_at: '2027-06-15 18:00', end_at: '2027-06-15 21:00', duration_minutes: 180, quantity: 1, unit_rate: '8000.00', line_total: '8000.00', sort_order: 0, staff: [] }],
  quotations: terminalSummaries,
  cancelled_at: null,
  cancellation_reason: null,
  created_at: '2027-05-01T02:00:00.000000Z',
  updated_at: '2027-05-01T02:00:00.000000Z',
}

const quotation = {
  id: 21,
  quotation_number: 'QT-2027-000003',
  status: 'DRAFT',
  booking: { id: 8, booking_number: 'BK-2027-000001', status: 'PENDING' },
  valid_until: '2027-06-30',
  sent_at: null,
  accepted_at: null,
  closed_at: null,
  seller_snapshot: { display_name: 'Studio', email: 'studio@example.com', phone: '09170000000', address: 'Quezon City', logo_path: null },
  customer_snapshot: { name: 'Ana Cruz', email: 'ana@example.com', phone: '09171234567', address: 'Makati' },
  event_snapshot: { event_type_name: 'Wedding', event_name: 'Ana & Leo', event_date: '2027-06-15', venue_name: 'The Glass House', venue_address: 'Makati', contact_person: 'Ana Cruz', contact_number: '09171234567' },
  subtotal: '8000.00',
  transportation_fee: '250.25',
  crew_meal_fee: '125.00',
  discount_amount: '250.00',
  total: '8125.25',
  items: [{ id: 31, service_name: 'Mirror Booth', package_name: 'Premium', start_at: '2027-06-15 18:00', end_at: '2027-06-15 21:00', duration_minutes: 180, quantity: 1, unit_rate: '8000.00', line_total: '8000.00', sort_order: 0 }],
  created_at: '2027-06-01T02:00:00.000000Z',
  updated_at: '2027-06-01T02:00:00.000000Z',
}

function response(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

function page(data: unknown[], currentPage = 1, lastPage = 1, total = data.length) {
  return { data, links: { prev: currentPage > 1 ? 'prev' : null, next: currentPage < lastPage ? 'next' : null }, meta: { current_page: currentPage, last_page: lastPage, per_page: 15, total } }
}

function fetchApi(overrides?: (url: string, init?: RequestInit) => Response | Promise<Response> | undefined) {
  return vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)
    const overridden = overrides?.(url, init)
    if (overridden) return overridden
    if (url.endsWith('/api/me')) return response(auth)
    if (url.endsWith('/sanctum/csrf-cookie')) return new Response(null, { status: 204 })
    if (url.endsWith('/api/v1/business-settings')) return response(settings)
    if (url.endsWith('/api/v1/bookings/8')) return response(booking)
    if (url.endsWith('/api/v1/quotations/21')) return response(quotation)
    if (url.includes('/api/v1/quotations?')) return response(page([draftSummary]))
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

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  window.history.pushState({}, '', '/')
})

test('renders the quotation list with exact peso money and related Booking', async () => {
  renderRoute('/quotations')
  const row = (await screen.findByText('QT-2027-000003')).closest('tr')

  expect(within(row!).getByText('Ana Cruz')).toBeInTheDocument()
  expect(within(row!).getByText('₱8,125.25')).toBeInTheDocument()
  expect(within(row!).getByText('Draft')).toBeInTheDocument()
  expect(within(row!).getByRole('link', { name: 'BK-2027-000001' })).toHaveAttribute('href', '/bookings/8')
  expect(within(row!).getByRole('link', { name: /View/ })).toHaveAttribute('href', '/quotations/21')
})

test('integrates list pagination, status filter, and search', async () => {
  const fetchMock = fetchApi((url) => {
    if (url.includes('/api/v1/quotations?')) return response(page([draftSummary], url.includes('page=2') ? 2 : 1, 2, 2))
  })
  renderRoute('/quotations', fetchMock)
  await screen.findByText('QT-2027-000003')

  fireEvent.change(screen.getByLabelText('Status'), { target: { value: 'OUTDATED' } })
  fireEvent.change(screen.getByLabelText('Search quotations'), { target: { value: 'Ana' } })
  fireEvent.click(screen.getByRole('button', { name: 'Search' }))
  await waitFor(() => expect(fetchMock.mock.calls.some(([input]) => {
    const url = String(input)
    return url.includes('/api/v1/quotations?') && url.includes('status=OUTDATED') && url.includes('search=Ana')
  })).toBe(true))

  fireEvent.click(await screen.findByRole('button', { name: 'Next' }))
  await waitFor(() => expect(fetchMock.mock.calls.some(([input]) => {
    const url = String(input)
    return url.includes('/api/v1/quotations?') && url.includes('page=2')
  })).toBe(true))
})

test('shows historical revisions and Create Quotation on eligible Booking detail', async () => {
  renderRoute('/bookings/8')
  await screen.findByRole('heading', { name: 'BK-2027-000001' })

  expect(screen.getByRole('heading', { name: 'Quotations' })).toBeInTheDocument()
  expect(screen.getByText('QT-2027-000002')).toBeInTheDocument()
  expect(screen.getByText('QT-2027-000001')).toBeInTheDocument()
  expect(screen.getByText('Outdated')).toBeInTheDocument()
  expect(screen.getByText('Rejected')).toBeInTheDocument()
  expect(screen.getByRole('link', { name: /Create Quotation/ })).toHaveAttribute('href', '/bookings/8/quotations/new')
})

test('hides Create Quotation while an active or accepted quotation blocks creation', async () => {
  const blockedBooking = { ...booking, status: 'QUOTED', quotations: [{ ...draftSummary, status: 'ACCEPTED', accepted_at: '2027-06-03T02:00:00.000000Z', booking: undefined }] }
  renderRoute('/bookings/8', fetchApi((url) => url.endsWith('/api/v1/bookings/8') ? response(blockedBooking) : undefined))

  await screen.findByText('QT-2027-000003')
  expect(screen.queryByRole('link', { name: /Create Quotation/ })).not.toBeInTheDocument()
  expect(screen.getAllByText('Quoted')).toHaveLength(2)
  expect(screen.getAllByText('Accepted')).toHaveLength(1)
  expect(screen.getAllByRole('link', { name: 'View Quotation' })).toHaveLength(1)
  expect(screen.getByRole('link', { name: 'View Quotation' })).toHaveAttribute('href', '/quotations/21')
  expect(screen.queryByRole('link', { name: 'Edit Booking' })).not.toBeInTheDocument()
  expect(screen.queryByLabelText('More booking actions')).not.toBeInTheDocument()
})

test('creates a Draft from read-only Booking data and navigates to its detail', async () => {
  let payload: Record<string, unknown> | undefined
  const fetchMock = fetchApi((url, init) => {
    if (url.endsWith('/api/v1/bookings/8/quotations') && init?.method === 'POST') {
      payload = JSON.parse(String(init.body)) as Record<string, unknown>
      return response(quotation, 201)
    }
  })
  renderRoute('/bookings/8/quotations/new', fetchMock)

  await screen.findByRole('heading', { name: 'Create Quotation' })
  expect(screen.getByText('Mirror Booth')).toBeInTheDocument()
  expect(screen.getByText('Jun 15, 2027, 6:00 PM')).toBeInTheDocument()
  expect(screen.getByText('to Jun 15, 2027, 9:00 PM')).toBeInTheDocument()
  expect(screen.getAllByText('₱8,000.00').length).toBeGreaterThan(0)
  fireEvent.change(screen.getByLabelText('Transportation fee'), { target: { value: '250.25' } })
  fireEvent.change(screen.getByLabelText('Crew meal fee'), { target: { value: '125.00' } })
  fireEvent.change(screen.getByLabelText('Discount'), { target: { value: '250.00' } })
  fireEvent.change(screen.getByLabelText('Valid until'), { target: { value: '2027-06-30' } })
  fireEvent.click(screen.getByRole('button', { name: 'Create Quotation' }))

  expect(await screen.findByRole('heading', { name: 'QT-2027-000003' }, { timeout: 5000 })).toBeInTheDocument()
  expect(window.location.pathname).toBe('/quotations/21')
  expect(payload).toEqual({ transportation_fee: '250.25', crew_meal_fee: '125.00', discount_amount: '250.00', valid_until: '2027-06-30' })
  expect(payload).not.toHaveProperty('items')
  expect(payload).not.toHaveProperty('total')
  expect(payload).not.toHaveProperty('status')
})

test('surfaces backend Draft creation conflicts', async () => {
  renderRoute('/bookings/8/quotations/new', fetchApi((url, init) => {
    if (url.endsWith('/api/v1/bookings/8/quotations') && init?.method === 'POST') return response({ message: 'Validation failed.', errors: { quotation: ['This Booking already has an active quotation.'] } }, 422)
  }))

  await screen.findByRole('heading', { name: 'Create Quotation' })
  fireEvent.click(screen.getByRole('button', { name: 'Create Quotation' }))
  expect(await screen.findByRole('alert')).toHaveTextContent('This Booking already has an active quotation.')
})

test('renders quotation snapshots, items, totals, and lifecycle detail', async () => {
  renderRoute('/quotations/21')
  await screen.findByRole('heading', { name: 'QT-2027-000003' })

  expect(screen.getByRole('link', { name: 'BK-2027-000001' })).toHaveAttribute('href', '/bookings/8')
  expect(screen.getByText('Quotation snapshot')).toBeInTheDocument()
  expect(screen.getByText('The Glass House')).toBeInTheDocument()
  expect(screen.getByText('Mirror Booth')).toBeInTheDocument()
  expect(screen.getByText('Jun 15, 2027, 6:00 PM')).toBeInTheDocument()
  expect(screen.getByText('to Jun 15, 2027, 9:00 PM')).toBeInTheDocument()
  expect(screen.getAllByText('₱8,000.00').length).toBeGreaterThan(0)
  expect(screen.getByText('₱8,125.25')).toBeInTheDocument()
})

test('downloads the customer-facing PDF from quotation detail', async () => {
  const createObjectUrl = vi.fn(() => 'blob:quotation-pdf')
  const revokeObjectUrl = vi.fn()
  const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined)
  class MockUrl extends URL {
    static createObjectURL = createObjectUrl
    static revokeObjectURL = revokeObjectUrl
  }
  vi.stubGlobal('URL', MockUrl)
  const fetchMock = fetchApi((url) => {
    if (url.endsWith('/api/v1/quotations/21/pdf')) {
      return new Response(new Blob(['%PDF-1.7'], { type: 'application/pdf' }), {
        headers: { 'Content-Type': 'application/pdf' },
      })
    }
  })
  renderRoute('/quotations/21', fetchMock)

  fireEvent.click(await screen.findByRole('button', { name: 'Download PDF' }))

  await waitFor(() => expect(createObjectUrl).toHaveBeenCalledTimes(1))
  expect(fetchMock).toHaveBeenCalledWith('/api/v1/quotations/21/pdf', expect.objectContaining({
    headers: expect.objectContaining({ Accept: 'application/pdf' }),
  }))
  expect(click).toHaveBeenCalledTimes(1)
  expect(click.mock.instances[0]).toHaveAttribute('download', 'QT-2027-000003.pdf')
  expect(revokeObjectUrl).toHaveBeenCalledWith('blob:quotation-pdf')
})

test('surfaces quotation PDF download failures', async () => {
  renderRoute('/quotations/21', fetchApi((url) => {
    if (url.endsWith('/api/v1/quotations/21/pdf')) {
      return response({ message: 'The quotation PDF could not be generated.' }, 500)
    }
  }))

  fireEvent.click(await screen.findByRole('button', { name: 'Download PDF' }))

  expect(await screen.findByRole('alert')).toHaveTextContent('The quotation PDF could not be generated.')
})

test('edits only Draft adjustments and refreshes authoritative totals', async () => {
  let payload: Record<string, unknown> | undefined
  const updated = { ...quotation, transportation_fee: '500.00', crew_meal_fee: '100.00', discount_amount: '50.00', valid_until: null, total: '8550.00' }
  renderRoute('/quotations/21', fetchApi((url, init) => {
    if (url.endsWith('/api/v1/quotations/21') && init?.method === 'PUT') {
      payload = JSON.parse(String(init.body)) as Record<string, unknown>
      return response(updated)
    }
  }))

  fireEvent.click(await screen.findByRole('button', { name: /Edit adjustments/ }))
  fireEvent.change(screen.getByLabelText('Transportation fee'), { target: { value: '500.00' } })
  fireEvent.change(screen.getByLabelText('Crew meal fee'), { target: { value: '100.00' } })
  fireEvent.change(screen.getByLabelText('Discount'), { target: { value: '50.00' } })
  fireEvent.change(screen.getByLabelText('Valid until'), { target: { value: '' } })
  fireEvent.click(screen.getByRole('button', { name: 'Save adjustments' }))

  expect(await screen.findByText('Draft adjustments saved.')).toBeInTheDocument()
  expect(screen.getByText('₱8,550.00')).toBeInTheDocument()
  expect(payload).toEqual({ transportation_fee: '500.00', crew_meal_fee: '100.00', discount_amount: '50.00', valid_until: null })
})

test('maps backend adjustment validation to its field', async () => {
  renderRoute('/quotations/21', fetchApi((url, init) => {
    if (url.endsWith('/api/v1/quotations/21') && init?.method === 'PUT') return response({ message: 'Validation failed.', errors: { discount_amount: ['The quotation total must be greater than zero.'] } }, 422)
  }))

  fireEvent.click(await screen.findByRole('button', { name: /Edit adjustments/ }))
  fireEvent.click(screen.getByRole('button', { name: 'Save adjustments' }))
  expect(await screen.findByText('The quotation total must be greater than zero.')).toBeInTheDocument()
})

test.each([
  ['send', 'DRAFT', 'Send', 'Confirm Send', 'SENT'],
  ['accept', 'SENT', 'Accept', 'Confirm Acceptance', 'ACCEPTED'],
  ['reject', 'SENT', 'Reject', 'Confirm Rejection', 'REJECTED'],
] as const)('%s lifecycle action uses confirmation and explicit endpoint', async (transition, initialStatus, buttonName, confirmName, finalStatus) => {
  const initial = { ...quotation, status: initialStatus, booking: { ...quotation.booking, status: initialStatus === 'DRAFT' ? 'PENDING' : 'QUOTED' }, sent_at: initialStatus === 'SENT' ? '2027-06-02T02:00:00.000000Z' : null }
  const result = { ...initial, status: finalStatus, booking: { ...initial.booking, status: finalStatus === 'SENT' || finalStatus === 'ACCEPTED' ? 'QUOTED' : 'PENDING' } }
  let calls = 0
  renderRoute('/quotations/21', fetchApi((url, init) => {
    if (url.endsWith(`/api/v1/quotations/21/${transition}`) && init?.method === 'POST') { calls += 1; return response(result) }
    if (url.endsWith('/api/v1/quotations/21')) return response(initial)
  }))

  fireEvent.click(await screen.findByRole('button', { name: buttonName }))
  expect(calls).toBe(0)
  fireEvent.click(screen.getByRole('button', { name: confirmName }))
  await waitFor(() => expect(calls).toBe(1))
  expect((await screen.findAllByText(finalStatus.charAt(0) + finalStatus.slice(1).toLowerCase())).length).toBeGreaterThan(0)
})

test.each(['DRAFT', 'SENT'] as const)('cancels a %s quotation after confirmation', async (initialStatus) => {
  const initial = { ...quotation, status: initialStatus, booking: { ...quotation.booking, status: initialStatus === 'DRAFT' ? 'PENDING' : 'QUOTED' }, sent_at: initialStatus === 'SENT' ? '2027-06-02T02:00:00.000000Z' : null }
  const cancelled = { ...initial, status: 'CANCELLED', booking: { ...initial.booking, status: 'PENDING' }, closed_at: '2027-06-03T02:00:00.000000Z' }
  renderRoute('/quotations/21', fetchApi((url, init) => {
    if (url.endsWith('/api/v1/quotations/21/cancel') && init?.method === 'POST') return response(cancelled)
    if (url.endsWith('/api/v1/quotations/21')) return response(initial)
  }))

  fireEvent.click(await screen.findByRole('button', { name: 'Cancel Quotation' }))
  fireEvent.click(screen.getByRole('button', { name: 'Confirm Cancellation' }))
  expect(await screen.findByText('Cancelled')).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Cancel Quotation' })).not.toBeInTheDocument()
})

test.each(['REJECTED', 'CANCELLED', 'EXPIRED'] as const)('%s quotation is read-only', async (status) => {
  renderRoute('/quotations/21', fetchApi((url) => url.endsWith('/api/v1/quotations/21') ? response({ ...quotation, status, closed_at: '2027-06-03T02:00:00.000000Z' }) : undefined))

  await screen.findByText(status.charAt(0) + status.slice(1).toLowerCase())
  expect(screen.getByText('Read-only')).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: /Edit adjustments/ })).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Send' })).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Accept' })).not.toBeInTheDocument()
})

test('OUTDATED explains the stale snapshot and offers no rebuild controls', async () => {
  renderRoute('/quotations/21', fetchApi((url) => url.endsWith('/api/v1/quotations/21') ? response({ ...quotation, status: 'OUTDATED', closed_at: '2027-06-03T02:00:00.000000Z' }) : undefined))

  expect(await screen.findByText(/This quotation no longer reflects the current Booking details/)).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: /rebuild|restore/i })).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: /Edit adjustments/ })).not.toBeInTheDocument()
})

test('ACCEPTED is read-only and keeps its related Booking visibly Quoted', async () => {
  const accepted = { ...quotation, status: 'ACCEPTED', accepted_at: '2027-06-03T02:00:00.000000Z', closed_at: '2027-06-03T02:00:00.000000Z', booking: { ...quotation.booking, status: 'QUOTED' } }
  renderRoute('/quotations/21', fetchApi((url) => url.endsWith('/api/v1/quotations/21') ? response(accepted) : undefined))

  await screen.findByText(/The related Booking remains Quoted/)
  expect(screen.getAllByText('Quoted').length).toBeGreaterThan(0)
  expect(screen.queryByText('Confirmed')).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Accept' })).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Cancel Quotation' })).not.toBeInTheDocument()
})

test('surfaces backend lifecycle validation messages', async () => {
  renderRoute('/quotations/21', fetchApi((url, init) => {
    if (url.endsWith('/api/v1/quotations/21/send') && init?.method === 'POST') return response({ message: 'Validation failed.', errors: { valid_until: ['The valid-until date cannot be earlier than today in Asia/Manila.'] } }, 422)
  }))

  fireEvent.click(await screen.findByRole('button', { name: 'Send' }))
  fireEvent.click(screen.getByRole('button', { name: 'Confirm Send' }))
  expect(await screen.findByRole('alert')).toHaveTextContent('The valid-until date cannot be earlier than today in Asia/Manila.')
})
