import { fireEvent, render, screen, within } from '@testing-library/react'
import { afterEach, expect, test, vi } from 'vitest'

import App from '@/App'
import { AppProviders } from '@/app/providers'

const auth = { user: { id: 7, name: 'Erica Admin', email: 'erica@example.com' }, organization: { id: 12, name: 'Canonical Tenant', status: 'active' } }
const settings = { display_name: 'Rzeath Events', email: null, phone: null, address: null, logo_path: null, logo_url: null, theme_accent: 'plum', booking_prefix: 'BK', quotation_prefix: 'QT', billing_prefix: 'INV' }

function response(body: unknown) {
  return new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } })
}

function emptyPage() {
  return { data: [], links: { prev: null, next: null }, meta: { current_page: 1, last_page: 1, per_page: 8, total: 0 } }
}

afterEach(() => {
  vi.unstubAllGlobals()
  window.history.pushState({}, '', '/')
})

test('opens and dismisses grouped navigation at a mobile viewport', async () => {
  Object.defineProperty(window, 'innerWidth', { configurable: true, value: 390 })
  vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
    const url = String(input)
    if (url.endsWith('/api/me')) return response(auth)
    if (url.endsWith('/api/v1/business-settings')) return response(settings)
    return response(emptyPage())
  }))
  render(<AppProviders><App /></AppProviders>)

  const trigger = await screen.findByRole('button', { name: 'Open navigation' })
  expect(trigger).toHaveAttribute('aria-expanded', 'false')
  fireEvent.click(trigger)

  const drawer = screen.getByRole('complementary', { name: 'Mobile navigation' })
  expect(trigger).toHaveAttribute('aria-expanded', 'true')
  expect(within(drawer).getByRole('link', { name: 'Dashboard' })).toHaveAttribute('aria-current', 'page')
  expect(within(drawer).getByText('Operations')).toBeInTheDocument()
  expect(within(drawer).getByRole('link', { name: 'Bookings' })).toHaveAttribute('href', '/bookings')
  expect(within(drawer).getByRole('link', { name: 'Calendar' })).toHaveAttribute('href', '/calendar')
  expect(within(drawer).getByRole('link', { name: 'Customers' })).toHaveAttribute('href', '/customers')
  expect(within(drawer).getByText('Commercial')).toBeInTheDocument()
  expect(within(drawer).getByRole('link', { name: 'Quotations' })).toHaveAttribute('href', '/quotations')
  expect(within(drawer).getByRole('link', { name: 'Billings' })).toHaveAttribute('href', '/billings')
  expect(within(drawer).getByRole('link', { name: 'Payments' })).toHaveAttribute('href', '/payments')
  expect(within(drawer).getByText('Configuration')).toBeInTheDocument()
  expect(within(drawer).getByRole('link', { name: 'Master Data' })).toHaveAttribute('href', '/master-data')
  expect(within(drawer).queryByRole('link', { name: 'Service Rates' })).not.toBeInTheDocument()
  expect(within(drawer).getByRole('link', { name: 'Rzeath Events' })).toBeInTheDocument()
  expect(within(drawer).getByLabelText('RE business initials')).toBeInTheDocument()
  expect(within(drawer).getByText('Event Services · Photobooth')).toBeInTheDocument()
  expect(within(drawer).getByText('Powered by TakdaOps')).toBeInTheDocument()
  expect(within(drawer).getByText('Erica Admin')).toBeInTheDocument()
  expect(within(drawer).getByText('Owner ·')).toBeInTheDocument()
  expect(within(drawer).queryByPlaceholderText(/search/i)).not.toBeInTheDocument()

  fireEvent.keyDown(document, { key: 'Escape' })
  expect(screen.queryByRole('complementary', { name: 'Mobile navigation' })).not.toBeInTheDocument()
  expect(trigger).toHaveFocus()
})

test('shows the tenant logo and Business Settings name separately from owner identity', async () => {
  const brandedSettings = { ...settings, logo_path: 'business-logos/12/brand.png', logo_url: '/storage/business-logos/12/brand.png' }
  vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
    const url = String(input)
    if (url.endsWith('/api/me')) return response(auth)
    if (url.endsWith('/api/v1/business-settings')) return response(brandedSettings)
    return response(emptyPage())
  }))
  render(<AppProviders><App /></AppProviders>)

  expect((await screen.findAllByAltText('Rzeath Events logo')).length).toBeGreaterThan(0)
  expect(screen.getAllByText('Rzeath Events').length).toBeGreaterThan(0)
  expect(screen.getByText('Erica Admin')).toBeInTheDocument()
  expect(screen.queryByText('Canonical Tenant')).not.toBeInTheDocument()
})

test('signs out from the anchored account control and returns to login', async () => {
  let signedOut = false
  vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)
    if (url.endsWith('/sanctum/csrf-cookie')) return new Response(null, { status: 204 })
    if (url.endsWith('/api/auth/logout') && init?.method === 'POST') {
      signedOut = true
      return new Response(null, { status: 204 })
    }
    if (url.endsWith('/api/me')) {
      return signedOut
        ? new Response(JSON.stringify({ message: 'Unauthenticated.' }), { status: 401, headers: { 'Content-Type': 'application/json' } })
        : response(auth)
    }
    if (url.endsWith('/api/v1/business-settings')) return response(settings)
    return response(emptyPage())
  }))
  render(<AppProviders><App /></AppProviders>)

  fireEvent.click(await screen.findByRole('button', { name: 'Sign out' }))
  expect(await screen.findByRole('heading', { name: 'Sign in' })).toBeInTheDocument()
  expect(signedOut).toBe(true)
})
