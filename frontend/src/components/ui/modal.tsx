import { X } from 'lucide-react'
import { type PropsWithChildren, type ReactNode, useEffect, useId, useRef } from 'react'

import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

type ModalProps = PropsWithChildren<{
  title: string
  description?: string
  footer?: ReactNode
  size?: 'default' | 'large'
  onClose: () => void
}>

const focusableSelector = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

export function Modal({ title, description, footer, size = 'default', onClose, children }: ModalProps) {
  const dialogRef = useRef<HTMLDivElement>(null)
  const titleId = useId()
  const descriptionId = useId()

  useEffect(() => {
    const previouslyFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null
    const dialog = dialogRef.current
    const firstFocusable = dialog?.querySelector<HTMLElement>(focusableSelector)
    ;(firstFocusable ?? dialog)?.focus()
    const originalOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    const handleKeyDown = (event: KeyboardEvent) => {
      const openDialogs = [...document.querySelectorAll<HTMLElement>('[role="dialog"][aria-modal="true"]')]
      if (openDialogs.at(-1) !== dialog) return
      if (event.key === 'Escape') onClose()
      if (event.key !== 'Tab' || !dialog) return

      const focusable = [...dialog.querySelectorAll<HTMLElement>(focusableSelector)]
      if (focusable.length === 0) {
        event.preventDefault()
        dialog.focus()
        return
      }

      const first = focusable[0]
      const last = focusable[focusable.length - 1]
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault()
        last.focus()
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault()
        first.focus()
      }
    }

    document.addEventListener('keydown', handleKeyDown)
    return () => {
      document.removeEventListener('keydown', handleKeyDown)
      document.body.style.overflow = originalOverflow
      previouslyFocused?.focus()
    }
  }, [onClose])

  return (
    <div
      className="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-slate-950/50 p-4 backdrop-blur-[2px]"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) onClose()
      }}
    >
      <div
        ref={dialogRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        aria-describedby={description ? descriptionId : undefined}
        tabIndex={-1}
        className={cn(
          'my-8 w-full overflow-hidden rounded-2xl border border-border bg-surface shadow-2xl outline-none',
          size === 'large' ? 'max-w-6xl' : 'max-w-2xl',
        )}
      >
        <header className="flex items-start justify-between gap-4 border-b border-border px-6 py-5">
          <div>
            <h2 id={titleId} className="text-xl font-semibold text-foreground">{title}</h2>
            {description ? <p id={descriptionId} className="mt-1 text-sm leading-6 text-muted">{description}</p> : null}
          </div>
          <Button variant="ghost" size="icon" onClick={onClose} aria-label="Close dialog" className="-mr-2 -mt-2 shrink-0">
            <X className="size-5" aria-hidden="true" />
          </Button>
        </header>
        <div className="max-h-[calc(100vh-12rem)] overflow-y-auto px-6 py-5">{children}</div>
        {footer ? <footer className="flex flex-wrap justify-end gap-3 border-t border-border bg-surface-subtle px-6 py-4">{footer}</footer> : null}
      </div>
    </div>
  )
}
