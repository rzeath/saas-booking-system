import type { ServiceRateQuery } from '@/lib/api'

export const serviceRatesQueryKey = ['service-rates'] as const

export function serviceRateListQueryKey(query: ServiceRateQuery) {
  return [...serviceRatesQueryKey, query] as const
}
