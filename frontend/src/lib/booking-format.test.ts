import { describe, expect, test } from 'vitest'

import { formatMoney } from '@/lib/booking-format'

describe('formatMoney', () => {
  test('always formats monetary values as Philippine Peso', () => {
    expect(formatMoney('10000')).toBe('₱10,000.00')
    expect(formatMoney('1234.5')).toBe('₱1,234.50')
    expect(formatMoney('0.01')).toBe('₱0.01')
  })
})
