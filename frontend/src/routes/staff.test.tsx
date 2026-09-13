import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, expect, test, vi } from 'vitest'

import App from '@/App'
import { AppProviders } from '@/app/providers'

const authContext = {
  user: { id: 7, name: 'Erica Admin', email: 'erica@example.com' },
  organization: { id: 12, name: 'Canonical Tenant', status: 'active' },
}

const staff = {
  id: 41,
  name: 'Maria Santos',
  phone: '0917 123 4567',
  email: 'maria@example.com',
  notes: 'Lead operator',
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

function page(data = [staff]) {
  return {
    data,
    links: { prev: null, next: null },
    meta: { current_page: 1, last_page: 1, per_page: 15, total: data.length },
  }
}

function baseFetch(data = [staff]) {
  return vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)
    if (url.endsWith('/api/me')) return jsonResponse(authContext)
    if (url.endsWith('/sanctum/csrf-cookie')) return new Response(null, { status: 204 })
    if (url.endsWith('/api/staff') && init?.method === 'POST') {
      return jsonResponse({ ...staff, id: 42, name: 'Juan Dela Cruz' }, 201)
    }
    if (url.endsWith('/api/staff/41') && init?.method === 'PUT') {
      const body = JSON.parse(String(init.body)) as { name: string; is_active: boolean }
      return jsonResponse({ ...staff, name: body.name, is_active: body.is_active })
    }
    if (url.includes('/api/staff?')) return jsonResponse(page(data))
    return jsonResponse({ message: 'Not found.' }, 404)
  })
}

function renderStaff(fetchMock: ReturnType<typeof vi.fn>) {
  window.history.pushState({}, '', '/staff')
  vi.stubGlobal('fetch', fetchMock)
  render(<AppProviders><App /></AppProviders>)
}

afterEach(() => {
  vi.unstubAllGlobals()
  window.history.pushState({}, '', '/')
})

test('renders the staff list', async () => {
  renderStaff(baseFetch())

  expect(await screen.findByRole('heading', { name: 'Staff' })).toBeInTheDocument()
  const row = (await screen.findByText('Maria Santos')).closest('tr')
  expect(within(row!).getByText('0917 123 4567')).toBeInTheDocument()
  expect(within(row!).getByText('maria@example.com')).toBeInTheDocument()
  expect(within(row!).getByText('Active')).toBeInTheDocument()
})

test('renders the staff empty state', async () => {
  renderStaff(baseFetch([]))

  expect(await screen.findByText('No staff match these filters.')).toBeInTheDocument()
})

test('sends staff search and status filters', async () => {
  const fetchMock = baseFetch()
  renderStaff(fetchMock)
  await screen.findByText('Maria Santos')

  fireEvent.change(screen.getByLabelText('Search staff'), { target: { value: '0917' } })
  fireEvent.click(screen.getByRole('button', { name: 'Search' }))
  fireEvent.change(screen.getByLabelText('Status filter'), { target: { value: 'inactive' } })

  await waitFor(() => expect(fetchMock.mock.calls.some(([input]) => {
    const url = String(input)
    return url.includes('search=0917') && url.includes('status=inactive')
  })).toBe(true))
})

test('validates required staff fields before submitting', async () => {
  const fetchMock = baseFetch()
  renderStaff(fetchMock)
  await screen.findByText('Maria Santos')

  fireEvent.click(screen.getByRole('button', { name: 'New staff member' }))
  fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'invalid-email' } })
  fireEvent.click(screen.getByRole('button', { name: 'Create staff member' }))

  expect(await screen.findByText('Staff name is required.')).toBeInTheDocument()
  expect(screen.getByText('Phone is required.')).toBeInTheDocument()
  expect(screen.getByText('Enter a valid email address.')).toBeInTheDocument()
  expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'POST')).toBe(false)
})

test('creates a staff member successfully', async () => {
  renderStaff(baseFetch())
  await screen.findByText('Maria Santos')

  fireEvent.click(screen.getByRole('button', { name: 'New staff member' }))
  fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Juan Dela Cruz' } })
  fireEvent.change(screen.getByLabelText('Phone'), { target: { value: '0999 555 1111' } })
  fireEvent.click(screen.getByRole('button', { name: 'Create staff member' }))

  expect(await screen.findByText('Juan Dela Cruz saved.')).toBeInTheDocument()
})

test('edits a staff member', async () => {
  renderStaff(baseFetch())
  const row = (await screen.findByText('Maria Santos')).closest('tr')

  fireEvent.click(within(row!).getByRole('button', { name: 'Edit' }))
  fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Maria Reyes' } })
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

  expect(await screen.findByText('Maria Reyes saved.')).toBeInTheDocument()
})

test('deactivates staff', async () => {
  const fetchMock = baseFetch()
  renderStaff(fetchMock)
  const row = (await screen.findByText('Maria Santos')).closest('tr')

  fireEvent.click(within(row!).getByRole('button', { name: 'Deactivate' }))
  expect(await screen.findByText('Maria Santos is now inactive.')).toBeInTheDocument()

  await waitFor(() => expect(fetchMock.mock.calls.some(([, init]) => {
    if (init?.method !== 'PUT') return false
    return (JSON.parse(String(init.body)) as { is_active: boolean }).is_active === false
  })).toBe(true))
})

test('reactivates staff', async () => {
  renderStaff(baseFetch([{ ...staff, is_active: false }]))
  const inactiveRow = (await screen.findByText('Maria Santos')).closest('tr')
  fireEvent.click(within(inactiveRow!).getByRole('button', { name: 'Activate' }))
  expect(await screen.findByText('Maria Santos is now active.')).toBeInTheDocument()
})

test('maps backend validation errors to the matching field', async () => {
  const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)
    if (url.endsWith('/api/me')) return jsonResponse(authContext)
    if (url.endsWith('/sanctum/csrf-cookie')) return new Response(null, { status: 204 })
    if (url.endsWith('/api/staff') && init?.method === 'POST') {
      return jsonResponse({ message: 'Validation failed.', errors: { phone: ['The phone field is required.'] } }, 422)
    }
    if (url.includes('/api/staff?')) return jsonResponse(page())
    return jsonResponse({}, 404)
  })
  renderStaff(fetchMock)
  await screen.findByText('Maria Santos')

  fireEvent.click(screen.getByRole('button', { name: 'New staff member' }))
  fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Juan Dela Cruz' } })
  fireEvent.change(screen.getByLabelText('Phone'), { target: { value: '0917' } })
  fireEvent.click(screen.getByRole('button', { name: 'Create staff member' }))

  expect(await screen.findByText('The phone field is required.')).toBeInTheDocument()
})

test('shows a generic save error', async () => {
  const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)
    if (url.endsWith('/api/me')) return jsonResponse(authContext)
    if (url.endsWith('/sanctum/csrf-cookie')) return new Response(null, { status: 204 })
    if (url.endsWith('/api/staff') && init?.method === 'POST') {
      return jsonResponse({ message: 'Unable to save staff right now.' }, 500)
    }
    if (url.includes('/api/staff?')) return jsonResponse(page())
    return jsonResponse({}, 404)
  })
  renderStaff(fetchMock)
  await screen.findByText('Maria Santos')

  fireEvent.click(screen.getByRole('button', { name: 'New staff member' }))
  fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Juan Dela Cruz' } })
  fireEvent.change(screen.getByLabelText('Phone'), { target: { value: '0917' } })
  fireEvent.click(screen.getByRole('button', { name: 'Create staff member' }))

  expect(await screen.findByText('Unable to save staff right now.')).toBeInTheDocument()
})
