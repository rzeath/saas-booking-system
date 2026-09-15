import type { QuotationQuery } from '@/lib/api'

export const quotationsQueryKey = ['quotations'] as const
export const quotationListsQueryKey = [...quotationsQueryKey, 'list'] as const

export function quotationListQueryKey(query: QuotationQuery) {
  return [...quotationListsQueryKey, query] as const
}

export function quotationDetailQueryKey(id: number) {
  return [...quotationsQueryKey, 'detail', id] as const
}
