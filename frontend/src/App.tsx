import { Route, Routes } from 'react-router-dom'

import { GuestRoute, ProtectedRoute } from '@/components/auth/auth-guards'
import { AuthenticatedLayout } from '@/components/layout/authenticated-layout'
import { LoginRoute } from '@/routes/auth/login'
import { RegisterRoute } from '@/routes/auth/register'
import { BusinessSettingsRoute } from '@/routes/business-settings'
import { BookingDetailRoute } from '@/routes/booking-detail'
import { BookingEditRoute } from '@/routes/booking-edit'
import { BookingNewRoute } from '@/routes/booking-new'
import { BookingsRoute } from '@/routes/bookings'
import { CustomersRoute } from '@/routes/customers'
import { EventTypesRoute } from '@/routes/event-types'
import { HomeRoute } from '@/routes/home'
import { ServiceRatesRoute } from '@/routes/service-rates'
import { ServicesRoute } from '@/routes/services'
import { StaffRoute } from '@/routes/staff'

export default function App() {
  return (
    <Routes>
      <Route element={<ProtectedRoute><AuthenticatedLayout /></ProtectedRoute>}>
        <Route path="/" element={<HomeRoute />} />
        <Route path="/customers" element={<CustomersRoute />} />
        <Route path="/event-types" element={<EventTypesRoute />} />
        <Route path="/services" element={<ServicesRoute />} />
        <Route path="/service-rates" element={<ServiceRatesRoute />} />
        <Route path="/staff" element={<StaffRoute />} />
        <Route path="/bookings" element={<BookingsRoute />} />
        <Route path="/bookings/new" element={<BookingNewRoute />} />
        <Route path="/bookings/:bookingId" element={<BookingDetailRoute />} />
        <Route path="/bookings/:bookingId/edit" element={<BookingEditRoute />} />
        <Route path="/settings/business" element={<BusinessSettingsRoute />} />
      </Route>
      <Route path="/login" element={<GuestRoute><LoginRoute /></GuestRoute>} />
      <Route path="/register" element={<GuestRoute><RegisterRoute /></GuestRoute>} />
    </Routes>
  )
}
