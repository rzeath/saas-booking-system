import { cva } from 'class-variance-authority'

export const buttonVariants = cva(
  'inline-flex min-h-10 items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-surface disabled:pointer-events-none disabled:opacity-50',
  {
    variants: {
      variant: {
        primary: 'bg-primary text-primary-foreground hover:bg-primary-hover',
        secondary: 'border border-border bg-surface text-foreground hover:bg-surface-subtle',
        ghost: 'text-muted hover:bg-surface-subtle hover:text-foreground',
        destructive: 'bg-danger text-white hover:bg-red-800',
      },
      size: {
        default: 'min-h-10 px-4 py-2',
        small: 'min-h-8 px-3 py-1.5 text-xs',
        icon: 'size-10 p-0',
      },
    },
    defaultVariants: { variant: 'primary', size: 'default' },
  },
)
