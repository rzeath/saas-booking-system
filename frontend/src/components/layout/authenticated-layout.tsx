import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { LogOut } from 'lucide-react'
import { NavLink, Outlet, useNavigate } from 'react-router-dom'

import { getCurrentAuth, logout } from '@/lib/api'
import { authQueryKey } from '@/lib/auth-query'
import { cn } from '@/lib/utils'

const navigation = [
  { to: '/', label: 'Overview', end: true },
  { to: '/customers', label: 'Customers', end: false },
  { to: '/event-types', label: 'Event Types', end: false },
  { to: '/settings/business', label: 'Business Settings', end: false },
]

export function AuthenticatedLayout() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const authQuery = useQuery({ queryKey: authQueryKey, queryFn: getCurrentAuth })
  const logoutMutation = useMutation({
    mutationFn: logout,
    onSuccess: () => {
      queryClient.clear()
      navigate('/login', { replace: true })
    },
  })

  if (!authQuery.data) return null

  return (
    <div className="min-h-screen bg-slate-950 text-slate-100">
      <header className="border-b border-slate-800 bg-slate-900/90">
        <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-6 py-4">
          <div>
            <NavLink to="/" className="text-lg font-semibold text-cyan-400">TakdaOps</NavLink>
            <p className="text-xs text-slate-500">{authQuery.data.organization.name}</p>
          </div>
          <nav aria-label="Primary navigation" className="flex flex-wrap items-center gap-1">
            {navigation.map((item) => (
              <NavLink
                key={item.to}
                to={item.to}
                end={item.end}
                className={({ isActive }) => cn(
                  'rounded-lg px-3 py-2 text-sm font-medium transition',
                  isActive ? 'bg-cyan-500 text-slate-950' : 'text-slate-300 hover:bg-slate-800 hover:text-white',
                )}
              >
                {item.label}
              </NavLink>
            ))}
          </nav>
          <button
            type="button"
            disabled={logoutMutation.isPending}
            onClick={() => logoutMutation.mutate()}
            className="inline-flex items-center gap-2 rounded-lg border border-slate-700 px-3 py-2 text-sm hover:bg-slate-800 disabled:opacity-60"
          >
            <LogOut className="size-4" aria-hidden="true" />
            {logoutMutation.isPending ? 'Signing out…' : 'Sign out'}
          </button>
        </div>
        {logoutMutation.isError ? <p role="alert" className="mx-auto max-w-7xl px-6 pb-3 text-sm text-rose-400">Logout failed. Please try again.</p> : null}
      </header>
      <main className="mx-auto w-full max-w-7xl px-6 py-10">
        <Outlet context={authQuery.data} />
      </main>
    </div>
  )
}
