import type { MasterDataQuery } from '@/lib/api'

export const packagesQueryKey = ['packages'] as const

export function packageListQueryKey(serviceId: number, query: MasterDataQuery) {
  return [...packagesQueryKey, serviceId, query] as const
}
