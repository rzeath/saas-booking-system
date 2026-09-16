import { BookingForm } from '@/components/bookings/booking-form'
import { Page, PageHeader } from '@/components/layout/page'

export function BookingNewRoute() {
  return (
    <Page>
      <PageHeader title="New Booking" description="Create and schedule an event booking." />
      <BookingForm />
    </Page>
  )
}
