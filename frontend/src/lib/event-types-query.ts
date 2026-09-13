import type { MasterDataQuery } from '@/lib/api'

export const eventTypesQueryKey = ['event-types'] as const

export function eventTypeListQueryKey(query: MasterDataQuery) {
  return [...eventTypesQueryKey, query] as const
}
