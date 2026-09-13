import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, expect, test, vi } from 'vitest'

import App from '@/App'
import { AppProviders } from '@/app/providers'

const auth = { user: { id: 1, name: 'Admin', email: 'admin@example.com' }, organization: { id: 1, name: 'Tenant', status: 'active' } }
const service = { id: 10, name: '360 Booth', total_units: 3, is_active: true, created_at: '2027-01-01T00:00:00Z', updated_at: '2027-01-01T00:00:00Z' }
const packageItem = { id: 20, service: { id: 10, name: '360 Booth', is_active: true }, name: 'Premium', is_active: true, created_at: '2027-01-01T00:00:00Z', updated_at: '2027-01-01T00:00:00Z' }
const response = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
const page = (data: unknown[]) => ({ data, links: { prev: null, next: null }, meta: { current_page: 1, last_page: 1, per_page: 15, total: data.length } })

function baseFetch() {
  return vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)
    if (url.endsWith('/api/me')) return response(auth)
    if (url.endsWith('/sanctum/csrf-cookie')) return new Response(null, { status: 204 })
    if (url.includes('/api/services/10/packages?')) return response(page([packageItem]))
    if (url.endsWith('/api/services/10/packages') && init?.method === 'POST') return response({ ...packageItem, id: 21, name: 'Basic' }, 201)
    if (url.endsWith('/api/packages/20') && init?.method === 'PUT') return response({ ...packageItem, name: 'Premium Plus', is_active: false })
    if (url.endsWith('/api/services/10') && init?.method === 'PUT') return response({ ...service, name: '360 Video Booth', is_active: false })
    if (url.endsWith('/api/services') && init?.method === 'POST') return response({ ...service, id: 11, name: 'Mirror Booth' }, 201)
    if (url.includes('/api/services?')) return response(page([service]))
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
  fireEvent.change(screen.getByLabelText('Total units'), { target: { value: '0' } })
  fireEvent.click(screen.getByRole('button', { name: 'Create service' }))
  expect(await screen.findByText('Service name is required.')).toBeInTheDocument()
  expect(screen.getByText('Total units must be at least 1.')).toBeInTheDocument()
  fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Mirror Booth' } })
  fireEvent.change(screen.getByLabelText('Total units'), { target: { value: '2' } })
  fireEvent.click(screen.getByRole('button', { name: 'Create service' }))
  expect(await screen.findByText('Mirror Booth saved.')).toBeInTheDocument()
})

test('lists and creates packages in service context', async () => {
  renderPage()
  const row = (await screen.findByText('360 Booth')).closest('tr')
  fireEvent.click(within(row!).getByRole('button', { name: 'Packages' }))
  expect(await screen.findByText('Premium')).toBeInTheDocument()
  fireEvent.click(screen.getByRole('button', { name: 'New package' }))
  fireEvent.change(screen.getByLabelText('Package name'), { target: { value: 'Basic' } })
  fireEvent.click(screen.getByRole('button', { name: 'Create package' }))
  await waitFor(() => expect(screen.queryByRole('button', { name: 'Create package' })).not.toBeInTheDocument())
})

test('shows a package duplicate backend error', async () => {
  const fetchMock = baseFetch()
  fetchMock.mockImplementation(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)
    if (url.endsWith('/api/me')) return response(auth)
    if (url.endsWith('/sanctum/csrf-cookie')) return new Response(null, { status: 204 })
    if (url.includes('/api/services/10/packages?')) return response(page([packageItem]))
    if (url.endsWith('/api/services/10/packages') && init?.method === 'POST') return response({ message: 'Invalid.', errors: { name: ['A package with this name already exists for the service.'] } }, 422)
    if (url.includes('/api/services?')) return response(page([service]))
    return response({}, 404)
  })
  renderPage(fetchMock)
  const row = (await screen.findByText('360 Booth')).closest('tr')
  fireEvent.click(within(row!).getByRole('button', { name: 'Packages' }))
  await screen.findByText('Premium')
  fireEvent.click(screen.getByRole('button', { name: 'New package' }))
  fireEvent.change(screen.getByLabelText('Package name'), { target: { value: 'Premium' } })
  fireEvent.click(screen.getByRole('button', { name: 'Create package' }))
  expect(await screen.findByText('A package with this name already exists for the service.')).toBeInTheDocument()
})

test('edits service details and status', async () => {
  const fetchMock = renderPage()
  const row = (await screen.findByText('360 Booth')).closest('tr')
  fireEvent.click(within(row!).getByRole('button', { name: 'Edit' }))
  fireEvent.change(screen.getByLabelText('Name'), { target: { value: '360 Video Booth' } })
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
  expect(await screen.findByText('360 Video Booth saved.')).toBeInTheDocument()
  fireEvent.click(within(row!).getByRole('button', { name: 'Deactivate' }))
  await waitFor(() => expect(fetchMock.mock.calls.filter(([url, init]) => String(url).endsWith('/api/services/10') && init?.method === 'PUT').length).toBeGreaterThanOrEqual(2))
})

test('edits package details and status', async () => {
  renderPage()
  const row = (await screen.findByText('360 Booth')).closest('tr')
  fireEvent.click(within(row!).getByRole('button', { name: 'Packages' }))
  const packageRow = (await screen.findByText('Premium')).parentElement?.parentElement
  fireEvent.click(within(packageRow!).getByRole('button', { name: 'Edit' }))
  fireEvent.change(screen.getByLabelText('Package name'), { target: { value: 'Premium Plus' } })
  fireEvent.change(screen.getByLabelText('Package status'), { target: { value: 'inactive' } })
  fireEvent.click(screen.getByRole('button', { name: 'Save package' }))
  await waitFor(() => expect(screen.queryByRole('button', { name: 'Save package' })).not.toBeInTheDocument())
})
