import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, expect, test, vi } from 'vitest'

import App from '@/App'
import { AppProviders } from '@/app/providers'

const auth = { user: { id: 1, name: 'Admin', email: 'admin@example.com' }, organization: { id: 1, name: 'Tenant', status: 'active' } }
const eventType = { id: 30, name: 'Wedding', is_active: true, created_at: '2027-01-01T00:00:00Z', updated_at: '2027-01-01T00:00:00Z' }
const services = [
  { id: 10, name: '360 Booth', total_units: 3, is_active: true, created_at: '2027-01-01T00:00:00Z', updated_at: '2027-01-01T00:00:00Z' },
  { id: 11, name: 'Mirror Booth', total_units: 2, is_active: true, created_at: '2027-01-01T00:00:00Z', updated_at: '2027-01-01T00:00:00Z' },
]
const relatedService = { id: 10, name: '360 Booth', is_active: true }
const packageItem = { id: 20, service: relatedService, name: 'Premium', is_active: true, created_at: '2027-01-01T00:00:00Z', updated_at: '2027-01-01T00:00:00Z' }
const rate = { id: 40, event_type: { id: 30, name: 'Wedding', is_active: true }, service: relatedService, package: { id: 20, name: 'Premium', is_active: true }, duration_minutes: 180, unit_rate: '7500.00', is_active: true, is_available: true, created_at: '2027-01-01T00:00:00Z', updated_at: '2027-01-01T00:00:00Z' }
const response = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
const page = (data: unknown[]) => ({ data, links: { prev: null, next: null }, meta: { current_page: 1, last_page: 1, per_page: 15, total: data.length } })

function baseFetch(ratePost?: () => Response) {
  return vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)
    if (url.endsWith('/api/me')) return response(auth)
    if (url.endsWith('/sanctum/csrf-cookie')) return new Response(null, { status: 204 })
    if (url.includes('/api/event-types?')) return response(page([eventType]))
    if (url.includes('/api/services/10/packages?')) return response(page([packageItem]))
    if (url.includes('/api/services/11/packages?')) return response(page([]))
    if (url.includes('/api/services?')) return response(page(services))
    if (url.endsWith('/api/service-rates') && init?.method === 'POST') return ratePost?.() ?? response(rate, 201)
    if (url.endsWith('/api/service-rates/40') && init?.method === 'PUT') return response({ ...rate, unit_rate: '8000.00', is_active: false, is_available: false })
    if (url.includes('/api/service-rates?')) return response(page([rate]))
    return response({ message: 'Not found.' }, 404)
  })
}

function renderPage(fetchMock = baseFetch()) {
  window.history.pushState({}, '', '/service-rates')
  vi.stubGlobal('fetch', fetchMock)
  render(<AppProviders><App /></AppProviders>)
  return fetchMock
}

afterEach(() => { vi.unstubAllGlobals(); window.history.pushState({}, '', '/') })

test('renders and filters service rates', async () => {
  const fetchMock = renderPage()
  expect(await screen.findByRole('heading', { name: 'Service Rates' })).toBeInTheDocument()
  expect(await screen.findByText('7500.00')).toBeInTheDocument()
  fireEvent.change(screen.getByLabelText('Service filter'), { target: { value: '10' } })
  fireEvent.change(screen.getByLabelText('Status filter'), { target: { value: 'inactive' } })
  await waitFor(() => expect(fetchMock.mock.calls.some(([url]) => String(url).includes('service_id=10') && String(url).includes('status=inactive'))).toBe(true))
})

test('validates the rate form and clears a stale package when service changes', async () => {
  renderPage()
  await screen.findByText('7500.00')
  fireEvent.click(screen.getByRole('button', { name: 'New service rate' }))
  fireEvent.click(screen.getByRole('button', { name: 'Create service rate' }))
  expect(await screen.findByText('Select an event type.')).toBeInTheDocument()
  expect(screen.getByText('Select a service.')).toBeInTheDocument()
  await waitFor(() => expect(within(screen.getByLabelText('Service')).getByRole('option', { name: '360 Booth' })).toBeInTheDocument())
  fireEvent.change(screen.getByLabelText('Service'), { target: { value: '10' } })
  await waitFor(() => expect(screen.getByLabelText('Package')).not.toBeDisabled())
  fireEvent.change(screen.getByLabelText('Package'), { target: { value: '20' } })
  expect(screen.getByLabelText('Package')).toHaveValue('20')
  fireEvent.change(screen.getByLabelText('Service'), { target: { value: '11' } })
  expect(screen.getByLabelText('Package')).toHaveValue('0')
})

test('creates a service rate', async () => {
  renderPage()
  await screen.findByText('7500.00')
  fireEvent.click(screen.getByRole('button', { name: 'New service rate' }))
  await waitFor(() => expect(within(screen.getByLabelText('Service')).getByRole('option', { name: '360 Booth' })).toBeInTheDocument())
  fireEvent.change(screen.getByLabelText('Event type'), { target: { value: '30' } })
  fireEvent.change(screen.getByLabelText('Service'), { target: { value: '10' } })
  await waitFor(() => expect(screen.getByLabelText('Package')).not.toBeDisabled())
  fireEvent.change(screen.getByLabelText('Package'), { target: { value: '20' } })
  fireEvent.change(screen.getByLabelText('Price'), { target: { value: '7500.00' } })
  fireEvent.click(screen.getByRole('button', { name: 'Create service rate' }))
  expect(await screen.findByText('360 Booth · Premium rate saved.')).toBeInTheDocument()
})

test('shows duplicate combination backend validation', async () => {
  renderPage(baseFetch(() => response({ message: 'Invalid.', errors: { duration_minutes: ['A rate already exists for this event type, package, and duration.'] } }, 422)))
  await screen.findByText('7500.00')
  fireEvent.click(screen.getByRole('button', { name: 'New service rate' }))
  await waitFor(() => expect(within(screen.getByLabelText('Service')).getByRole('option', { name: '360 Booth' })).toBeInTheDocument())
  fireEvent.change(screen.getByLabelText('Event type'), { target: { value: '30' } })
  fireEvent.change(screen.getByLabelText('Service'), { target: { value: '10' } })
  await waitFor(() => expect(screen.getByLabelText('Package')).not.toBeDisabled())
  fireEvent.change(screen.getByLabelText('Package'), { target: { value: '20' } })
  fireEvent.change(screen.getByLabelText('Price'), { target: { value: '7500.00' } })
  fireEvent.click(screen.getByRole('button', { name: 'Create service rate' }))
  expect(await screen.findByText('A rate already exists for this event type, package, and duration.')).toBeInTheDocument()
})

test('edits and deactivates a service rate', async () => {
  const fetchMock = renderPage()
  await screen.findByText('7500.00')
  fireEvent.click(screen.getByRole('button', { name: 'Edit' }))
  fireEvent.change(screen.getByLabelText('Price'), { target: { value: '8000.00' } })
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
  expect(await screen.findByText('360 Booth · Premium rate saved.')).toBeInTheDocument()
  fireEvent.click(screen.getByRole('button', { name: 'Deactivate' }))
  await waitFor(() => expect(fetchMock.mock.calls.filter(([url, init]) => String(url).endsWith('/api/service-rates/40') && init?.method === 'PUT').length).toBeGreaterThanOrEqual(2))
})
