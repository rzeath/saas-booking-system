import { fireEvent, render, screen, within } from '@testing-library/react'
import { afterEach, expect, test, vi } from 'vitest'

import App from '@/App'
import { AppProviders } from '@/app/providers'

const auth = { user: { id: 7, name: 'Erica Admin', email: 'erica@example.com' }, organization: { id: 12, name: 'Rzeath Events', status: 'active' } }
const settings = { display_name: 'Rzeath Events', email: null, phone: null, address: null, logo_path: null, currency: 'PHP', booking_prefix: 'BK', quotation_prefix: 'QT', billing_prefix: 'INV' }

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
    if (url.endsWith('/api/business-settings')) return response(settings)
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
  expect(within(drawer).getByText('Erica Admin')).toBeInTheDocument()

  fireEvent.keyDown(document, { key: 'Escape' })
  expect(screen.queryByRole('complementary', { name: 'Mobile navigation' })).not.toBeInTheDocument()
  expect(trigger).toHaveFocus()
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
    if (url.endsWith('/api/business-settings')) return response(settings)
    return response(emptyPage())
  }))
  render(<AppProviders><App /></AppProviders>)

  fireEvent.click(await screen.findByRole('button', { name: 'Sign out' }))
  expect(await screen.findByRole('heading', { name: 'Sign in' })).toBeInTheDocument()
  expect(signedOut).toBe(true)
})
