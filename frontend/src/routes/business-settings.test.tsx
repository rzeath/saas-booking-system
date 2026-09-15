import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, expect, test, vi } from 'vitest'

import App from '@/App'
import { AppProviders } from '@/app/providers'
import { StatusBadge } from '@/components/ui/status-badge'

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
  logo_url: null,
  theme_accent: 'plum',
  booking_prefix: 'BK',
  quotation_prefix: 'QT',
  billing_prefix: 'INV',
}
const originalCreateObjectUrl = URL.createObjectURL
const originalRevokeObjectUrl = URL.revokeObjectURL

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

function baseFetch(currentSettings = settings) {
  return vi.fn(async (input: RequestInfo | URL) => {
    const url = String(input)
    if (url.endsWith('/api/me')) return jsonResponse(authContext)
    if (url.endsWith('/api/v1/business-settings')) return jsonResponse(currentSettings)
    if (url.endsWith('/sanctum/csrf-cookie')) return new Response(null, { status: 204 })
    return jsonResponse({ message: 'Not found.' }, 404)
  })
}

afterEach(() => {
  vi.unstubAllGlobals()
  if (originalCreateObjectUrl) Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: originalCreateObjectUrl })
  else Reflect.deleteProperty(URL, 'createObjectURL')
  if (originalRevokeObjectUrl) Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: originalRevokeObjectUrl })
  else Reflect.deleteProperty(URL, 'revokeObjectURL')
  delete document.documentElement.dataset.themeAccent
  window.history.pushState({}, '', '/')
})

test('renders Branding settings without tenant currency or timezone controls', async () => {
  renderSettings(baseFetch())

  expect(await screen.findByRole('heading', { name: 'Business Settings' })).toBeInTheDocument()
  expect(screen.getByLabelText('Business Name')).toHaveValue('Rzeath Events')
  expect(screen.getByLabelText('Business Email')).toHaveValue('bookings@example.com')
  expect(screen.queryByLabelText('Currency')).not.toBeInTheDocument()
  expect(screen.queryByLabelText('Timezone')).not.toBeInTheDocument()
  const accents = screen.getByRole('radiogroup', { name: 'Theme Accent' })
  for (const label of ['Plum', 'Forest', 'Terracotta', 'Teal', 'Indigo', 'Graphite']) {
    expect(within(accents).getByRole('radio', { name: label })).toBeInTheDocument()
  }
  expect(within(accents).getByRole('radio', { name: 'Plum' })).toBeChecked()
})

test('shows client validation before attempting an update', async () => {
  const fetchMock = baseFetch()
  renderSettings(fetchMock)

  fireEvent.change(await screen.findByLabelText('Business Name'), { target: { value: ' ' } })
  fireEvent.change(screen.getByLabelText('Booking Prefix'), { target: { value: 'bad-prefix' } })
  fireEvent.click(screen.getByRole('button', { name: 'Save Changes' }))

  expect(await screen.findByText('Business name is required.')).toBeInTheDocument()
  expect(screen.getByText('Use letters and numbers only.')).toBeInTheDocument()
  expect(fetchMock).toHaveBeenCalledTimes(2)
})

test('saves the business name and accent through FormData and refreshes shell branding', async () => {
  const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)
    if (url.endsWith('/api/me')) return jsonResponse(authContext)
    if (url.endsWith('/sanctum/csrf-cookie')) return new Response(null, { status: 204 })
    if (url.endsWith('/api/v1/business-settings') && init?.method === 'POST') {
      const data = init.body as FormData
      return jsonResponse({
        ...settings,
        display_name: String(data.get('display_name')),
        theme_accent: String(data.get('theme_accent')),
        booking_prefix: String(data.get('booking_prefix')),
      })
    }
    if (url.endsWith('/api/v1/business-settings')) return jsonResponse(settings)
    return jsonResponse({ message: 'Not found.' }, 404)
  })
  renderSettings(fetchMock)

  fireEvent.change(await screen.findByLabelText('Business Name'), { target: { value: 'Updated Brand' } })
  fireEvent.change(screen.getByLabelText('Booking Prefix'), { target: { value: 'book' } })
  fireEvent.click(screen.getByRole('radio', { name: 'Forest' }))
  fireEvent.click(screen.getByRole('button', { name: 'Save Changes' }))

  expect(await screen.findByText('Business settings saved.')).toBeInTheDocument()
  expect(screen.getByLabelText('Business Name')).toHaveValue('Updated Brand')
  expect(screen.getByLabelText('Booking Prefix')).toHaveValue('BOOK')
  await waitFor(() => expect(document.documentElement.dataset.themeAccent).toBe('forest'))
  expect(screen.getAllByText('Updated Brand').length).toBeGreaterThan(1)

  const updateCall = fetchMock.mock.calls.find((call) => String(call[0]).endsWith('/api/v1/business-settings') && call[1]?.method === 'POST')
  const data = updateCall?.[1]?.body as FormData
  expect(data.get('_method')).toBe('PUT')
  expect(data.get('display_name')).toBe('Updated Brand')
  expect(data.get('theme_accent')).toBe('forest')
  expect(data.get('booking_prefix')).toBe('BOOK')
  expect(data.get('currency')).toBeNull()
})

test('previews and uploads a valid logo', async () => {
  Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: vi.fn(() => 'blob:logo-preview') })
  Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: vi.fn() })
  let uploadedLogo: FormDataEntryValue | null = null
  const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)
    if (url.endsWith('/api/me')) return jsonResponse(authContext)
    if (url.endsWith('/sanctum/csrf-cookie')) return new Response(null, { status: 204 })
    if (url.endsWith('/api/v1/business-settings') && init?.method === 'POST') {
      uploadedLogo = (init.body as FormData).get('logo')
      return jsonResponse({ ...settings, logo_path: 'business-logos/12/logo.png', logo_url: '/storage/business-logos/12/logo.png' })
    }
    if (url.endsWith('/api/v1/business-settings')) return jsonResponse(settings)
    return jsonResponse({}, 404)
  })
  renderSettings(fetchMock)

  const logo = new File(['image'], 'brand.png', { type: 'image/png' })
  fireEvent.change(await screen.findByLabelText('Upload Logo'), { target: { files: [logo] } })
  expect(screen.getByAltText('Rzeath Events logo preview')).toHaveAttribute('src', 'blob:logo-preview')
  fireEvent.click(screen.getByRole('button', { name: 'Save Changes' }))

  expect(await screen.findByText('Business settings saved.')).toBeInTheDocument()
  expect(uploadedLogo).toBeInstanceOf(File)
  expect(screen.getAllByAltText('Rzeath Events logo').length).toBeGreaterThan(0)
})

test('supports removing an existing logo', async () => {
  const withLogo = { ...settings, logo_path: 'business-logos/12/old.png', logo_url: '/storage/business-logos/12/old.png' }
  let removeLogo: FormDataEntryValue | null = null
  const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)
    if (url.endsWith('/api/me')) return jsonResponse(authContext)
    if (url.endsWith('/sanctum/csrf-cookie')) return new Response(null, { status: 204 })
    if (url.endsWith('/api/v1/business-settings') && init?.method === 'POST') {
      removeLogo = (init.body as FormData).get('remove_logo')
      return jsonResponse(settings)
    }
    if (url.endsWith('/api/v1/business-settings')) return jsonResponse(withLogo)
    return jsonResponse({}, 404)
  })
  renderSettings(fetchMock)

  expect(await screen.findByAltText('Rzeath Events logo preview')).toBeInTheDocument()
  fireEvent.click(screen.getByRole('button', { name: 'Remove' }))
  expect(screen.getByLabelText('RE logo fallback')).toBeInTheDocument()
  fireEvent.click(screen.getByRole('button', { name: 'Save Changes' }))

  await screen.findByText('Business settings saved.')
  expect(removeLogo).toBe('1')
})

test('maps backend validation errors to the corresponding field', async () => {
  const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)
    if (url.endsWith('/api/me')) return jsonResponse(authContext)
    if (url.endsWith('/sanctum/csrf-cookie')) return new Response(null, { status: 204 })
    if (url.endsWith('/api/v1/business-settings') && init?.method === 'POST') {
      return jsonResponse({ message: 'The booking prefix is invalid.', errors: { booking_prefix: ['The booking prefix is invalid.'] } }, 422)
    }
    if (url.endsWith('/api/v1/business-settings')) return jsonResponse(settings)
    return jsonResponse({}, 404)
  })
  renderSettings(fetchMock)

  fireEvent.click(await screen.findByRole('button', { name: 'Save Changes' }))
  expect(await screen.findByText('The booking prefix is invalid.')).toBeInTheDocument()
  expect(screen.getByRole('textbox', { name: /Booking Prefix/ })).toHaveAttribute('aria-invalid', 'true')
})

test('semantic status styling remains independent from the tenant accent', () => {
  document.documentElement.dataset.themeAccent = 'indigo'
  render(<StatusBadge tone="danger">Rejected</StatusBadge>)
  expect(screen.getByText('Rejected')).toHaveClass('bg-danger-soft', 'text-danger')
  expect(screen.getByText('Rejected')).not.toHaveClass('bg-primary-soft', 'text-primary')
})
