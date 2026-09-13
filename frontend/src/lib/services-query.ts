import type { MasterDataQuery } from '@/lib/api'

export const servicesQueryKey = ['services'] as const

export function serviceListQueryKey(query: MasterDataQuery) {
  return [...servicesQueryKey, query] as const
}
