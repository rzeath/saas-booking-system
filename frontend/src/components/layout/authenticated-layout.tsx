import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef, useState } from 'react'
import { Outlet, useNavigate } from 'react-router-dom'

import { AppSidebar } from '@/components/layout/app-sidebar'
import { getCurrentAuth, logout } from '@/lib/api'
import { authQueryKey } from '@/lib/auth-query'

export function AuthenticatedLayout() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [mobileOpen, setMobileOpen] = useState(false)
  const menuButtonRef = useRef<HTMLButtonElement>(null)
  const closeButtonRef = useRef<HTMLButtonElement>(null)
  const authQuery = useQuery({ queryKey: authQueryKey, queryFn: getCurrentAuth })
  const logoutMutation = useMutation({
    mutationFn: logout,
    onSuccess: () => {
      queryClient.clear()
      navigate('/login', { replace: true })
    },
  })

  useEffect(() => {
    if (!mobileOpen) return
    const originalOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    closeButtonRef.current?.focus()
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setMobileOpen(false)
        menuButtonRef.current?.focus()
      }
    }
    document.addEventListener('keydown', closeOnEscape)
    return () => {
      document.removeEventListener('keydown', closeOnEscape)
      document.body.style.overflow = originalOverflow
    }
  }, [mobileOpen])

  if (!authQuery.data) return null

  const closeMobile = () => {
    setMobileOpen(false)
    menuButtonRef.current?.focus()
  }

  return (
    <div className="min-h-screen bg-background text-foreground">
      <a href="#main-content" className="fixed left-4 top-4 z-[60] -translate-y-24 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground shadow-lg transition-transform focus:translate-y-0">Skip to main content</a>
      <AppSidebar
        auth={authQuery.data}
        mobileOpen={mobileOpen}
        onMobileOpen={() => setMobileOpen(true)}
        onMobileClose={closeMobile}
        menuButtonRef={menuButtonRef}
        closeButtonRef={closeButtonRef}
        logoutPending={logoutMutation.isPending}
        logoutError={logoutMutation.isError}
        onLogout={() => logoutMutation.mutate()}
      />
      <div className="lg:pl-64" inert={mobileOpen || undefined}>
        <main id="main-content" className="app-workspace min-h-screen px-4 py-6 sm:px-6 sm:py-8 xl:px-10 xl:py-10">
          <Outlet context={authQuery.data} />
        </main>
      </div>
    </div>
  )
}
