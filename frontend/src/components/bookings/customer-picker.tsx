import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Search } from 'lucide-react'
import { useState } from 'react'
import { createPortal } from 'react-dom'

import { CustomerForm } from '@/components/customers/customer-form'
import { FormField } from '@/components/forms/form-field'
import { Button } from '@/components/ui/button'
import { Modal } from '@/components/ui/modal'
import { getCustomers, type Customer, type MasterDataQuery } from '@/lib/api'
import { customerListQueryKey, customersQueryKey } from '@/lib/customers-query'

const initialQuery: MasterDataQuery = {
  page: 1,
  search: '',
  status: 'active',
  per_page: 10,
}

export function CustomerPicker({
  value,
  selectedCustomer,
  error,
  disabled,
  onSelect,
}: {
  value: number
  selectedCustomer?: Customer
  error?: string
  disabled?: boolean
  onSelect: (customer: Customer) => void
}) {
  const queryClient = useQueryClient()
  const [query, setQuery] = useState<MasterDataQuery>(initialQuery)
  const [search, setSearch] = useState('')
  const [isChanging, setIsChanging] = useState(false)
  const [isCreating, setIsCreating] = useState(false)
  const isChoosing = value < 1 || isChanging
  const customers = useQuery({
    queryKey: customerListQueryKey(query),
    queryFn: () => getCustomers(query),
    enabled: isChoosing && !disabled,
  })

  const choose = (customer: Customer) => {
    onSelect(customer)
    setIsChanging(false)
    setSearch('')
    setQuery(initialQuery)
  }
  const submitSearch = () => {
    setQuery((current) => ({ ...current, page: 1, search: search.trim() }))
  }

  return (
    <div className="md:col-span-2">
      <div className="flex items-center justify-between gap-3">
        <span className="text-sm font-medium text-foreground">Customer</span>
        {selectedCustomer && !isChoosing ? (
          <Button variant="ghost" size="small" disabled={disabled} onClick={() => setIsChanging(true)}>Change</Button>
        ) : null}
      </div>

      {selectedCustomer && !isChoosing ? (
        <div className="mt-2 flex min-h-20 items-center justify-between gap-4 rounded-lg border border-border bg-surface-subtle px-4 py-3">
          <div className="min-w-0">
            <strong className="block truncate text-sm text-foreground">{selectedCustomer.name}</strong>
            <p className="mt-1 truncate text-sm text-muted">
              {[selectedCustomer.phone, selectedCustomer.email].filter(Boolean).join(' · ') || 'No contact details'}
            </p>
            {!selectedCustomer.is_active ? <span className="mt-1 block text-xs text-warning">Inactive customer</span> : null}
          </div>
        </div>
      ) : (
        <div className="mt-2 rounded-lg border border-border bg-surface-subtle p-4">
          <div className="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
            <FormField
              label="Search customer"
              id="booking-customer-search"
              value={search}
              disabled={disabled}
              placeholder="Name, email, or phone"
              onChange={(event) => setSearch(event.target.value)}
              onKeyDown={(event) => {
                if (event.key !== 'Enter') return
                event.preventDefault()
                submitSearch()
              }}
            />
            <Button variant="secondary" disabled={disabled} onClick={submitSearch}>
              <Search className="size-4" aria-hidden="true" /> Search
            </Button>
          </div>

          <div className="mt-3 overflow-hidden rounded-lg border border-border bg-surface" role="listbox" aria-label="Customer search results">
            {customers.isPending ? <p role="status" className="px-4 py-5 text-center text-sm text-muted">Searching customers…</p> : null}
            {customers.isError ? (
              <div className="px-4 py-5 text-center">
                <p role="alert" className="text-sm text-danger">Customer search could not be loaded.</p>
                <Button variant="ghost" size="small" className="mt-2" onClick={() => { void customers.refetch() }}>Try again</Button>
              </div>
            ) : null}
            {customers.data?.data.length === 0 ? <p className="px-4 py-5 text-center text-sm text-muted">No customers found.</p> : null}
            {customers.data?.data.map((customer) => (
              <button
                key={customer.id}
                type="button"
                role="option"
                aria-label={customer.name}
                aria-selected={customer.id === value}
                className="flex min-h-14 w-full items-center justify-between gap-4 border-b border-border px-4 py-3 text-left last:border-b-0 hover:bg-surface-subtle focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
                onClick={() => choose(customer)}
              >
                <span className="min-w-0">
                  <strong className="block truncate text-sm text-foreground">{customer.name}</strong>
                  <span className="block truncate text-xs text-muted">{[customer.phone, customer.email].filter(Boolean).join(' · ') || 'No contact details'}</span>
                </span>
                <span className="shrink-0 text-xs font-medium text-primary">Select</span>
              </button>
            ))}
          </div>

          <div className="mt-3 flex items-center justify-between gap-3">
            <Button variant="ghost" className="px-0 text-primary" disabled={disabled} onClick={() => setIsCreating(true)}>
              <Plus className="size-4" aria-hidden="true" /> Add New Customer
            </Button>
            {selectedCustomer ? <Button variant="ghost" size="small" onClick={() => setIsChanging(false)}>Keep current</Button> : null}
          </div>
        </div>
      )}

      {error ? <span className="mt-1.5 block text-sm text-danger">{error}</span> : null}

      {isCreating ? createPortal(
        <Modal title="Add new customer" description="Create reusable customer master data without leaving this booking." onClose={() => setIsCreating(false)}>
          <CustomerForm
            customer={null}
            showStatus={false}
            onCancel={() => setIsCreating(false)}
            onSaved={(customer) => {
              void queryClient.invalidateQueries({ queryKey: customersQueryKey })
              choose(customer)
              setIsCreating(false)
            }}
          />
        </Modal>,
        document.body,
      ) : null}
    </div>
  )
}
