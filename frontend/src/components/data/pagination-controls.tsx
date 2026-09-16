type PaginationControlsProps = {
  page: number
  lastPage: number
  total: number
  perPage?: number
  showPageNumbers?: boolean
  onPageChange: (page: number) => void
}

export function PaginationControls({
  page,
  lastPage,
  total,
  perPage,
  showPageNumbers = false,
  onPageChange,
}: PaginationControlsProps) {
  if (total === 0) return null

  const firstItem = perPage ? ((page - 1) * perPage) + 1 : null
  const lastItem = perPage ? Math.min(page * perPage, total) : null
  const pageNumbers = showPageNumbers
    ? Array.from({ length: lastPage }, (_, index) => index + 1).filter((pageNumber) => (
        lastPage <= 5
        || pageNumber === 1
        || pageNumber === lastPage
        || Math.abs(pageNumber - page) <= 1
      ))
    : []

  return (
    <nav aria-label="Pagination" className="flex flex-wrap items-center justify-between gap-4 border-t border-border px-5 py-4">
      <p className="text-sm text-muted">
        {firstItem && lastItem ? `Showing ${firstItem}–${lastItem} of ${total}` : `Page ${page} of ${lastPage} · ${total} total`}
      </p>
      <div className="flex items-center gap-1">
        <Button variant="secondary" size="small" disabled={page <= 1} onClick={() => onPageChange(page - 1)}>Previous</Button>
        {pageNumbers.map((pageNumber, index) => (
          <span key={pageNumber} className="contents">
            {index > 0 && pageNumber - pageNumbers[index - 1] > 1 ? <span className="px-1 text-xs text-muted" aria-hidden="true">…</span> : null}
            <Button
              variant={pageNumber === page ? 'secondary' : 'ghost'}
              size="small"
              className="min-w-8 px-2"
              aria-label={`Go to page ${pageNumber}`}
              aria-current={pageNumber === page ? 'page' : undefined}
              onClick={() => onPageChange(pageNumber)}
            >
              {pageNumber}
            </Button>
          </span>
        ))}
        <Button variant="secondary" size="small" disabled={page >= lastPage} onClick={() => onPageChange(page + 1)}>Next</Button>
      </div>
    </nav>
  )
}
import { Button } from '@/components/ui/button'
