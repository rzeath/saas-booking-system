import { X } from 'lucide-react'
import { type PropsWithChildren, useEffect, useRef } from 'react'

type ModalProps = PropsWithChildren<{
  title: string
  onClose: () => void
}>

export function Modal({ title, onClose, children }: ModalProps) {
  const dialogRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    dialogRef.current?.focus()

    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose()
    }

    document.addEventListener('keydown', closeOnEscape)
    return () => document.removeEventListener('keydown', closeOnEscape)
  }, [onClose])

  return (
    <div
      className="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-slate-950/80 p-4 backdrop-blur-sm"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) onClose()
      }}
    >
      <div
        ref={dialogRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby="modal-title"
        tabIndex={-1}
        className="my-8 w-full max-w-2xl rounded-2xl border border-slate-700 bg-slate-900 p-6 shadow-2xl outline-none"
      >
        <div className="flex items-center justify-between gap-4">
          <h2 id="modal-title" className="text-xl font-semibold text-slate-100">{title}</h2>
          <button type="button" onClick={onClose} aria-label="Close" className="rounded-lg p-2 text-slate-400 hover:bg-slate-800 hover:text-slate-100">
            <X className="size-5" aria-hidden="true" />
          </button>
        </div>
        <div className="mt-6">{children}</div>
      </div>
    </div>
  )
}
