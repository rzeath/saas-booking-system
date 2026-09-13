import { Link, useOutletContext } from 'react-router-dom'

import type { AuthContext } from '@/lib/api'

export function HomeRoute() {
  const auth = useOutletContext<AuthContext>()

  return (
    <section className="mx-auto w-full max-w-3xl rounded-2xl border border-slate-800 bg-slate-900 p-8 shadow-2xl shadow-black/20">
      <p className="text-sm font-semibold tracking-[0.08em] text-cyan-400">Event Booking Management</p>
      <h1 className="mt-2 text-3xl font-semibold tracking-tight">Overview</h1>
      <p className="mt-3 text-slate-400">Manage your tenant settings and master data.</p>

      <dl className="mt-8 divide-y divide-slate-800 rounded-xl border border-slate-800 bg-slate-950/60">
        <div className="p-4"><dt className="text-xs uppercase tracking-wide text-slate-500">Business Name</dt><dd className="mt-1 font-medium">{auth.organization.name}</dd></div>
        <div className="p-4"><dt className="text-xs uppercase tracking-wide text-slate-500">Admin Name</dt><dd className="mt-1 font-medium">{auth.user.name}</dd></div>
        <div className="p-4"><dt className="text-xs uppercase tracking-wide text-slate-500">Admin Email</dt><dd className="mt-1 font-medium">{auth.user.email}</dd></div>
      </dl>

      <div className="mt-6 flex flex-wrap gap-3">
        <Link to="/customers" className="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-400">Manage customers</Link>
        <Link to="/event-types" className="rounded-lg border border-slate-700 px-4 py-2 text-sm font-medium hover:bg-slate-800">Manage event types</Link>
      </div>
      <p className="mt-6 text-xs text-slate-600">by Rzeath</p>
    </section>
  )
}
