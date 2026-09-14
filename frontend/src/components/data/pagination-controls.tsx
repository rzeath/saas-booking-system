type PaginationControlsProps = {
  page: number
  lastPage: number
  total: number
  onPageChange: (page: number) => void
}

export function PaginationControls({
  page,
  lastPage,
  total,
  onPageChange,
}: PaginationControlsProps) {
  if (total === 0) return null

  return (
    <nav aria-label="Pagination" className="flex flex-wrap items-center justify-between gap-4 border-t border-border px-5 py-4">
      <p className="text-sm text-muted">Page {page} of {lastPage} · {total} total</p>
      <div className="flex gap-2">
        <Button variant="secondary" size="small" disabled={page <= 1} onClick={() => onPageChange(page - 1)}>Previous</Button>
        <Button variant="secondary" size="small" disabled={page >= lastPage} onClick={() => onPageChange(page + 1)}>Next</Button>
      </div>
    </nav>
  )
}
import { Button } from '@/components/ui/button'
