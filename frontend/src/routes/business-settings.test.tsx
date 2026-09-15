import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, expect, test, vi } from 'vitest'

import App from '@/App'
import { AppProviders } from '@/app/providers'

const authContext = {
  user: { id: 7, name: 'Erica Admin', email: 'erica@example.com' },
  organization: { id: 12, name: 'Canonical Tenant', status: 'active' },
}

const settings = {
  display_name: 'Rzeath Events',
  email: 'bookings@example.com',
  phone: '+63 917 123 4567',
  address: 'Makati City',
  logo_path: null,
  currency: 'PHP',
  booking_prefix: 'BK',
  quotation_prefix: 'QT',
  billing_prefix: 'INV',
}

function jsonResponse(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

function renderSettings(fetchMock: ReturnType<typeof vi.fn>) {
  window.history.pushState({}, '', '/settings/business')
  vi.stubGlobal('fetch', fetchMock)
  render(<AppProviders><App /></AppProviders>)
}

function baseFetch() {
  return vi.fn(async (input: RequestInfo | URL) => {
    const url = String(input)

    if (url.endsWith('/api/me')) return jsonResponse(authContext)
    if (url.endsWith('/api/v1/business-settings')) return jsonResponse(settings)
    if (url.endsWith('/sanctum/csrf-cookie')) return new Response(null, { status: 204 })

    return jsonResponse({ message: 'Not found.' }, 404)
  })
}

afterEach(() => {
  vi.unstubAllGlobals()
  window.history.pushState({}, '', '/')
})

test('loads and renders the current tenant business settings', async () => {
  renderSettings(baseFetch())

  expect(await screen.findByRole('heading', { name: 'Business settings' })).toBeInTheDocument()
  expect(screen.getByLabelText('Display Name')).toHaveValue('Rzeath Events')
  expect(screen.getByLabelText('Business Email')).toHaveValue('bookings@example.com')
  expect(screen.queryByLabelText('Timezone')).not.toBeInTheDocument()
  expect(screen.getByLabelText('Currency')).toHaveValue('PHP')
  expect(screen.getByLabelText('Billing Prefix')).toHaveValue('INV')
})

test('shows client validation before attempting an update', async () => {
  const fetchMock = baseFetch()
  renderSettings(fetchMock)

  fireEvent.change(await screen.findByLabelText('Display Name'), { target: { value: ' ' } })
  fireEvent.change(screen.getByLabelText('Booking Prefix'), { target: { value: 'bad-prefix' } })
  fireEvent.click(screen.getByRole('button', { name: 'Save settings' }))

  expect(await screen.findByText('Display name is required.')).toBeInTheDocument()
  expect(screen.getByText('Use letters and numbers only.')).toBeInTheDocument()
  expect(fetchMock).toHaveBeenCalledTimes(2)
})

test('submits normalized settings and renders the saved result', async () => {
  const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)

    if (url.endsWith('/api/me')) return jsonResponse(authContext)
    if (url.endsWith('/sanctum/csrf-cookie')) return new Response(null, { status: 204 })
    if (url.endsWith('/api/v1/business-settings') && init?.method === 'PUT') {
      const inputSettings = JSON.parse(String(init.body)) as typeof settings
      return jsonResponse({ ...settings, ...inputSettings })
    }
    if (url.endsWith('/api/v1/business-settings')) return jsonResponse(settings)

    return jsonResponse({ message: 'Not found.' }, 404)
  })
  renderSettings(fetchMock)

  fireEvent.change(await screen.findByLabelText('Display Name'), { target: { value: 'Updated Brand' } })
  fireEvent.change(screen.getByLabelText('Booking Prefix'), { target: { value: 'book' } })
  fireEvent.click(screen.getByRole('button', { name: 'Save settings' }))

  expect(await screen.findByText('Business settings saved.')).toBeInTheDocument()
  expect(screen.getByLabelText('Display Name')).toHaveValue('Updated Brand')
  expect(screen.getByLabelText('Booking Prefix')).toHaveValue('BOOK')

  const updateCall = fetchMock.mock.calls.find((call) => String(call[0]).endsWith('/api/v1/business-settings') && call[1]?.method === 'PUT')
  expect(JSON.parse(String(updateCall?.[1]?.body))).toMatchObject({
    display_name: 'Updated Brand',
    booking_prefix: 'BOOK',
  })
})

test('maps backend validation errors to the corresponding field', async () => {
  const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)

    if (url.endsWith('/api/me')) return jsonResponse(authContext)
    if (url.endsWith('/sanctum/csrf-cookie')) return new Response(null, { status: 204 })
    if (url.endsWith('/api/v1/business-settings') && init?.method === 'PUT') {
      return jsonResponse({
        message: 'The booking prefix is invalid.',
        errors: { booking_prefix: ['The booking prefix is invalid.'] },
      }, 422)
    }
    if (url.endsWith('/api/v1/business-settings')) return jsonResponse(settings)

    return jsonResponse({ message: 'Not found.' }, 404)
  })
  renderSettings(fetchMock)

  fireEvent.click(await screen.findByRole('button', { name: 'Save settings' }))

  expect(await screen.findByText('The booking prefix is invalid.')).toBeInTheDocument()
  expect(screen.getByRole('textbox', { name: /Booking Prefix/ })).toHaveAttribute('aria-invalid', 'true')
  await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(4))
})
