import { fireEvent, render, screen } from '@testing-library/react'
import { afterEach, expect, test, vi } from 'vitest'

import App from '@/App'
import { AppProviders } from '@/app/providers'

function jsonResponse(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

function renderApp(path: string, currentAuth: Response) {
  window.history.pushState({}, '', path)
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue(currentAuth))

  render(<AppProviders><App /></AppProviders>)
}

afterEach(() => {
  vi.unstubAllGlobals()
  window.history.pushState({}, '', '/')
})

test('redirects an unauthenticated protected route to login without flashing protected content', async () => {
  renderApp('/', jsonResponse({ message: 'Unauthenticated.' }, 401))

  expect(screen.getByRole('status')).toHaveTextContent('Checking your session')
  expect(await screen.findByRole('heading', { name: 'Sign in' })).toBeInTheDocument()
  expect(screen.queryByText('Business Name')).not.toBeInTheDocument()
})

test('renders login validation feedback before making an authentication request', async () => {
  renderApp('/login', jsonResponse({ message: 'Unauthenticated.' }, 401))

  fireEvent.click(await screen.findByRole('button', { name: 'Sign in' }))

  expect(await screen.findByText('Enter a valid email address.')).toBeInTheDocument()
  expect(screen.getByText('Password is required.')).toBeInTheDocument()
  expect(fetch).toHaveBeenCalledTimes(1)
})

test('renders the authenticated user and only their organization context', async () => {
  renderApp('/', jsonResponse({
    user: { id: 7, name: 'Erica Admin', email: 'erica@example.com' },
    organization: { id: 12, name: 'Rzeath Events', status: 'active' },
  }))

  expect((await screen.findAllByText('TakdaOps')).length).toBeGreaterThan(0)
  expect(screen.getAllByText('Rzeath Events').length).toBeGreaterThan(0)
  expect(screen.getAllByText('Erica Admin').length).toBeGreaterThan(0)
  expect(screen.getAllByText('erica@example.com').length).toBeGreaterThan(0)
})

test('validates all required registration fields', async () => {
  renderApp('/register', jsonResponse({ message: 'Unauthenticated.' }, 401))

  fireEvent.click(await screen.findByRole('button', { name: 'Create account' }))

  expect(await screen.findByText('Business name is required.')).toBeInTheDocument()
  expect(screen.getByText('Admin name is required.')).toBeInTheDocument()
  expect(screen.getByText('Enter a valid email address.')).toBeInTheDocument()
  expect(screen.getByText('Use at least 8 characters.')).toBeInTheDocument()
  expect(screen.getByText('Confirm your password.')).toBeInTheDocument()
  expect(fetch).toHaveBeenCalledTimes(1)
})
