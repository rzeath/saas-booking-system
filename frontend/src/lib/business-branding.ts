import type { ThemeAccent } from '@/lib/api'

export const themeAccentOptions: ReadonlyArray<{ value: ThemeAccent; label: string }> = [
  { value: 'plum', label: 'Plum' },
  { value: 'forest', label: 'Forest' },
  { value: 'terracotta', label: 'Terracotta' },
  { value: 'teal', label: 'Teal' },
  { value: 'indigo', label: 'Indigo' },
  { value: 'graphite', label: 'Graphite' },
]

export function businessInitials(name: string): string {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join('') || 'B'
}
