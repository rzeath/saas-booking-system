import { render, screen } from '@testing-library/react'
import { afterEach, expect, test, vi } from 'vitest'

import App from '@/App'
import { AppProviders } from '@/app/providers'

afterEach(() => {
  vi.unstubAllGlobals()
})

test('renders the foundation screen and reports a healthy API connection', async () => {
  vi.stubGlobal(
    'fetch',
    vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ status: 'ok' }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    ),
  )

  render(
    <AppProviders>
      <App />
    </AppProviders>,
  )

  expect(screen.getByRole('heading', { name: 'Event Booking Management' })).toBeInTheDocument()
  expect(await screen.findByText('API connected')).toBeInTheDocument()
})
