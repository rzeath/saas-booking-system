import { useQuery } from '@tanstack/react-query'
import type { PropsWithChildren } from 'react'
import { Navigate, useLocation } from 'react-router-dom'

import { getCurrentAuth } from '@/lib/api'
import { authQueryKey } from '@/lib/auth-query'

function AuthLoading() {
  return <main className="grid min-h-screen place-items-center bg-slate-950 text-slate-300"><p role="status">Checking your session…</p></main>
}

function AuthFailure({ retry }: { retry: () => void }) {
  return (
    <main className="grid min-h-screen place-items-center bg-slate-950 px-6 text-slate-100">
      <div className="text-center">
        <p role="alert" className="text-rose-300">We could not check your session.</p>
        <button type="button" onClick={retry} className="mt-4 rounded-lg border border-slate-700 px-4 py-2 text-sm hover:bg-slate-800">Try again</button>
      </div>
    </main>
  )
}

export function ProtectedRoute({ children }: PropsWithChildren) {
  const location = useLocation()
  const authQuery = useQuery({ queryKey: authQueryKey, queryFn: getCurrentAuth })

  if (authQuery.isPending) return <AuthLoading />
  if (authQuery.isError) return <AuthFailure retry={() => { void authQuery.refetch() }} />
  if (!authQuery.data) return <Navigate to="/login" replace state={{ from: location.pathname }} />
  return children
}

export function GuestRoute({ children }: PropsWithChildren) {
  const authQuery = useQuery({ queryKey: authQueryKey, queryFn: getCurrentAuth })

  if (authQuery.isPending) return <AuthLoading />
  if (authQuery.isError) return <AuthFailure retry={() => { void authQuery.refetch() }} />
  if (authQuery.data) return <Navigate to="/" replace />
  return children
}
