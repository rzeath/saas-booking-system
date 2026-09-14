import { AlertCircle, Inbox } from 'lucide-react'
import type { ReactNode } from 'react'

import { Button } from '@/components/ui/button'

export function LoadingState({ label = 'Loading…' }: { label?: string }) {
  return (
    <div role="status" className="grid min-h-48 place-items-center p-8 text-center text-sm text-muted">
      <div><span className="mx-auto mb-3 block size-6 animate-spin rounded-full border-2 border-border border-t-primary" aria-hidden="true" />{label}</div>
    </div>
  )
}

export function ErrorState({ title = 'Something went wrong.', description, onRetry }: { title?: string; description?: string; onRetry?: () => void }) {
  return (
    <div className="grid min-h-48 place-items-center p-8 text-center">
      <div>
        <AlertCircle className="mx-auto size-7 text-danger" aria-hidden="true" />
        <p role="alert" className="mt-3 font-semibold text-foreground">{title}</p>
        {description ? <p className="mt-1 text-sm text-muted">{description}</p> : null}
        {onRetry ? <Button variant="secondary" className="mt-4" onClick={onRetry}>Try again</Button> : null}
      </div>
    </div>
  )
}

export function EmptyState({ title, description, action }: { title: string; description?: string; action?: ReactNode }) {
  return (
    <div className="grid min-h-48 place-items-center p-8 text-center">
      <div>
        <Inbox className="mx-auto size-8 text-muted" aria-hidden="true" />
        <p className="mt-3 font-semibold text-foreground">{title}</p>
        {description ? <p className="mt-1 max-w-md text-sm text-muted">{description}</p> : null}
        {action ? <div className="mt-4 flex justify-center">{action}</div> : null}
      </div>
    </div>
  )
}
