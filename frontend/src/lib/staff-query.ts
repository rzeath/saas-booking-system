import type { MasterDataQuery } from '@/lib/api'

export const staffQueryKey = ['staff'] as const

export function staffListQueryKey(query: MasterDataQuery) {
  return [...staffQueryKey, query] as const
}
