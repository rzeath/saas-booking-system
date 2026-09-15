import type { BillingQuery, PaymentQuery } from '@/lib/api'

export const billingsQueryKey = ['billings'] as const
export const billingListsQueryKey = [...billingsQueryKey, 'list'] as const

export function billingListQueryKey(query: BillingQuery) {
  return [...billingListsQueryKey, query] as const
}

export function billingDetailQueryKey(id: number) {
  return [...billingsQueryKey, 'detail', id] as const
}

export function billingPaymentsQueryKey(id: number, query: PaymentQuery) {
  return [...billingsQueryKey, 'detail', id, 'payments', query] as const
}

export function billingPaymentListsQueryKey(id: number) {
  return [...billingsQueryKey, 'detail', id, 'payments'] as const
}
