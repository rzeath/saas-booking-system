import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, expect, test, vi } from 'vitest'

import App from '@/App'
import { AppProviders } from '@/app/providers'

const authContext = {
  user: { id: 7, name: 'Erica Admin', email: 'erica@example.com' },
  organization: { id: 12, name: 'Canonical Tenant', status: 'active' },
}

const eventType = {
  id: 31,
  name: 'Wedding',
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

function page(data = [eventType]) {
  return {
    data,
    links: { prev: null, next: null },
    meta: { current_page: 1, last_page: 1, per_page: 15, total: data.length },
  }
}

function renderEventTypes(fetchMock: ReturnType<typeof vi.fn>) {
  window.history.pushState({}, '', '/event-types')
  vi.stubGlobal('fetch', fetchMock)
  render(<AppProviders><App /></AppProviders>)
}

function baseFetch() {
  return vi.fn(async (input: RequestInfo | URL, _init?: RequestInit) => {
    void _init
    const url = String(input)
    if (url.endsWith('/api/me')) return jsonResponse(authContext)
    if (url.includes('/api/v1/event-types?')) return jsonResponse(page())
    if (url.endsWith('/sanctum/csrf-cookie')) return new Response(null, { status: 204 })
    return jsonResponse({ message: 'Not found.' }, 404)
  })
}

afterEach(() => {
  vi.unstubAllGlobals()
  window.history.pushState({}, '', '/')
})

test('renders the event type list', async () => {
  renderEventTypes(baseFetch())

  expect(await screen.findByRole('heading', { name: 'Event Types' })).toBeInTheDocument()
  const row = (await screen.findByText('Wedding')).closest('tr')
  expect(within(row!).getByText('Active')).toBeInTheDocument()
})

test('validates event type creation before submitting', async () => {
  const fetchMock = baseFetch()
  renderEventTypes(fetchMock)
  await screen.findByText('Wedding')

  fireEvent.click(screen.getByRole('button', { name: 'New event type' }))
  fireEvent.click(screen.getByRole('button', { name: 'Create event type' }))

  expect(await screen.findByText('Event type name is required.')).toBeInTheDocument()
  expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'POST')).toBe(false)
})

test('creates an event type successfully', async () => {
  const created = { ...eventType, id: 32, name: 'Corporate Event' }
  const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)
    if (url.endsWith('/api/me')) return jsonResponse(authContext)
    if (url.endsWith('/sanctum/csrf-cookie')) return new Response(null, { status: 204 })
    if (url.endsWith('/api/v1/event-types') && init?.method === 'POST') return jsonResponse(created, 201)
    if (url.includes('/api/v1/event-types?')) return jsonResponse(page())
    return jsonResponse({ message: 'Not found.' }, 404)
  })
  renderEventTypes(fetchMock)
  await screen.findByText('Wedding')

  fireEvent.click(screen.getByRole('button', { name: 'New event type' }))
  fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Corporate Event' } })
  fireEvent.click(screen.getByRole('button', { name: 'Create event type' }))

  expect(await screen.findByText('Corporate Event saved.')).toBeInTheDocument()
})

test('shows duplicate-name backend validation', async () => {
  const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)
    if (url.endsWith('/api/me')) return jsonResponse(authContext)
    if (url.endsWith('/sanctum/csrf-cookie')) return new Response(null, { status: 204 })
    if (url.endsWith('/api/v1/event-types') && init?.method === 'POST') {
      return jsonResponse({ message: 'Validation failed.', errors: { name: ['An event type with this name already exists.'] } }, 422)
    }
    if (url.includes('/api/v1/event-types?')) return jsonResponse(page())
    return jsonResponse({ message: 'Not found.' }, 404)
  })
  renderEventTypes(fetchMock)
  await screen.findByText('Wedding')

  fireEvent.click(screen.getByRole('button', { name: 'New event type' }))
  fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'wedding' } })
  fireEvent.click(screen.getByRole('button', { name: 'Create event type' }))

  expect(await screen.findByText('An event type with this name already exists.')).toBeInTheDocument()
})

test('edits an event type', async () => {
  const updated = { ...eventType, name: 'Wedding Celebration' }
  const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)
    if (url.endsWith('/api/me')) return jsonResponse(authContext)
    if (url.endsWith('/sanctum/csrf-cookie')) return new Response(null, { status: 204 })
    if (url.endsWith('/api/v1/event-types/31') && init?.method === 'PUT') return jsonResponse(updated)
    if (url.includes('/api/v1/event-types?')) return jsonResponse(page())
    return jsonResponse({ message: 'Not found.' }, 404)
  })
  renderEventTypes(fetchMock)
  const row = (await screen.findByText('Wedding')).closest('tr')

  fireEvent.click(within(row!).getByRole('button', { name: 'Edit' }))
  fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Wedding Celebration' } })
  fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

  expect(await screen.findByText('Wedding Celebration saved.')).toBeInTheDocument()
})

test('applies the event type status filter', async () => {
  const fetchMock = baseFetch()
  renderEventTypes(fetchMock)
  await screen.findByText('Wedding')

  fireEvent.change(screen.getByLabelText('Status filter'), { target: { value: 'inactive' } })

  await waitFor(() => expect(fetchMock.mock.calls.some(([input]) => String(input).includes('status=inactive'))).toBe(true))
})
