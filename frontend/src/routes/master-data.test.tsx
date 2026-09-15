import { fireEvent, render, screen } from '@testing-library/react'
import { afterEach, expect, test, vi } from 'vitest'

import App from '@/App'
import { AppProviders } from '@/app/providers'

const auth = { user: { id: 1, name: 'Admin', email: 'admin@example.com' }, organization: { id: 1, name: 'Tenant', status: 'active' } }

function response(body: unknown) {
  return new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } })
}

function emptyPage() {
  return { data: [], links: { prev: null, next: null }, meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 } }
}

function renderWorkspace(path = '/master-data') {
  window.history.pushState({}, '', path)
  vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
    if (String(input).endsWith('/api/me')) return response(auth)
    return response(emptyPage())
  }))
  render(<AppProviders><App /></AppProviders>)
}

afterEach(() => {
  vi.unstubAllGlobals()
  window.history.pushState({}, '', '/')
})

test('renders the unified Master Data workspace and switches tabs', async () => {
  renderWorkspace()

  expect(await screen.findByRole('heading', { name: 'Master Data' })).toBeInTheDocument()
  expect(screen.getByText('Manage the configuration used by bookings and pricing.')).toBeInTheDocument()
  expect(screen.getAllByRole('tab').map((tab) => tab.textContent)).toEqual(['Event Types', 'Services', 'Packages', 'Staff'])
  expect(screen.getByRole('tab', { name: 'Event Types' })).toHaveAttribute('aria-selected', 'true')

  fireEvent.click(screen.getByRole('tab', { name: 'Services' }))
  expect(await screen.findByRole('heading', { name: 'Services' })).toBeInTheDocument()
  expect(screen.getByRole('tab', { name: 'Services' })).toHaveAttribute('aria-selected', 'true')
  expect(window.location.search).toBe('?tab=services')
})

test('redirects a legacy Master Data route into the matching workspace tab', async () => {
  renderWorkspace('/packages')

  expect(await screen.findByRole('heading', { name: 'Master Data' })).toBeInTheDocument()
  expect(screen.getByRole('tab', { name: 'Packages' })).toHaveAttribute('aria-selected', 'true')
  expect(window.location.pathname).toBe('/master-data')
})
