import type {
  InputHTMLAttributes,
  PropsWithChildren,
  SelectHTMLAttributes,
  TextareaHTMLAttributes,
} from 'react'

const labelClassName = 'block text-sm font-medium text-foreground'
const controlClassName = 'mt-2 w-full rounded-lg border border-border bg-surface px-3 py-2.5 text-foreground shadow-sm outline-none transition placeholder:text-muted/70 focus:border-primary focus:ring-2 focus:ring-ring/40 disabled:cursor-not-allowed disabled:bg-surface-subtle disabled:text-muted'
const errorClassName = 'mt-1.5 block text-sm text-danger'

type FormFieldProps = InputHTMLAttributes<HTMLInputElement> & { label: string; error?: string }

export function FormField({ label, error, id, ...inputProps }: FormFieldProps) {
  const errorId = error && id ? `${id}-error` : undefined

  return (
    <div>
      <label className={labelClassName} htmlFor={id}>{label}</label>
      <input
        {...inputProps}
        id={id}
        aria-invalid={Boolean(error)}
        aria-describedby={errorId}
        className={controlClassName}
      />
      {error ? <span id={errorId} className={errorClassName}>{error}</span> : null}
    </div>
  )
}

type TextAreaFieldProps = TextareaHTMLAttributes<HTMLTextAreaElement> & { label: string; error?: string }

export function TextAreaField({ label, error, id, ...textareaProps }: TextAreaFieldProps) {
  const errorId = error && id ? `${id}-error` : undefined

  return (
    <div>
      <label className={labelClassName} htmlFor={id}>{label}</label>
      <textarea
        {...textareaProps}
        id={id}
        aria-invalid={Boolean(error)}
        aria-describedby={errorId}
        className={`${controlClassName} min-h-24 resize-y`}
      />
      {error ? <span id={errorId} className={errorClassName}>{error}</span> : null}
    </div>
  )
}

type SelectFieldProps = PropsWithChildren<SelectHTMLAttributes<HTMLSelectElement> & { label: string; error?: string }>

export function SelectField({ label, error, id, children, ...selectProps }: SelectFieldProps) {
  const errorId = error && id ? `${id}-error` : undefined

  return (
    <div>
      <label className={labelClassName} htmlFor={id}>{label}</label>
      <select
        {...selectProps}
        id={id}
        aria-invalid={Boolean(error)}
        aria-describedby={errorId}
        className={controlClassName}
      >
        {children}
      </select>
      {error ? <span id={errorId} className={errorClassName}>{error}</span> : null}
    </div>
  )
}
