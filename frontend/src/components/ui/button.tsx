import type { VariantProps } from 'class-variance-authority'
import { forwardRef, type ButtonHTMLAttributes } from 'react'

import { buttonVariants } from '@/components/ui/button-variants'
import { cn } from '@/lib/utils'

type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & VariantProps<typeof buttonVariants>

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  { className, variant, size, type = 'button', ...props },
  ref,
) {
  return <button ref={ref} type={type} className={cn(buttonVariants({ variant, size }), className)} {...props} />
})
