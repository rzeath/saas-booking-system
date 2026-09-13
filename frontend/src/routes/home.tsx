import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { LogOut } from 'lucide-react'
import { Link, useNavigate } from 'react-router-dom'

import { getCurrentAuth, logout } from '@/lib/api'
import { authQueryKey } from '@/lib/auth-query'

export function HomeRoute() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const authQuery = useQuery({ queryKey: authQueryKey, queryFn: getCurrentAuth })
  const logoutMutation = useMutation({
    mutationFn: logout,
    onSuccess: () => {
      queryClient.setQueryData(authQueryKey, null)
      navigate('/login', { replace: true })
    },
  })

  if (!authQuery.data) return null

  return (
    <main className="grid min-h-screen place-items-center bg-slate-950 px-6 text-slate-100">
      <section className="w-full max-w-xl rounded-2xl border border-slate-800 bg-slate-900 p-8 shadow-2xl shadow-black/20">
        <p className="text-sm font-semibold tracking-[0.08em] text-cyan-400">TakdaOps</p>
        <p className="mt-1 text-xs uppercase tracking-[0.18em] text-slate-500">Event Booking Management</p>
        <h1 className="mt-5 text-3xl font-semibold tracking-tight">{authQuery.data.organization.name}</h1>
        <p className="mt-3 text-slate-400">Your authentication and organization foundation is ready.</p>

        <dl className="mt-8 divide-y divide-slate-800 rounded-xl border border-slate-800 bg-slate-950/60">
          <div className="p-4"><dt className="text-xs uppercase tracking-wide text-slate-500">Business Name</dt><dd className="mt-1 font-medium">{authQuery.data.organization.name}</dd></div>
          <div className="p-4"><dt className="text-xs uppercase tracking-wide text-slate-500">Admin Name</dt><dd className="mt-1 font-medium">{authQuery.data.user.name}</dd></div>
          <div className="p-4"><dt className="text-xs uppercase tracking-wide text-slate-500">Admin Email</dt><dd className="mt-1 font-medium">{authQuery.data.user.email}</dd></div>
        </dl>

        <Link to="/settings/business" className="mt-6 inline-flex rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-400">Business settings</Link>

        {logoutMutation.isError ? <p role="alert" className="mt-4 text-sm text-rose-400">Logout failed. Please try again.</p> : null}
        <button
          type="button"
          disabled={logoutMutation.isPending}
          onClick={() => logoutMutation.mutate()}
          className="mt-6 inline-flex items-center gap-2 rounded-lg border border-slate-700 px-4 py-2 text-sm font-medium hover:bg-slate-800 disabled:opacity-60"
        >
          <LogOut className="size-4" aria-hidden="true" />
          {logoutMutation.isPending ? 'Signing out…' : 'Sign out'}
        </button>
        <p className="mt-6 text-xs text-slate-600">by Rzeath</p>
      </section>
    </main>
  )
}
