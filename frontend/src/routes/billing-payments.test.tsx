import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, expect, test, vi } from 'vitest'

import App from '@/App'
import { AppProviders } from '@/app/providers'

const auth = { user: { id: 7, name: 'Admin', email: 'admin@example.com' }, organization: { id: 1, name: 'Studio', status: 'active' } }
const settings = { display_name: 'Studio', email: null, phone: null, address: null, logo_path: null, logo_url: null, theme_accent: 'plum', booking_prefix: 'BK', quotation_prefix: 'QT', billing_prefix: 'INV' }

const billing = {
  id: 41,
  billing_number: 'INV-2026-000001',
  quotation: { id: 21, quotation_number: 'QT-2026-000003' },
  booking: { id: 8, booking_number: 'BK-2026-000001', status: 'CONFIRMED' },
  seller_snapshot: { display_name: 'Historical Studio', email: 'studio@example.com', phone: '09170000000', address: 'Cebu City', logo_path: null },
  customer_snapshot: { name: 'Ana Cruz', email: 'ana@example.com', phone: '09171234567', address: 'Makati' },
  event_snapshot: { event_type_name: 'Wedding', event_name: 'Ana and Leo', event_date: '2026-10-15', venue_name: 'The Glass House', venue_address: 'Makati', contact_person: 'Ana Cruz', contact_number: '09171234567' },
  subtotal: '8000.00',
  transportation_fee: '250.25',
  crew_meal_fee: '125.00',
  discount_amount: '250.00',
  total: '8125.25',
  payment_summary: { amount_paid: '2000.10', remaining_balance: '6125.15', payment_status: 'PARTIALLY_PAID' },
  items: [{ id: 51, service_name: 'Mirror Booth', package_name: 'Premium', start_at: '2026-10-15 18:00', end_at: '2026-10-15 21:00', duration_minutes: 180, quantity: 1, unit_rate: '8000.00', line_total: '8000.00', sort_order: 0 }],
  created_at: '2026-09-10T02:00:00.000000Z',
}

const postedPayment = {
  id: 61,
  billing: { id: 41, billing_number: 'INV-2026-000001' },
  quotation: { id: 21, quotation_number: 'QT-2026-000003' },
  booking: { id: 8, booking_number: 'BK-2026-000001', status: 'CONFIRMED' },
  amount: '2000.10',
  paid_at: '2026-09-10 10:30:00',
  payment_method: 'GCASH',
  reference_number: 'GCASH-001',
  internal_note: 'Initial deposit.',
  status: 'POSTED',
  created_by: { id: 7, name: 'Admin' },
  voided_at: null,
  void_reason: null,
  voided_by: null,
  created_at: '2026-09-10T02:30:00.000000Z',
}

const voidedPayment = {
  ...postedPayment,
  id: 62,
  amount: '500.00',
  payment_method: 'CASH',
  reference_number: null,
  status: 'VOIDED',
  voided_at: '2026-09-11T02:30:00.000000Z',
  void_reason: 'Duplicate entry.',
  voided_by: { id: 7, name: 'Admin' },
}

const acceptedQuotation = {
  id: 21,
  quotation_number: 'QT-2026-000003',
  status: 'ACCEPTED',
  booking: { id: 8, booking_number: 'BK-2026-000001', status: 'QUOTED' },
  billing: null,
  valid_until: '2026-10-01',
  sent_at: '2026-09-08T02:00:00.000000Z',
  accepted_at: '2026-09-09T02:00:00.000000Z',
  closed_at: null,
  seller_snapshot: billing.seller_snapshot,
  customer_snapshot: billing.customer_snapshot,
  event_snapshot: billing.event_snapshot,
  subtotal: '8000.00',
  transportation_fee: '250.25',
  crew_meal_fee: '125.00',
  discount_amount: '250.00',
  total: '8125.25',
  items: billing.items,
  created_at: '2026-09-07T02:00:00.000000Z',
  updated_at: '2026-09-09T02:00:00.000000Z',
}

function response(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

function page(data: unknown[], currentPage = 1, lastPage = 1, total = data.length) {
  return { data, links: { prev: currentPage > 1 ? 'prev' : null, next: currentPage < lastPage ? 'next' : null }, meta: { current_page: currentPage, last_page: lastPage, per_page: 15, total } }
}

function financialFetch(overrides?: (url: string, init?: RequestInit) => Response | Promise<Response> | undefined) {
  return vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)
    const overridden = overrides?.(url, init)
    if (overridden) return overridden
    if (url.endsWith('/api/me')) return response(auth)
    if (url.endsWith('/sanctum/csrf-cookie')) return new Response(null, { status: 204 })
    if (url.endsWith('/api/v1/business-settings')) return response(settings)
    if (url.endsWith('/api/v1/quotations/21')) return response(acceptedQuotation)
    if (url.endsWith('/api/v1/billings/41')) return response(billing)
    if (url.includes('/api/v1/billings/41/payments?')) return response(page([postedPayment, voidedPayment]))
    if (url.includes('/api/v1/billings?')) return response(page([billing]))
    if (url.includes('/api/v1/payments?')) return response(page([postedPayment, voidedPayment]))
    return response({ message: 'Not found.' }, 404)
  })
}

function renderRoute(path: string, fetchMock = financialFetch()) {
  window.history.pushState({}, '', path)
  vi.stubGlobal('fetch', fetchMock)
  render(<AppProviders><App /></AppProviders>)
  return fetchMock
}

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  window.history.pushState({}, '', '/')
})

test('Accepted Quotation without Billing validates and records its first Payment', async () => {
  let quotationState: Record<string, unknown> = acceptedQuotation
  let payload: Record<string, unknown> | undefined
  const result = {
    payment: { ...postedPayment, billing: { id: 41, billing_number: 'INV-2026-000001' }, booking: { ...postedPayment.booking, status: 'CONFIRMED' }, amount: '1000.00', reference_number: 'GCASH-FIRST' },
    billing: { id: 41, billing_number: 'INV-2026-000001' },
    payment_summary: { amount_paid: '1000.00', remaining_balance: '7125.25', payment_status: 'PARTIALLY_PAID' },
    booking: { id: 8, booking_number: 'BK-2026-000001', status: 'CONFIRMED' },
  }
  const fetchMock = financialFetch((url, init) => {
    if (url.endsWith('/api/v1/quotations/21') && !init?.method) return response(quotationState)
    if (url.endsWith('/api/v1/quotations/21/payments') && init?.method === 'POST') {
      payload = JSON.parse(String(init.body)) as Record<string, unknown>
      quotationState = { ...acceptedQuotation, billing: result.billing, booking: result.booking }
      return response(result, 201)
    }
  })
  renderRoute('/quotations/21', fetchMock)

  fireEvent.click(await screen.findByRole('button', { name: 'Record Payment' }, { timeout: 5000 }))
  const dialog = screen.getByRole('dialog', { name: 'Record Payment' })
  fireEvent.click(within(dialog).getByRole('button', { name: 'Record Payment' }))
  expect(await within(dialog).findByText(/greater than zero/)).toBeInTheDocument()

  fireEvent.change(within(dialog).getByLabelText('Amount'), { target: { value: '1000.00' } })
  fireEvent.change(within(dialog).getByLabelText('Payment method'), { target: { value: 'GCASH' } })
  fireEvent.click(within(dialog).getByRole('button', { name: 'Record Payment' }))
  expect(await within(dialog).findByText(/reference number for this payment method/)).toBeInTheDocument()
  fireEvent.change(within(dialog).getByLabelText('Reference number'), { target: { value: 'GCASH-FIRST' } })
  fireEvent.click(within(dialog).getByRole('button', { name: 'Record Payment' }))

  expect(await screen.findByText('Payment recorded. Billing INV-2026-000001 is ready.')).toBeInTheDocument()
  expect(screen.getByRole('link', { name: 'View Billing' })).toHaveAttribute('href', '/billings/41')
  expect(payload).toEqual(expect.objectContaining({ amount: '1000.00', payment_method: 'GCASH', reference_number: 'GCASH-FIRST' }))
  expect(payload).not.toHaveProperty('billing_id')
  expect(payload).not.toHaveProperty('currency')
})

test('Quotation payment actions follow lifecycle and remaining balance', async () => {
  const draft = { ...acceptedQuotation, status: 'DRAFT', billing: null, booking: { ...acceptedQuotation.booking, status: 'PENDING' } }
  renderRoute('/quotations/21', financialFetch((url) => url.endsWith('/api/v1/quotations/21') ? response(draft) : undefined))
  await screen.findByRole('heading', { name: 'QT-2026-000003' })
  expect(screen.queryByRole('button', { name: 'Record Payment' })).not.toBeInTheDocument()

  cleanup()
  vi.unstubAllGlobals()
  const fullBilling = { ...billing, payment_summary: { amount_paid: '8125.25', remaining_balance: '0.00', payment_status: 'PAID' } }
  const withBilling = { ...acceptedQuotation, billing: { id: 41, billing_number: 'INV-2026-000001' }, booking: { ...acceptedQuotation.booking, status: 'CONFIRMED' } }
  renderRoute('/quotations/21', financialFetch((url) => {
    if (url.endsWith('/api/v1/quotations/21')) return response(withBilling)
    if (url.endsWith('/api/v1/billings/41')) return response(fullBilling)
  }))
  expect(await screen.findByRole('link', { name: 'View Billing' })).toHaveAttribute('href', '/billings/41')
  await waitFor(() => expect(screen.queryByRole('button', { name: 'Record Payment' })).not.toBeInTheDocument())
})

test('Record Payment surfaces backend validation errors', async () => {
  renderRoute('/quotations/21', financialFetch((url, init) => {
    if (url.endsWith('/api/v1/quotations/21/payments') && init?.method === 'POST') {
      return response({ message: 'Validation failed.', errors: { amount: ['The payment amount cannot exceed the remaining balance.'] } }, 422)
    }
  }))

  fireEvent.click(await screen.findByRole('button', { name: 'Record Payment' }))
  const dialog = screen.getByRole('dialog')
  fireEvent.change(within(dialog).getByLabelText('Amount'), { target: { value: '1000.00' } })
  fireEvent.click(within(dialog).getByRole('button', { name: 'Record Payment' }))
  expect(await within(dialog).findByText('The payment amount cannot exceed the remaining balance.')).toBeInTheDocument()
})

test('Billing detail records a subsequent partial Payment with exact balance validation', async () => {
  let billingState = billing
  let payload: Record<string, unknown> | undefined
  const nextPayment = {
    ...postedPayment,
    id: 63,
    amount: '125.05',
    paid_at: '2026-09-15 10:30:00',
    payment_method: 'CASH',
    reference_number: null,
    internal_note: null,
  }
  const result = {
    payment: nextPayment,
    billing: postedPayment.billing,
    payment_summary: { amount_paid: '2125.15', remaining_balance: '6000.10', payment_status: 'PARTIALLY_PAID' },
    booking: postedPayment.booking,
  }
  let postCount = 0
  renderRoute('/billings/41', financialFetch((url, init) => {
    if (url.endsWith('/api/v1/billings/41')) return response(billingState)
    if (url.endsWith('/api/v1/quotations/21/payments') && init?.method === 'POST') {
      postCount += 1
      payload = JSON.parse(String(init.body)) as Record<string, unknown>
      billingState = { ...billing, payment_summary: result.payment_summary }
      return response(result, 201)
    }
  }))

  fireEvent.click(await screen.findByRole('button', { name: 'Record Payment' }))
  const dialog = screen.getByRole('dialog', { name: 'Record Payment' })
  fireEvent.change(within(dialog).getByLabelText('Amount'), { target: { value: '6125.16' } })
  fireEvent.click(within(dialog).getByRole('button', { name: 'Record Payment' }))
  expect(await within(dialog).findByText('Amount cannot exceed the remaining balance of ₱6,125.15.')).toBeInTheDocument()
  expect(postCount).toBe(0)

  fireEvent.change(within(dialog).getByLabelText('Amount'), { target: { value: '125.05' } })
  fireEvent.click(within(dialog).getByRole('button', { name: 'Record Payment' }))

  expect(await screen.findByText('₱125.05 payment recorded.')).toBeInTheDocument()
  expect(payload).toEqual(expect.objectContaining({ amount: '125.05', payment_method: 'CASH', reference_number: null }))
  await waitFor(() => expect(screen.getAllByText('₱6,000.10').length).toBeGreaterThan(0))
})

test('Billings list renders exact summaries, statuses, search, and pagination', async () => {
  const unpaid = { ...billing, id: 42, billing_number: 'INV-2026-000002', payment_summary: { amount_paid: '0.00', remaining_balance: '8125.25', payment_status: 'UNPAID' } }
  const paid = { ...billing, id: 43, billing_number: 'INV-2026-000003', payment_summary: { amount_paid: '8125.25', remaining_balance: '0.00', payment_status: 'PAID' } }
  const fetchMock = financialFetch((url) => {
    if (url.includes('/api/v1/billings?')) return response(page([billing, unpaid, paid], url.includes('page=2') ? 2 : 1, 2, 3))
  })
  renderRoute('/billings', fetchMock)

  await screen.findByText('INV-2026-000001')
  expect(screen.getByText('Partially paid')).toBeInTheDocument()
  expect(screen.getByText('Unpaid')).toBeInTheDocument()
  expect(screen.getAllByText('Paid').length).toBeGreaterThan(0)
  expect(screen.getAllByText('₱6,125.15').length).toBeGreaterThan(0)
  fireEvent.change(screen.getByLabelText('Search billings'), { target: { value: 'Ana' } })
  fireEvent.click(screen.getByRole('button', { name: 'Search' }))
  fireEvent.click(await screen.findByRole('button', { name: 'Next' }))
  await waitFor(() => expect(fetchMock.mock.calls.some(([input]) => String(input).includes('search=Ana') && String(input).includes('page=2'))).toBe(true))
  expect(screen.queryByLabelText(/currency/i)).not.toBeInTheDocument()
})

test('Billing detail uses snapshots and retains POSTED and VOIDED payment history', async () => {
  renderRoute('/billings/41')
  await screen.findByRole('heading', { name: 'INV-2026-000001' })

  expect(screen.getByText('Billing snapshot')).toBeInTheDocument()
  expect(screen.getByText('Historical Studio')).toBeInTheDocument()
  expect(screen.getByText('Ana and Leo')).toBeInTheDocument()
  expect(screen.getByText('Mirror Booth')).toBeInTheDocument()
  expect(screen.getByText('Oct 15, 2026, 6:00 PM')).toBeInTheDocument()
  expect(screen.getByText('to Oct 15, 2026, 9:00 PM')).toBeInTheDocument()
  expect(screen.getAllByText('₱8,125.25').length).toBeGreaterThan(0)
  expect(screen.getAllByText('₱6,125.15').length).toBeGreaterThan(0)
  expect(await screen.findByText('Posted')).toBeInTheDocument()
  expect(screen.getByText('Voided')).toBeInTheDocument()
  expect(screen.getByText('Duplicate entry.')).toBeInTheDocument()
  expect(screen.getAllByRole('button', { name: /Void/ })).toHaveLength(1)
})

test('downloads the customer-facing PDF from Billing detail', async () => {
  const createObjectURL = vi.fn(() => 'blob:billing-pdf')
  const revokeObjectURL = vi.fn()
  const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined)
  class MockUrl extends URL {
    static createObjectURL = createObjectURL
    static revokeObjectURL = revokeObjectURL
  }
  vi.stubGlobal('URL', MockUrl)
  const fetchMock = renderRoute('/billings/41', financialFetch((url) => {
    if (url.endsWith('/api/v1/billings/41/pdf')) {
      return new Response('%PDF-billing', { headers: { 'Content-Type': 'application/pdf' } })
    }
  }))

  fireEvent.click(await screen.findByRole('button', { name: 'Download PDF' }))

  await waitFor(() => expect(fetchMock.mock.calls.some(([input]) => String(input).endsWith('/api/v1/billings/41/pdf'))).toBe(true))
  expect(createObjectURL).toHaveBeenCalledOnce()
  expect(click).toHaveBeenCalledOnce()
  expect(click.mock.instances[0]).toHaveAttribute('download', 'INV-2026-000001.pdf')
  expect(revokeObjectURL).toHaveBeenCalledWith('blob:billing-pdf')
})

test('Void dialog requires a reason and refreshes the derived balance', async () => {
  let billingState = billing
  let paymentsState: Record<string, unknown>[] = [postedPayment, voidedPayment]
  let payload: Record<string, unknown> | undefined
  const voidedResultPayment = { ...postedPayment, status: 'VOIDED', voided_at: '2026-09-15T02:00:00.000000Z', void_reason: 'Bank reversal.', voided_by: { id: 7, name: 'Admin' } }
  const result = { payment: voidedResultPayment, billing: postedPayment.billing, payment_summary: { amount_paid: '0.00', remaining_balance: '8125.25', payment_status: 'UNPAID' }, booking: postedPayment.booking }
  renderRoute('/billings/41', financialFetch((url, init) => {
    if (url.endsWith('/api/v1/billings/41')) return response(billingState)
    if (url.includes('/api/v1/billings/41/payments?')) return response(page(paymentsState))
    if (url.endsWith('/api/v1/payments/61/void') && init?.method === 'POST') {
      payload = JSON.parse(String(init.body)) as Record<string, unknown>
      billingState = { ...billing, payment_summary: result.payment_summary }
      paymentsState = [voidedResultPayment, voidedPayment]
      return response(result)
    }
  }))

  fireEvent.click((await screen.findAllByRole('button', { name: /Void/ }))[0])
  const dialog = screen.getByRole('dialog', { name: 'Void Payment' })
  fireEvent.click(within(dialog).getByRole('button', { name: 'Void Payment' }))
  expect(await within(dialog).findByText(/Enter a reason/)).toBeInTheDocument()
  fireEvent.change(within(dialog).getByLabelText('Void reason'), { target: { value: 'Bank reversal.' } })
  fireEvent.click(within(dialog).getByRole('button', { name: 'Void Payment' }))

  expect(await screen.findByText('₱2,000.10 payment voided. Billing balance updated.')).toBeInTheDocument()
  expect(payload).toEqual({ void_reason: 'Bank reversal.' })
  await waitFor(() => expect(screen.getAllByText('Unpaid').length).toBeGreaterThan(0))
  await waitFor(() => expect(screen.queryByRole('button', { name: /Void/ })).not.toBeInTheDocument())
})

test('Payments list sends operational search and filters to the API', async () => {
  const fetchMock = renderRoute('/payments')
  await screen.findByText('GCASH-001')

  fireEvent.change(screen.getByLabelText('Status'), { target: { value: 'VOIDED' } })
  fireEvent.change(screen.getByLabelText('Method'), { target: { value: 'GCASH' } })
  fireEvent.change(screen.getByLabelText('Paid from'), { target: { value: '2026-09-01' } })
  fireEvent.change(screen.getByLabelText('Paid to'), { target: { value: '2026-09-15' } })
  fireEvent.change(screen.getByLabelText('Search payments'), { target: { value: 'GCASH-001' } })
  fireEvent.click(screen.getByRole('button', { name: 'Search' }))

  await waitFor(() => expect(fetchMock.mock.calls.some(([input]) => {
    const url = String(input)
    return url.includes('/api/v1/payments?') && url.includes('status=VOIDED') && url.includes('payment_method=GCASH') && url.includes('paid_from=2026-09-01') && url.includes('paid_to=2026-09-15') && url.includes('search=GCASH-001')
  })).toBe(true))
  expect((await screen.findAllByRole('link', { name: 'INV-2026-000001' }))[0]).toHaveAttribute('href', '/billings/41')
  expect(screen.getAllByText('Voided').length).toBeGreaterThan(0)
})
