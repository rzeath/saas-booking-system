import { Route, Routes } from 'react-router-dom'

import { GuestRoute, ProtectedRoute } from '@/components/auth/auth-guards'
import { LoginRoute } from '@/routes/auth/login'
import { RegisterRoute } from '@/routes/auth/register'
import { BusinessSettingsRoute } from '@/routes/business-settings'
import { HomeRoute } from '@/routes/home'

export default function App() {
  return (
    <Routes>
      <Route path="/" element={<ProtectedRoute><HomeRoute /></ProtectedRoute>} />
      <Route path="/settings/business" element={<ProtectedRoute><BusinessSettingsRoute /></ProtectedRoute>} />
      <Route path="/login" element={<GuestRoute><LoginRoute /></GuestRoute>} />
      <Route path="/register" element={<GuestRoute><RegisterRoute /></GuestRoute>} />
    </Routes>
  )
}
