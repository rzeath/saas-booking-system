import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, expect, test, vi } from 'vitest'

import App from '@/App'
import { AppProviders } from '@/app/providers'

const auth = { user: { id: 1, name: 'Admin', email: 'admin@example.com' }, organization: { id: 1, name: 'Tenant', status: 'active' } }
const service = { id: 10, name: '360 Booth', total_units: 3, is_active: true, created_at: '2027-01-01T00:00:00Z', updated_at: '2027-01-01T00:00:00Z' }
const relatedService = { id: 10, name: '360 Booth', is_active: true }
const premium = { id: 20, services: [relatedService], name: 'Premium', is_active: true, created_at: '2027-01-01T00:00:00Z', updated_at: '2027-01-01T00:00:00Z' }
const basic = { ...premium, id: 21, services: [], name: 'Basic' }
const eventType = { id: 30, name: 'Wedding', is_active: true, created_at: '2027-01-01T00:00:00Z', updated_at: '2027-01-01T00:00:00Z' }
const response = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
const page = (data: unknown[]) => ({ data, links: { prev: null, next: null }, meta: { current_page: 1, last_page: 1, per_page: 15, total: data.length } })

function baseFetch(onMappings?: (body: { package_ids: number[] }) => Response) {
  return vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)
    if (url.endsWith('/api/me')) return response(auth)
    if (url.endsWith('/sanctum/csrf-cookie')) return new Response(null, { status: 204 })
    if (url.includes('/api/v1/services/10/package-mappings?')) return response(page([premium]))
    if (url.endsWith('/api/v1/services/10/package-mappings') && init?.method === 'PUT') return onMappings?.(JSON.parse(String(init.body)) as { package_ids: number[] }) ?? response({ data: [premium, basic] })
    if (url.includes('/api/v1/services/10/packages/20/rates?')) return response(page([]))
    if (url.includes('/api/v1/event-types?')) return response(page([eventType]))
    if (url.includes('/api/v1/packages?')) return response(page([premium, basic]))
    if (url.endsWith('/api/v1/services/10') && init?.method === 'PUT') return response({ ...service, name: '360 Video Booth', is_active: false })
    if (url.endsWith('/api/v1/services') && init?.method === 'POST') return response({ ...service, id: 11, name: 'Mirror Booth' }, 201)
    if (url.includes('/api/v1/services?')) return response(page([service]))
    return response({ message: 'Not found.' }, 404)
  })
}

function renderPage(fetchMock = baseFetch()) {
  window.history.pushState({}, '', '/services')
  vi.stubGlobal('fetch', fetchMock)
  render(<AppProviders><App /></AppProviders>)
  return fetchMock
}

afterEach(() => { vi.unstubAllGlobals(); window.history.pushState({}, '', '/') })

test('renders, searches, and filters the service list', async () => {
  const fetchMock = renderPage()
  expect(await screen.findByRole('heading', { name: 'Services' })).toBeInTheDocument()
  expect(await screen.findByText('360 Booth')).toBeInTheDocument()
  fireEvent.change(screen.getByLabelText('Search services'), { target: { value: '360' } })
  fireEvent.click(screen.getByRole('button', { name: 'Search' }))
  fireEvent.change(screen.getByLabelText('Status filter'), { target: { value: 'inactive' } })
  await waitFor(() => expect(fetchMock.mock.calls.some(([url]) => String(url).includes('search=360') && String(url).includes('status=inactive'))).toBe(true))
})

test('validates and creates a service', async () => {
  renderPage()
  await screen.findByText('360 Booth')
  fireEvent.click(screen.getByRole('button', { name: 'New service' }))
  fireEvent.click(screen.getByRole('button', { name: 'Create service' }))
  expect(await screen.findByText('Service name is required.')).toBeInTheDocument()
  fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Mirror Booth' } })
  fireEvent.change(screen.getByLabelText('Total units'), { target: { value: '2' } })
  fireEvent.click(screen.getByRole('button', { name: 'Create service' }))
  expect(await screen.findByText('Mirror Booth saved.')).toBeInTheDocument()
})

test('assigns and unassigns existing packages without creating them', async () => {
  let savedIds: number[] = []
  renderPage(baseFetch((body) => { savedIds = body.package_ids; return response({ data: [basic] }) }))
  const row = (await screen.findByText('360 Booth')).closest('tr')
  fireEvent.click(within(row!).getByRole('button', { name: 'Packages & Rates' }))
  expect(await screen.findByRole('checkbox', { name: /Premium/ })).toBeChecked()
  const basicCheckbox = screen.getByRole('checkbox', { name: /Basic/ })
  fireEvent.click(screen.getByRole('checkbox', { name: /Premium/ }))
  fireEvent.click(basicCheckbox)
  fireEvent.click(screen.getByRole('button', { name: 'Save Changes' }))
  await waitFor(() => expect(savedIds).toEqual([21]))
})

test('keeps package mapping and pricing selection as separate states', async () => {
  renderPage()
  const row = (await screen.findByText('360 Booth')).closest('tr')
  fireEvent.click(within(row!).getByRole('button', { name: 'Packages & Rates' }))

  const premiumButton = await screen.findByRole('button', { name: /Premium/ })
  const basicButton = screen.getByRole('button', { name: /Basic/ })
  expect(premiumButton).toHaveAttribute('aria-pressed', 'true')
  expect(screen.getByRole('checkbox', { name: /Premium/ })).toBeChecked()
  expect(screen.getByRole('checkbox', { name: /Basic/ })).not.toBeChecked()

  fireEvent.click(basicButton)
  expect(basicButton).toHaveAttribute('aria-pressed', 'true')
  expect(screen.getByRole('checkbox', { name: /Premium/ })).toBeChecked()
  expect(screen.getByRole('checkbox', { name: /Basic/ })).not.toBeChecked()
  expect(screen.getByText('Map this Package and save your changes before configuring rates.')).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Add Rate' })).not.toBeInTheDocument()
})

test('shows a blocked unassignment error from the backend', async () => {
  renderPage(baseFetch(() => response({ message: 'Invalid.', errors: { package_ids: ['Packages used by rates or bookings cannot be unassigned from this service.'] } }, 422)))
  const row = (await screen.findByText('360 Booth')).closest('tr')
  fireEvent.click(within(row!).getByRole('button', { name: 'Packages & Rates' }))
  fireEvent.click(await screen.findByRole('checkbox', { name: /Premium/ }))
  fireEvent.click(screen.getByRole('button', { name: 'Save Changes' }))
  expect(await screen.findByText('Packages used by rates or bookings cannot be unassigned from this service.')).toBeInTheDocument()
  expect(screen.getByRole('checkbox', { name: /Premium/ })).toBeChecked()
})
