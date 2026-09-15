import type { Quotation } from '@/lib/api'
import { formatMoney } from '@/lib/booking-format'

export function QuotationCommercialSummary({ quotation }: { quotation: Quotation }) {
  const rows = [
    ['Subtotal', quotation.subtotal],
    ['Transportation fee', quotation.transportation_fee],
    ['Crew meal fee', quotation.crew_meal_fee],
    ['Discount', quotation.discount_amount],
  ] as const

  return (
    <dl>
      {rows.map(([label, value]) => (
        <div key={label} className="flex items-center justify-between gap-6 border-b border-border py-3 text-sm">
          <dt className="text-muted">{label}</dt>
          <dd className="font-medium tabular-nums">{label === 'Discount' && value !== '0.00' ? '-' : ''}{formatMoney(value)}</dd>
        </div>
      ))}
      <div className="flex items-center justify-between gap-6 pt-4">
        <dt className="font-semibold">Total</dt>
        <dd className="text-xl font-bold tabular-nums">{formatMoney(quotation.total)}</dd>
      </div>
    </dl>
  )
}
