import { BookingForm } from '@/components/bookings/booking-form'

export function BookingNewRoute() {
  return (
    <section>
      <p className="text-sm font-semibold tracking-[0.08em] text-cyan-400">Bookings</p>
      <h1 className="mt-2 text-3xl font-semibold tracking-tight">Create booking</h1>
      <p className="mt-2 text-sm text-slate-400">Add the event details and one or more scheduled services.</p>
      <BookingForm />
    </section>
  )
}
