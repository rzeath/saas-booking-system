import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, expect, test, vi } from 'vitest'

import App from '@/App'
import { AppProviders } from '@/app/providers'

const auth = { user: { id: 1, name: 'Admin', email: 'admin@example.com' }, organization: { id: 1, name: 'Tenant', status: 'active' } }
const packageItem = { id: 20, services: [{ id: 10, name: '360 Booth', is_active: true }, { id: 11, name: 'Mirror Booth', is_active: true }], name: 'Premium', is_active: true, created_at: '2027-01-01T00:00:00Z', updated_at: '2027-01-01T00:00:00Z' }
const response = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
const page = (data: unknown[]) => ({ data, links: { prev: null, next: null }, meta: { current_page: 1, last_page: 1, per_page: 15, total: data.length } })

function baseFetch() {
  return vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)
    if (url.endsWith('/api/me')) return response(auth)
    if (url.endsWith('/sanctum/csrf-cookie')) return new Response(null, { status: 204 })
    if (url.endsWith('/api/packages') && init?.method === 'POST') return response({ ...packageItem, id: 21, services: [], name: 'Basic' }, 201)
    if (url.endsWith('/api/packages/20') && init?.method === 'PUT') return response({ ...packageItem, name: 'Premium Plus', is_active: false })
    if (url.includes('/api/packages?')) return response(page([packageItem]))
    return response({ message: 'Not found.' }, 404)
  })
}

function renderPage(fetchMock = baseFetch()) {
  window.history.pushState({}, '', '/packages')
  vi.stubGlobal('fetch', fetchMock)
  render(<AppProviders><App /></AppProviders>)
  return fetchMock
}

afterEach(() => { vi.unstubAllGlobals(); window.history.pushState({}, '', '/') })

test('lists independent packages and their service mappings', async () => {
  renderPage()
  expect(await screen.findByRole('heading', { name: 'Packages' })).toBeInTheDocument()
  const row = (await screen.findByText('Premium')).closest('tr')
  expect(within(row!).getByText('360 Booth, Mirror Booth')).toBeInTheDocument()
})

test('creates a package without a service field', async () => {
  let submitted: Record<string, unknown> | undefined
  const fetchMock = baseFetch()
  fetchMock.mockImplementation(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)
    if (url.endsWith('/api/me')) return response(auth)
    if (url.endsWith('/sanctum/csrf-cookie')) return new Response(null, { status: 204 })
    if (url.endsWith('/api/packages') && init?.method === 'POST') { submitted = JSON.parse(String(init.body)) as Record<string, unknown>; return response({ ...packageItem, id: 21, services: [], name: 'Basic' }, 201) }
    if (url.includes('/api/packages?')) return response(page([packageItem]))
    return response({}, 404)
  })
  renderPage(fetchMock)
  await screen.findByText('Premium')
  fireEvent.click(screen.getByRole('button', { name: 'New package' }))
  expect(screen.queryByLabelText('Service')).not.toBeInTheDocument()
  fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Basic' } })
  fireEvent.click(screen.getByRole('button', { name: 'Create package' }))
  await waitFor(() => expect(submitted).toEqual({ name: 'Basic', is_active: true }))
})

test('edits and deactivates a package', async () => {
  const fetchMock = renderPage()
  const row = (await screen.findByText('Premium')).closest('tr')
  fireEvent.click(within(row!).getByRole('button', { name: 'Edit' }))
  fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Premium Plus' } })
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))
  expect(await screen.findByText('Premium Plus saved.')).toBeInTheDocument()
  fireEvent.click(within(row!).getByRole('button', { name: 'Deactivate' }))
  await waitFor(() => expect(fetchMock.mock.calls.filter(([url, init]) => String(url).endsWith('/api/packages/20') && init?.method === 'PUT').length).toBeGreaterThanOrEqual(2))
})
