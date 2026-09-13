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
    <nav aria-label="Pagination" className="flex flex-wrap items-center justify-between gap-4 border-t border-slate-800 px-5 py-4">
      <p className="text-sm text-slate-400">Page {page} of {lastPage} · {total} total</p>
      <div className="flex gap-2">
        <button type="button" disabled={page <= 1} onClick={() => onPageChange(page - 1)} className="rounded-lg border border-slate-700 px-3 py-1.5 text-sm hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-40">Previous</button>
        <button type="button" disabled={page >= lastPage} onClick={() => onPageChange(page + 1)} className="rounded-lg border border-slate-700 px-3 py-1.5 text-sm hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-40">Next</button>
      </div>
    </nav>
  )
}
