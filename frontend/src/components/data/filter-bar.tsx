import type { FormHTMLAttributes, PropsWithChildren } from 'react'

import { cn } from '@/lib/utils'

export function FilterBar({ children, className, ...props }: PropsWithChildren<FormHTMLAttributes<HTMLFormElement>>) {
  return <form role="search" className={cn('grid gap-4 border-b border-border bg-surface p-5', className)} {...props}>{children}</form>
}
