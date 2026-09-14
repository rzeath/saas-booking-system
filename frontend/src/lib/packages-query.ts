import type { MasterDataQuery } from '@/lib/api'

export const packagesQueryKey = ['packages'] as const

export function packageListQueryKey(query: MasterDataQuery) {
  return [...packagesQueryKey, 'list', query] as const
}

export function servicePackageListQueryKey(serviceId: number, query: MasterDataQuery) {
  return [...packagesQueryKey, 'service', serviceId, query] as const
}
