import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, expect, test, vi } from 'vitest'

import App from '@/App'
import { AppProviders } from '@/app/providers'

const authContext = {
  user: { id: 7, name: 'Erica Admin', email: 'erica@example.com' },
  organization: { id: 12, name: 'Canonical Tenant', status: 'active' },
}

const customer = {
  id: 21,
  name: 'Maria Santos',
  email: 'maria@example.com',
  phone: '09171234567',
  address: 'Makati City',
  notes: 'Afternoon calls.',
  is_active: true,
  created_at: '2027-01-02T03:04:05.000000Z',
  updated_at: '2027-01-02T03:04:05.000000Z',
}

function jsonResponse(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

function page(data = [customer]) {
  return {
    data,
    links: { prev: null, next: null },
    meta: { current_page: 1, last_page: 1, per_page: 15, total: data.length },
  }
}

function renderCustomers(fetchMock: ReturnType<typeof vi.fn>) {
  window.history.pushState({}, '', '/customers')
  vi.stubGlobal('fetch', fetchMock)
  render(<AppProviders><App /></AppProviders>)
}

function baseFetch() {
  return vi.fn(async (input: RequestInfo | URL, _init?: RequestInit) => {
    void _init
    const url = String(input)
    if (url.endsWith('/api/me')) return jsonResponse(authContext)
    if (url.includes('/api/customers?')) return jsonResponse(page())
    if (url.endsWith('/sanctum/csrf-cookie')) return new Response(null, { status: 204 })
    return jsonResponse({ message: 'Not found.' }, 404)
  })
}

afterEach(() => {
  vi.unstubAllGlobals()
  window.history.pushState({}, '', '/')
})

test('renders the customer list', async () => {
  renderCustomers(baseFetch())

  expect(await screen.findByRole('heading', { name: 'Customers' })).toBeInTheDocument()
  expect(await screen.findByText('Maria Santos')).toBeInTheDocument()
  expect(screen.getByText('maria@example.com')).toBeInTheDocument()
  expect(screen.getByText('09171234567')).toBeInTheDocument()
})

test('sends customer search and status filters through the shared query contract', async () => {
  const fetchMock = baseFetch()
  renderCustomers(fetchMock)
  await screen.findByText('Maria Santos')

  fireEvent.change(screen.getByLabelText('Search customers'), { target: { value: ' maria ' } })
  fireEvent.click(screen.getByRole('button', { name: 'Search' }))

  await waitFor(() => expect(fetchMock.mock.calls.some(([input]) => {
    const url = String(input)
    return url.includes('/api/customers?') && url.includes('search=maria')
  })).toBe(true))

  fireEvent.change(screen.getByLabelText('Status filter'), { target: { value: 'inactive' } })
  await waitFor(() => expect(fetchMock.mock.calls.some(([input]) => String(input).includes('status=inactive'))).toBe(true))
})

test('validates customer creation before submitting', async () => {
  const fetchMock = baseFetch()
  renderCustomers(fetchMock)
  await screen.findByText('Maria Santos')

  fireEvent.click(screen.getByRole('button', { name: 'New customer' }))
  fireEvent.click(screen.getByRole('button', { name: 'Create customer' }))

  expect(await screen.findByText('Customer name is required.')).toBeInTheDocument()
  expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'POST')).toBe(false)
})

test('creates a customer and refreshes the list', async () => {
  const created = { ...customer, id: 22, name: 'Juan Dela Cruz', email: null, phone: null }
  const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)
    if (url.endsWith('/api/me')) return jsonResponse(authContext)
    if (url.endsWith('/sanctum/csrf-cookie')) return new Response(null, { status: 204 })
    if (url.endsWith('/api/customers') && init?.method === 'POST') return jsonResponse(created, 201)
    if (url.includes('/api/customers?')) return jsonResponse(page())
    return jsonResponse({ message: 'Not found.' }, 404)
  })
  renderCustomers(fetchMock)
  await screen.findByText('Maria Santos')

  fireEvent.click(screen.getByRole('button', { name: 'New customer' }))
  fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Juan Dela Cruz' } })
  fireEvent.click(screen.getByRole('button', { name: 'Create customer' }))

  expect(await screen.findByText('Juan Dela Cruz saved.')).toBeInTheDocument()
  const createCall = fetchMock.mock.calls.find(([, init]) => init?.method === 'POST')
  expect(JSON.parse(String(createCall?.[1]?.body))).toMatchObject({
    name: 'Juan Dela Cruz',
    is_active: true,
  })
})

test('edits an existing customer', async () => {
  const updated = { ...customer, name: 'Maria Updated' }
  const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)
    if (url.endsWith('/api/me')) return jsonResponse(authContext)
    if (url.endsWith('/sanctum/csrf-cookie')) return new Response(null, { status: 204 })
    if (url.endsWith('/api/customers/21') && init?.method === 'PUT') return jsonResponse(updated)
    if (url.includes('/api/customers?')) return jsonResponse(page())
    return jsonResponse({ message: 'Not found.' }, 404)
  })
  renderCustomers(fetchMock)
  const row = (await screen.findByText('Maria Santos')).closest('tr')

  fireEvent.click(within(row!).getByRole('button', { name: 'Edit' }))
  fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Maria Updated' } })
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

  expect(await screen.findByText('Maria Updated saved.')).toBeInTheDocument()
  expect(fetchMock.mock.calls.some(([input, init]) => String(input).endsWith('/api/customers/21') && init?.method === 'PUT')).toBe(true)
})

test('shows backend customer validation errors on the matching field', async () => {
  const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)
    if (url.endsWith('/api/me')) return jsonResponse(authContext)
    if (url.endsWith('/sanctum/csrf-cookie')) return new Response(null, { status: 204 })
    if (url.endsWith('/api/customers') && init?.method === 'POST') {
      return jsonResponse({ message: 'Validation failed.', errors: { email: ['The email is invalid.'] } }, 422)
    }
    if (url.includes('/api/customers?')) return jsonResponse(page())
    return jsonResponse({ message: 'Not found.' }, 404)
  })
  renderCustomers(fetchMock)
  await screen.findByText('Maria Santos')

  fireEvent.click(screen.getByRole('button', { name: 'New customer' }))
  fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Juan' } })
  fireEvent.click(screen.getByRole('button', { name: 'Create customer' }))

  expect(await screen.findByText('The email is invalid.')).toBeInTheDocument()
  expect(screen.getByLabelText('Email')).toHaveAttribute('aria-invalid', 'true')
})
