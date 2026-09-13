import { useQuery } from '@tanstack/react-query'
import { CircleCheck, CircleX, LoaderCircle } from 'lucide-react'

import { getApiHealth } from '@/lib/api'

export function HomeRoute() {
  const healthQuery = useQuery({
    queryKey: ['api-health'],
    queryFn: getApiHealth,
  })

  return (
    <main className="grid min-h-screen place-items-center bg-slate-950 px-6 text-slate-100">
      <section className="w-full max-w-xl rounded-2xl border border-slate-800 bg-slate-900 p-8 shadow-2xl shadow-black/20">
        <p className="text-sm font-medium uppercase tracking-[0.2em] text-cyan-400">
          Development foundation
        </p>
        <h1 className="mt-3 text-3xl font-semibold tracking-tight">
          Event Booking Management
        </h1>
        <p className="mt-3 text-slate-400">
          React is running. This screen verifies connectivity to the Laravel API.
        </p>

        <div
          className="mt-8 flex items-center gap-3 rounded-xl border border-slate-700 bg-slate-950/70 p-4"
          role="status"
        >
          {healthQuery.isPending ? (
            <>
              <LoaderCircle className="size-5 animate-spin text-cyan-400" aria-hidden="true" />
              <span>Checking API connection…</span>
            </>
          ) : healthQuery.isSuccess ? (
            <>
              <CircleCheck className="size-5 text-emerald-400" aria-hidden="true" />
              <span>API connected</span>
            </>
          ) : (
            <>
              <CircleX className="size-5 text-rose-400" aria-hidden="true" />
              <span>API unavailable</span>
            </>
          )}
        </div>
      </section>
    </main>
  )
}
