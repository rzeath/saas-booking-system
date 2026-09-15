import { durationLabel, formatMoney } from '@/lib/booking-format'
import { formatManilaWallClock } from '@/lib/quotation-format'

export type QuotationDisplayItem = {
  id: number
  serviceName: string
  packageName: string
  startAt: string
  endAt: string
  durationMinutes: number
  quantity: number
  unitRate: string
  lineTotal: string
}

export function QuotationItemsTable({ items, currency }: { items: QuotationDisplayItem[]; currency: string }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full text-left text-sm">
        <thead className="border-b border-border text-xs uppercase text-muted">
          <tr><th className="px-5 py-3">Service / package</th><th className="px-5 py-3">Schedule</th><th className="px-5 py-3">Duration</th><th className="px-5 py-3">Quantity</th><th className="px-5 py-3">Unit rate</th><th className="px-5 py-3 text-right">Line total</th></tr>
        </thead>
        <tbody className="divide-y divide-border">
          {items.map((item) => (
            <tr key={item.id}>
              <td className="px-5 py-4"><strong className="block">{item.serviceName}</strong><span className="text-muted">{item.packageName}</span></td>
              <td className="px-5 py-4"><span className="block">{formatManilaWallClock(item.startAt)}</span><span className="text-muted">to {formatManilaWallClock(item.endAt)}</span></td>
              <td className="px-5 py-4">{durationLabel(item.durationMinutes)}</td>
              <td className="px-5 py-4">{item.quantity}</td>
              <td className="px-5 py-4 tabular-nums">{formatMoney(item.unitRate, currency)}</td>
              <td className="px-5 py-4 text-right font-semibold tabular-nums">{formatMoney(item.lineTotal, currency)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
