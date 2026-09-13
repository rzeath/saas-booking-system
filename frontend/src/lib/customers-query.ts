import type { MasterDataQuery } from '@/lib/api'

export const customersQueryKey = ['customers'] as const

export function customerListQueryKey(query: MasterDataQuery) {
  return [...customersQueryKey, query] as const
}
