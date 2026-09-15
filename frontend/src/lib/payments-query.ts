import type { PaymentQuery } from '@/lib/api'

export const paymentsQueryKey = ['payments'] as const
export const paymentListsQueryKey = [...paymentsQueryKey, 'list'] as const

export function paymentListQueryKey(query: PaymentQuery) {
  return [...paymentListsQueryKey, query] as const
}
