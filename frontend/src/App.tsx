import { Route, Routes } from 'react-router-dom'

import { HomeRoute } from '@/routes/home'

export default function App() {
  return (
    <Routes>
      <Route path="/" element={<HomeRoute />} />
    </Routes>
  )
}
