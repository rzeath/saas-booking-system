import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, expect, test, vi } from 'vitest'

import App from '@/App'
import { AppProviders } from '@/app/providers'

const auth = { user: { id: 1, name: 'Admin', email: 'admin@example.com' }, organization: { id: 1, name: 'Tenant', status: 'active' } }
const eventTypes = [
  { id: 30, name: 'Wedding', is_active: true, created_at: '2027-01-01T00:00:00Z', updated_at: '2027-01-01T00:00:00Z' },
  { id: 31, name: 'Birthday', is_active: true, created_at: '2027-01-01T00:00:00Z', updated_at: '2027-01-01T00:00:00Z' },
]
const services = [
  { id: 10, name: '360 Booth', total_units: 3, is_active: true, created_at: '2027-01-01T00:00:00Z', updated_at: '2027-01-01T00:00:00Z' },
  { id: 11, name: 'Mirror Booth', total_units: 2, is_active: true, created_at: '2027-01-01T00:00:00Z', updated_at: '2027-01-01T00:00:00Z' },
]
const relatedPackage = { id: 20, name: 'Premium', is_active: true }
const packageItem = { ...relatedPackage, services: services.map(({ id, name, is_active }) => ({ id, name, is_active })), created_at: '2027-01-01T00:00:00Z', updated_at: '2027-01-01T00:00:00Z' }

function rate(id: number, serviceIndex: number, eventTypeIndex: number, duration: number, price: string) {
  const service = services[serviceIndex]
  const eventType = eventTypes[eventTypeIndex]
  return {
    id,
    event_type: { id: eventType.id, name: eventType.name, is_active: eventType.is_active },
    service: { id: service.id, name: service.name, is_active: service.is_active },
    package: relatedPackage,
    duration_minutes: duration,
    unit_rate: price,
    is_active: true,
    is_available: true,
    created_at: '2027-01-01T00:00:00Z',
    updated_at: '2027-01-01T00:00:00Z',
  }
}

const weddingRate = rate(40, 0, 0, 180, '7500.00')
const birthdayRate = rate(41, 0, 1, 120, '6200.00')
const mirrorRate = rate(42, 1, 0, 240, '9900.00')
const response = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
const page = (data: unknown[]) => ({ data, links: { prev: null, next: null }, meta: { current_page: 1, last_page: 1, per_page: 100, total: data.length } })

function baseFetch(onWrite?: (url: string, init: RequestInit) => Response | undefined) {
  return vi.fn(async (input: RequestInfo | URL, init: RequestInit = {}) => {
    const url = String(input)
    const customResponse = onWrite?.(url, init)
    if (customResponse) return customResponse
    if (url.endsWith('/api/me')) return response(auth)
    if (url.endsWith('/sanctum/csrf-cookie')) return new Response(null, { status: 204 })
    if (url.includes('/api/v1/services/10/package-mappings?') || url.includes('/api/v1/services/11/package-mappings?')) return response(page([packageItem]))
    if (url.includes('/api/v1/services/10/packages/20/rates?') && url.includes('event_type_id=31')) return response(page([birthdayRate]))
    if (url.includes('/api/v1/services/10/packages/20/rates?')) return response(page([weddingRate]))
    if (url.includes('/api/v1/services/11/packages/20/rates?')) return response(page([mirrorRate]))
    if (url.includes('/api/v1/event-types?')) return response(page(eventTypes))
    if (url.includes('/api/v1/packages?')) return response(page([packageItem]))
    if (url.includes('/api/v1/services?')) return response(page(services))
    return response({ message: 'Not found.' }, 404)
  })
}

function renderPage(fetchMock = baseFetch(), path = '/master-data?tab=services') {
  window.history.pushState({}, '', path)
  vi.stubGlobal('fetch', fetchMock)
  render(<AppProviders><App /></AppProviders>)
  return fetchMock
}

async function openPricing(serviceName = '360 Booth') {
  const row = (await screen.findByText(serviceName)).closest('tr')
  fireEvent.click(within(row!).getByRole('button', { name: 'Packages & Rates' }))
  expect(await screen.findByRole('dialog', { name: 'Packages & Rates' })).toBeInTheDocument()
}

afterEach(() => {
  vi.unstubAllGlobals()
  window.history.pushState({}, '', '/')
})

test('loads contextual rates and switches Event Types', async () => {
  const fetchMock = renderPage()
  await openPricing()

  expect(await screen.findByText('₱7,500.00')).toBeInTheDocument()
  expect(screen.getByText('3 hours')).toBeInTheDocument()
  fireEvent.click(screen.getByRole('tab', { name: 'Birthday' }))
  expect(await screen.findByText('₱6,200.00')).toBeInTheDocument()
  expect(screen.getByText('2 hours')).toBeInTheDocument()
  expect(fetchMock.mock.calls.some(([url]) => String(url).includes('/services/10/packages/20/rates?') && String(url).includes('event_type_id=31'))).toBe(true)
})

test('adds a rate using Service, Package, and Event Type context', async () => {
  let submitted: Record<string, unknown> | undefined
  renderPage(baseFetch((url, init) => {
    if (url.endsWith('/api/v1/services/10/packages/20/rates') && init.method === 'POST') {
      submitted = JSON.parse(String(init.body)) as Record<string, unknown>
      return response(rate(43, 0, 0, 240, '9000.00'), 201)
    }
  }))
  await openPricing()
  await screen.findByText('₱7,500.00')

  fireEvent.click(screen.getByRole('button', { name: 'Add Rate' }))
  expect(screen.queryByLabelText('Service')).not.toBeInTheDocument()
  expect(screen.queryByLabelText('Package')).not.toBeInTheDocument()
  expect(screen.queryByLabelText('Event type')).not.toBeInTheDocument()
  fireEvent.change(screen.getByLabelText('Duration (minutes)'), { target: { value: '240' } })
  fireEvent.change(screen.getByLabelText('Price'), { target: { value: '9000.00' } })
  fireEvent.click(screen.getByRole('button', { name: 'Add rate' }))

  await waitFor(() => expect(submitted).toEqual({
    event_type_id: 30,
    service_id: 10,
    package_id: 20,
    duration_minutes: 240,
    unit_rate: '9000.00',
    is_active: true,
  }))
})

test('edits a contextual rate without changing its parent combination', async () => {
  let submitted: Record<string, unknown> | undefined
  renderPage(baseFetch((url, init) => {
    if (url.endsWith('/api/v1/services/10/packages/20/rates/40') && init.method === 'PUT') {
      submitted = JSON.parse(String(init.body)) as Record<string, unknown>
      return response({ ...weddingRate, unit_rate: '8000.00' })
    }
  }))
  await openPricing()
  await screen.findByText('₱7,500.00')

  const pricing = screen.getByRole('region', { name: 'Pricing configuration for Premium' })
  fireEvent.click(within(pricing).getByRole('button', { name: 'Edit' }))
  fireEvent.change(screen.getByLabelText('Price'), { target: { value: '8000.00' } })
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

  await waitFor(() => expect(submitted).toMatchObject({ event_type_id: 30, service_id: 10, package_id: 20, unit_rate: '8000.00' }))
})

test('shows different rates for the same reusable Package on different Services', async () => {
  renderPage()
  await openPricing('360 Booth')
  expect(await screen.findByText('₱7,500.00')).toBeInTheDocument()
  fireEvent.click(screen.getByRole('button', { name: 'Close dialog' }))

  await openPricing('Mirror Booth')
  expect(await screen.findByText('₱9,900.00')).toBeInTheDocument()
  expect(screen.queryByText('₱7,500.00')).not.toBeInTheDocument()
})

test('legacy Service Rates navigation resolves to contextual Services configuration', async () => {
  renderPage(baseFetch(), '/service-rates')

  expect(await screen.findByRole('heading', { name: 'Master Data' })).toBeInTheDocument()
  expect(screen.getByRole('tab', { name: 'Services' })).toHaveAttribute('aria-selected', 'true')
  expect(screen.queryByRole('tab', { name: 'Service Rates' })).not.toBeInTheDocument()
})
