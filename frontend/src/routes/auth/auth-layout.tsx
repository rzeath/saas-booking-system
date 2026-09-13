import type { PropsWithChildren, ReactNode } from 'react'
import { Link } from 'react-router-dom'

type AuthLayoutProps = PropsWithChildren<{ title: string; description: string; alternate: ReactNode }>

export function AuthLayout({ title, description, alternate, children }: AuthLayoutProps) {
  return (
    <main className="grid min-h-screen place-items-center bg-slate-950 px-6 py-12 text-slate-100">
      <section className="w-full max-w-md rounded-2xl border border-slate-800 bg-slate-900 p-8 shadow-2xl shadow-black/20">
        <Link to="/" className="text-sm font-semibold tracking-[0.08em] text-cyan-400">TakdaOps</Link>
        <p className="mt-1 text-xs uppercase tracking-[0.18em] text-slate-500">Event Booking Management</p>
        <h1 className="mt-5 text-3xl font-semibold tracking-tight">{title}</h1>
        <p className="mt-2 text-sm leading-6 text-slate-400">{description}</p>
        <div className="mt-7">{children}</div>
        <p className="mt-6 text-center text-sm text-slate-400">{alternate}</p>
        <p className="mt-4 text-center text-xs text-slate-600">by Rzeath</p>
      </section>
    </main>
  )
}
