import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Pencil, XCircle } from 'lucide-react'
import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'

import { TextAreaField } from '@/components/forms/form-field'
import { Modal } from '@/components/ui/modal'
import { cancelBooking, getBooking } from '@/lib/api'
import { bookingStatusLabel, durationLabel, formatMoney, sumMoney } from '@/lib/booking-format'
import { bookingDetailQueryKey, bookingListsQueryKey } from '@/lib/bookings-query'
import { businessSettingsQueryKey } from '@/lib/business-settings-query'
import { getBusinessSettings } from '@/lib/api'

function Detail({ label, value }: { label: string; value: string | null }) {
  return <div><dt className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</dt><dd className="mt-1 text-sm text-slate-200">{value || '—'}</dd></div>
}

export function BookingDetailRoute() {
  const bookingId = Number(useParams().bookingId)
  const queryClient = useQueryClient()
  const [cancelling, setCancelling] = useState(false)
  const [reason, setReason] = useState('')
  const [message, setMessage] = useState<string>()
  const booking = useQuery({ queryKey: bookingDetailQueryKey(bookingId), queryFn: () => getBooking(bookingId), enabled: Number.isInteger(bookingId) && bookingId > 0 })
  const settings = useQuery({ queryKey: businessSettingsQueryKey, queryFn: getBusinessSettings })
  const mutation = useMutation({
    mutationFn: () => cancelBooking(bookingId, reason.trim() || null),
    onSuccess: (cancelled) => {
      queryClient.setQueryData(bookingDetailQueryKey(bookingId), cancelled)
      void queryClient.invalidateQueries({ queryKey: bookingListsQueryKey })
      setCancelling(false)
      setMessage('Booking cancelled. Historical details were preserved.')
    },
    onError: (error) => setMessage(error instanceof Error ? error.message : 'Unable to cancel this booking.'),
  })

  if (!Number.isInteger(bookingId) || bookingId < 1) return <p role="alert" className="text-rose-300">Invalid booking.</p>
  if (booking.isPending) return <p role="status" className="text-slate-400">Loading booking…</p>
  if (booking.isError) return <div><p role="alert" className="text-rose-300">We could not load this booking.</p><button type="button" onClick={() => { void booking.refetch() }} className="mt-4 rounded-lg border border-slate-700 px-4 py-2 text-sm">Try again</button></div>

  const item = booking.data
  const currency = settings.data?.currency ?? 'PHP'
  const total = sumMoney(item.booking_services.map((line) => line.line_total))

  return (
    <section>
      <div className="flex flex-wrap items-start justify-between gap-4"><div><Link to="/bookings" className="text-sm text-cyan-300 hover:text-cyan-200">← Back to bookings</Link><div className="mt-4 flex flex-wrap items-center gap-3"><h1 className="text-3xl font-semibold tracking-tight">{item.booking_number}</h1><span className="rounded-full border border-slate-700 px-3 py-1 text-xs font-semibold uppercase tracking-wide">{bookingStatusLabel(item.status)}</span></div><p className="mt-2 text-slate-400">{item.event_name}</p></div>{item.status === 'PENDING' ? <div className="flex gap-2"><Link to={`/bookings/${item.id}/edit`} className="inline-flex items-center gap-2 rounded-lg border border-slate-700 px-4 py-2 text-sm"><Pencil className="size-4" aria-hidden="true" /> Edit</Link><button type="button" onClick={() => { setReason(''); setCancelling(true); setMessage(undefined) }} className="inline-flex items-center gap-2 rounded-lg border border-rose-900 px-4 py-2 text-sm text-rose-300"><XCircle className="size-4" aria-hidden="true" /> Cancel booking</button></div> : null}</div>
      {message ? <p role="status" className={`mt-5 rounded-lg border p-4 text-sm ${mutation.isError ? 'border-rose-900 text-rose-300' : 'border-emerald-900 text-emerald-300'}`}>{message}</p> : null}

      <div className="mt-8 grid gap-6 xl:grid-cols-2">
        <div className="rounded-2xl border border-slate-800 bg-slate-900 p-6"><h2 className="text-lg font-semibold">Event</h2><dl className="mt-5 grid gap-5 sm:grid-cols-2"><Detail label="Event date" value={item.event_date} /><Detail label="Timezone" value={item.timezone} /><Detail label="Event type snapshot" value={item.event_type_snapshot.name} /><Detail label="Venue" value={item.venue_name} /><div className="sm:col-span-2"><Detail label="Venue address" value={item.venue_address} /></div><Detail label="Contact person" value={item.contact_person} /><Detail label="Contact number" value={item.contact_number} /></dl></div>
        <div className="rounded-2xl border border-slate-800 bg-slate-900 p-6"><h2 className="text-lg font-semibold">Customer snapshot</h2><p className="mt-1 text-xs text-slate-500">Preserved from booking creation or its last permitted edit.</p><dl className="mt-5 grid gap-5 sm:grid-cols-2"><Detail label="Name" value={item.customer_snapshot.name} /><Detail label="Email" value={item.customer_snapshot.email} /><Detail label="Phone" value={item.customer_snapshot.phone} /><Detail label="Address" value={item.customer_snapshot.address} /></dl></div>
      </div>

      <div className="mt-6 rounded-2xl border border-slate-800 bg-slate-900"><div className="border-b border-slate-800 p-6"><h2 className="text-lg font-semibold">Scheduled services</h2><p className="mt-1 text-sm text-slate-400">Saved service, package, schedule, and price snapshots.</p></div><div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead className="border-b border-slate-800 text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Service / package</th><th className="px-5 py-3">Local schedule</th><th className="px-5 py-3">Duration</th><th className="px-5 py-3">Quantity</th><th className="px-5 py-3">Unit rate</th><th className="px-5 py-3 text-right">Line total</th></tr></thead><tbody className="divide-y divide-slate-800">{item.booking_services.map((line) => <tr key={line.id}><td className="px-5 py-4"><strong className="block">{line.service.name}</strong><span className="text-slate-400">{line.package.name}</span></td><td className="px-5 py-4"><span className="block">{line.local_start}</span><span className="text-slate-500">to {line.local_end}</span></td><td className="px-5 py-4">{durationLabel(line.duration_minutes)}</td><td className="px-5 py-4">{line.quantity}</td><td className="px-5 py-4">{formatMoney(line.unit_rate, currency)}</td><td className="px-5 py-4 text-right font-medium">{formatMoney(line.line_total, currency)}</td></tr>)}</tbody><tfoot className="border-t border-slate-700"><tr><th colSpan={5} className="px-5 py-4 text-right">Services total</th><td className="px-5 py-4 text-right text-lg font-semibold">{formatMoney(total, currency)}</td></tr></tfoot></table></div></div>
      <div className="mt-6 rounded-2xl border border-slate-800 bg-slate-900 p-6"><h2 className="text-lg font-semibold">Internal notes</h2><p className="mt-3 whitespace-pre-wrap text-sm text-slate-300">{item.internal_notes || 'No internal notes.'}</p>{item.status === 'CANCELLED' ? <div className="mt-5 border-t border-slate-800 pt-5"><Detail label="Cancellation reason" value={item.cancellation_reason} /></div> : null}</div>

      {cancelling ? <Modal title={`Cancel ${item.booking_number}?`} onClose={() => { if (!mutation.isPending) setCancelling(false) }}><p className="text-sm text-slate-300">This releases reserved service capacity. The booking and all historical details remain available.</p><div className="mt-5"><TextAreaField label="Reason (optional)" id="booking-cancellation-reason" value={reason} onChange={(event) => setReason(event.target.value)} maxLength={1000} /></div><div className="mt-6 flex justify-end gap-3"><button type="button" disabled={mutation.isPending} onClick={() => setCancelling(false)} className="rounded-lg border border-slate-700 px-4 py-2 text-sm">Keep booking</button><button type="button" disabled={mutation.isPending} onClick={() => mutation.mutate()} className="rounded-lg bg-rose-500 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">{mutation.isPending ? 'Cancelling…' : 'Confirm cancellation'}</button></div></Modal> : null}
    </section>
  )
}
