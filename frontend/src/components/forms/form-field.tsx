import type {
  InputHTMLAttributes,
  PropsWithChildren,
  SelectHTMLAttributes,
  TextareaHTMLAttributes,
} from 'react'

type FormFieldProps = InputHTMLAttributes<HTMLInputElement> & { label: string; error?: string }

export function FormField({ label, error, id, ...inputProps }: FormFieldProps) {
  const errorId = error && id ? `${id}-error` : undefined

  return (
    <div>
      <label className="block text-sm font-medium text-slate-200" htmlFor={id}>{label}</label>
      <input
        {...inputProps}
        id={id}
        aria-invalid={Boolean(error)}
        aria-describedby={errorId}
        className="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 text-slate-100 outline-none transition focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20"
      />
      {error ? <span id={errorId} className="mt-1.5 block text-sm text-rose-400">{error}</span> : null}
    </div>
  )
}

type TextAreaFieldProps = TextareaHTMLAttributes<HTMLTextAreaElement> & { label: string; error?: string }

export function TextAreaField({ label, error, id, ...textareaProps }: TextAreaFieldProps) {
  const errorId = error && id ? `${id}-error` : undefined

  return (
    <div>
      <label className="block text-sm font-medium text-slate-200" htmlFor={id}>{label}</label>
      <textarea
        {...textareaProps}
        id={id}
        aria-invalid={Boolean(error)}
        aria-describedby={errorId}
        className="mt-2 min-h-24 w-full resize-y rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 text-slate-100 outline-none transition focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20"
      />
      {error ? <span id={errorId} className="mt-1.5 block text-sm text-rose-400">{error}</span> : null}
    </div>
  )
}

type SelectFieldProps = PropsWithChildren<SelectHTMLAttributes<HTMLSelectElement> & { label: string; error?: string }>

export function SelectField({ label, error, id, children, ...selectProps }: SelectFieldProps) {
  const errorId = error && id ? `${id}-error` : undefined

  return (
    <div>
      <label className="block text-sm font-medium text-slate-200" htmlFor={id}>{label}</label>
      <select
        {...selectProps}
        id={id}
        aria-invalid={Boolean(error)}
        aria-describedby={errorId}
        className="mt-2 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 text-slate-100 outline-none transition focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20"
      >
        {children}
      </select>
      {error ? <span id={errorId} className="mt-1.5 block text-sm text-rose-400">{error}</span> : null}
    </div>
  )
}
