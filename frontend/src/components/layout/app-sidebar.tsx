import {
  CalendarDays,
  Database,
  FileText,
  Gauge,
  LogOut,
  Menu,
  Settings,
  Users,
  X,
} from 'lucide-react'
import { type KeyboardEvent, type RefObject } from 'react'
import { NavLink } from 'react-router-dom'

import { Button } from '@/components/ui/button'
import type { AuthContext, BusinessSetting } from '@/lib/api'
import { businessInitials } from '@/lib/business-branding'
import { cn } from '@/lib/utils'

const navigation = [
  { label: 'Overview', items: [{ to: '/', label: 'Dashboard', icon: Gauge, end: true }] },
  {
    label: 'Operations',
    items: [
      { to: '/bookings', label: 'Bookings', icon: CalendarDays, end: false },
      { to: '/quotations', label: 'Quotations', icon: FileText, end: false },
    ],
  },
  {
    label: 'People',
    items: [{ to: '/customers', label: 'Customers', icon: Users, end: false }],
  },
  {
    label: 'Configuration',
    items: [
      { to: '/master-data', label: 'Master Data', icon: Database, end: false },
      { to: '/settings/business', label: 'Business Settings', icon: Settings, end: false },
    ],
  },
] as const

type SidebarContentProps = {
  auth: AuthContext
  branding: Pick<BusinessSetting, 'display_name' | 'logo_url' | 'theme_accent'>
  logoutPending: boolean
  logoutError: boolean
  onLogout: () => void
  onNavigate?: () => void
  onClose?: () => void
  closeButtonRef?: RefObject<HTMLButtonElement | null>
}

function BusinessMark({ branding, compact = false }: { branding: SidebarContentProps['branding']; compact?: boolean }) {
  const size = compact ? 'size-9' : 'size-10'

  return branding.logo_url ? (
    <img src={branding.logo_url} alt={`${branding.display_name} logo`} className={`${size} shrink-0 rounded-lg border border-border bg-surface object-contain`} />
  ) : (
    <span className={`grid ${size} shrink-0 place-items-center rounded-lg bg-primary-soft text-xs font-bold text-primary`} aria-label={`${businessInitials(branding.display_name)} business initials`}>
      {businessInitials(branding.display_name)}
    </span>
  )
}

function SidebarContent({ auth, branding, logoutPending, logoutError, onLogout, onNavigate, onClose, closeButtonRef }: SidebarContentProps) {
  return (
    <div className="flex h-full flex-col bg-surface">
      <div className="flex min-h-20 items-center gap-3 border-b border-border px-5">
        <BusinessMark branding={branding} />
        <div className="min-w-0 flex-1">
          <NavLink to="/" onClick={onNavigate} className="block truncate text-base font-bold text-foreground">{branding.display_name}</NavLink>
          <p className="truncate text-[10px] font-medium text-muted">Powered by TakdaOps</p>
        </div>
        {onClose ? (
          <Button ref={closeButtonRef} variant="ghost" size="icon" onClick={onClose} aria-label="Close navigation" className="lg:hidden">
            <X className="size-5" aria-hidden="true" />
          </Button>
        ) : null}
      </div>

      <nav aria-label="Primary navigation" className="flex-1 overflow-y-auto px-3 py-5">
        {navigation.map((group, index) => (
          <div key={group.label} className={cn(index > 0 && 'mt-5')}>
            <p className="px-3 text-[10px] font-bold uppercase tracking-[0.14em] text-muted">{group.label}</p>
            <div className="mt-1.5 space-y-1">
              {group.items.map((item) => {
                const Icon = item.icon
                return (
                  <NavLink
                    key={item.to}
                    to={item.to}
                    end={item.end}
                    onClick={onNavigate}
                    className={({ isActive }) => cn(
                      'flex min-h-10 items-center gap-3 rounded-lg px-3 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                      isActive
                        ? 'bg-primary-soft text-primary'
                        : 'text-muted hover:bg-surface-subtle hover:text-foreground',
                    )}
                  >
                    <Icon className="size-[18px] shrink-0" aria-hidden="true" />
                    {item.label}
                  </NavLink>
                )
              })}
            </div>
          </div>
        ))}
      </nav>

      <div className="border-t border-border p-3">
        <div className="rounded-xl bg-surface-subtle p-3">
          <div className="flex items-center gap-3">
            <span className="grid size-9 shrink-0 place-items-center rounded-full bg-primary-soft text-xs font-bold text-primary" aria-hidden="true">
              {businessInitials(auth.user.name)}
            </span>
            <div className="min-w-0 flex-1">
              <p className="truncate text-sm font-semibold text-foreground">{auth.user.name}</p>
              <p className="truncate text-xs text-muted">{auth.user.email}</p>
            </div>
            <Button variant="ghost" size="icon" disabled={logoutPending} onClick={onLogout} aria-label={logoutPending ? 'Signing out' : 'Sign out'} title="Sign out" className="size-9 shrink-0">
              <LogOut className="size-4" aria-hidden="true" />
            </Button>
          </div>
          {logoutError ? <p role="alert" className="mt-2 text-xs text-danger">Sign out failed. Please try again.</p> : null}
        </div>
      </div>
    </div>
  )
}

export type AppSidebarProps = Omit<SidebarContentProps, 'onNavigate' | 'onClose' | 'closeButtonRef'> & {
  mobileOpen: boolean
  onMobileOpen: () => void
  onMobileClose: () => void
  menuButtonRef: RefObject<HTMLButtonElement | null>
  closeButtonRef: RefObject<HTMLButtonElement | null>
}

export function AppSidebar({ auth, mobileOpen, onMobileOpen, onMobileClose, menuButtonRef, closeButtonRef, ...accountProps }: AppSidebarProps) {
  const trapDrawerFocus = (event: KeyboardEvent<HTMLElement>) => {
    if (event.key !== 'Tab') return
    const focusable = [...event.currentTarget.querySelectorAll<HTMLElement>('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')]
    const first = focusable[0]
    const last = focusable[focusable.length - 1]
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault()
      last?.focus()
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault()
      first?.focus()
    }
  }

  return (
    <>
      <header className="sticky top-0 z-30 flex min-h-16 items-center justify-between border-b border-border bg-surface/95 px-4 backdrop-blur lg:hidden">
        <div className="flex items-center gap-2.5">
          <BusinessMark branding={accountProps.branding} compact />
          <div><p className="max-w-48 truncate text-sm font-bold text-foreground">{accountProps.branding.display_name}</p><p className="text-[10px] text-muted">Powered by TakdaOps</p></div>
        </div>
        <Button ref={menuButtonRef} variant="ghost" size="icon" onClick={onMobileOpen} aria-label="Open navigation" aria-expanded={mobileOpen} aria-controls="mobile-sidebar">
          <Menu className="size-5" aria-hidden="true" />
        </Button>
      </header>

      <aside className="fixed inset-y-0 left-0 z-30 hidden w-64 border-r border-border lg:block">
        <SidebarContent auth={auth} {...accountProps} />
      </aside>

      {mobileOpen ? (
        <div className="fixed inset-0 z-40 lg:hidden">
          <button type="button" aria-label="Close navigation overlay" onClick={onMobileClose} className="absolute inset-0 bg-slate-950/40" />
          <aside id="mobile-sidebar" aria-label="Mobile navigation" onKeyDown={trapDrawerFocus} className="absolute inset-y-0 left-0 w-[min(20rem,88vw)] border-r border-border shadow-2xl">
            <SidebarContent auth={auth} {...accountProps} onNavigate={onMobileClose} onClose={onMobileClose} closeButtonRef={closeButtonRef} />
          </aside>
        </div>
      ) : null}
    </>
  )
}
