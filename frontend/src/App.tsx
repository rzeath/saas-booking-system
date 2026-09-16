import { lazy, Suspense } from 'react'
import { Navigate, Route, Routes } from 'react-router-dom'

import { GuestRoute, ProtectedRoute } from '@/components/auth/auth-guards'
import { LoadingState } from '@/components/data/query-state'
import { AuthenticatedLayout } from '@/components/layout/authenticated-layout'
import { LoginRoute } from '@/routes/auth/login'
import { RegisterRoute } from '@/routes/auth/register'
import { BusinessSettingsRoute } from '@/routes/business-settings'
import { BillingDetailRoute } from '@/routes/billing-detail'
import { BillingsRoute } from '@/routes/billings'
import { BookingDetailRoute } from '@/routes/booking-detail'
import { BookingEditRoute } from '@/routes/booking-edit'
import { BookingNewRoute } from '@/routes/booking-new'
import { BookingsRoute } from '@/routes/bookings'
import { CustomersRoute } from '@/routes/customers'
import { HomeRoute } from '@/routes/home'
import { MasterDataRoute } from '@/routes/master-data'
import { PaymentsRoute } from '@/routes/payments'
import { QuotationDetailRoute } from '@/routes/quotation-detail'
import { QuotationNewRoute } from '@/routes/quotation-new'
import { QuotationsRoute } from '@/routes/quotations'

const CalendarRoute = lazy(async () => {
  const route = await import('@/routes/calendar')
  return { default: route.CalendarRoute }
})

export default function App() {
  return (
    <Routes>
      <Route element={<ProtectedRoute><AuthenticatedLayout /></ProtectedRoute>}>
        <Route path="/" element={<HomeRoute />} />
        <Route path="/customers" element={<CustomersRoute />} />
        <Route path="/master-data" element={<MasterDataRoute />} />
        <Route path="/event-types" element={<Navigate to="/master-data?tab=event-types" replace />} />
        <Route path="/services" element={<Navigate to="/master-data?tab=services" replace />} />
        <Route path="/packages" element={<Navigate to="/master-data?tab=packages" replace />} />
        <Route path="/service-rates" element={<Navigate to="/master-data?tab=services" replace />} />
        <Route path="/staff" element={<Navigate to="/master-data?tab=staff" replace />} />
        <Route path="/bookings" element={<BookingsRoute />} />
        <Route path="/calendar" element={<Suspense fallback={<LoadingState label="Loading calendar..." />}><CalendarRoute /></Suspense>} />
        <Route path="/bookings/new" element={<BookingNewRoute />} />
        <Route path="/bookings/:bookingId" element={<BookingDetailRoute />} />
        <Route path="/bookings/:bookingId/edit" element={<BookingEditRoute />} />
        <Route path="/bookings/:bookingId/quotations/new" element={<QuotationNewRoute />} />
        <Route path="/quotations" element={<QuotationsRoute />} />
        <Route path="/quotations/:quotationId" element={<QuotationDetailRoute />} />
        <Route path="/billings" element={<BillingsRoute />} />
        <Route path="/billings/:billingId" element={<BillingDetailRoute />} />
        <Route path="/payments" element={<PaymentsRoute />} />
        <Route path="/settings/business" element={<BusinessSettingsRoute />} />
      </Route>
      <Route path="/login" element={<GuestRoute><LoginRoute /></GuestRoute>} />
      <Route path="/register" element={<GuestRoute><RegisterRoute /></GuestRoute>} />
    </Routes>
  )
}
