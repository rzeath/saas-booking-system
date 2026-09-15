import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useState } from 'react'

import { CustomerForm } from '@/components/customers/customer-form'
import { PaginationControls } from '@/components/data/pagination-controls'
import { FormField, SelectField } from '@/components/forms/form-field'
import { Modal } from '@/components/ui/modal'
import {
  type Customer,
  getCustomers,
  type MasterDataQuery,
  type MasterDataStatus,
  type SaveCustomerInput,
  updateCustomer,
} from '@/lib/api'
import { customerListQueryKey, customersQueryKey } from '@/lib/customers-query'

function customerInput(customer: Customer): SaveCustomerInput {
  return {
    name: customer.name,
    email: customer.email,
    phone: customer.phone,
    address: customer.address,
    notes: customer.notes,
    is_active: customer.is_active,
  }
}

const initialQuery: MasterDataQuery = { page: 1, search: '', status: 'all' }

export function CustomersRoute() {
  const queryClient = useQueryClient()
  const [query, setQuery] = useState<MasterDataQuery>(initialQuery)
  const [search, setSearch] = useState('')
  const [editingCustomer, setEditingCustomer] = useState<Customer | null | undefined>()
  const [message, setMessage] = useState<string>()
  const [errorMessage, setErrorMessage] = useState<string>()
  const customersQuery = useQuery({
    queryKey: customerListQueryKey(query),
    queryFn: () => getCustomers(query),
  })
  const statusMutation = useMutation({
    mutationFn: ({ customer, isActive }: { customer: Customer; isActive: boolean }) =>
      updateCustomer(customer.id, { ...customerInput(customer), is_active: isActive }),
    onSuccess: (customer) => {
      setErrorMessage(undefined)
      setMessage(`${customer.name} is now ${customer.is_active ? 'active' : 'inactive'}.`)
      void queryClient.invalidateQueries({ queryKey: customersQueryKey })
    },
    onError: (error) => {
      setMessage(undefined)
      setErrorMessage(error instanceof Error ? error.message : 'Unable to update customer status.')
    },
  })

  return (
    <section>
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <p className="text-sm font-semibold tracking-[0.08em] text-cyan-400">Master data</p>
          <h1 className="mt-2 text-3xl font-semibold tracking-tight">Customers</h1>
          <p className="mt-2 text-sm text-slate-400">Manage reusable customer contact information.</p>
        </div>
        <button type="button" onClick={() => setEditingCustomer(null)} className="inline-flex items-center gap-2 rounded-lg bg-cyan-500 px-4 py-2.5 text-sm font-semibold text-slate-950 hover:bg-cyan-400">
          <Plus className="size-4" aria-hidden="true" /> New customer
        </button>
      </div>

      <div className="mt-8 rounded-2xl border border-slate-800 bg-slate-900 shadow-xl shadow-black/10">
        <form
          role="search"
          className="grid gap-4 border-b border-slate-800 p-5 md:grid-cols-[1fr_12rem_auto] md:items-end"
          onSubmit={(event) => {
            event.preventDefault()
            setQuery((current) => ({ ...current, page: 1, search: search.trim() }))
          }}
        >
          <FormField label="Search customers" id="customer-search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Name, email, or phone" />
          <SelectField
            label="Status filter"
            id="customer-status-filter"
            value={query.status}
            onChange={(event) => setQuery((current) => ({ ...current, page: 1, status: event.target.value as MasterDataStatus }))}
          >
            <option value="all">All</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </SelectField>
          <button type="submit" className="rounded-lg border border-slate-700 px-4 py-2.5 text-sm font-medium hover:bg-slate-800">Search</button>
        </form>

        {message ? <p role="status" className="px-5 pt-4 text-sm text-emerald-300">{message}</p> : null}
        {errorMessage ? <p role="alert" className="px-5 pt-4 text-sm text-rose-300">{errorMessage}</p> : null}
        {customersQuery.isPending ? <p role="status" className="p-8 text-center text-slate-400">Loading customers…</p> : null}
        {customersQuery.isError ? (
          <div className="p-8 text-center">
            <p role="alert" className="text-rose-300">We could not load customers.</p>
            <button type="button" onClick={() => { void customersQuery.refetch() }} className="mt-3 rounded-lg border border-slate-700 px-3 py-2 text-sm hover:bg-slate-800">Try again</button>
          </div>
        ) : null}
        {customersQuery.data && customersQuery.data.data.length === 0 ? <p className="p-8 text-center text-slate-400">No customers match these filters.</p> : null}
        {customersQuery.data && customersQuery.data.data.length > 0 ? (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-slate-800 text-xs uppercase tracking-wide text-slate-500">
                <tr><th className="px-5 py-3">Name</th><th className="px-5 py-3">Contact</th><th className="px-5 py-3">Status</th><th className="px-5 py-3 text-right">Actions</th></tr>
              </thead>
              <tbody className="divide-y divide-slate-800">
                {customersQuery.data.data.map((customer) => (
                  <tr key={customer.id}>
                    <td className="px-5 py-4 font-medium text-slate-100">{customer.name}</td>
                    <td className="px-5 py-4 text-slate-400"><span className="block">{customer.email ?? 'No email'}</span><span className="block">{customer.phone ?? 'No phone'}</span></td>
                    <td className="px-5 py-4"><span className={customer.is_active ? 'text-emerald-300' : 'text-slate-500'}>{customer.is_active ? 'Active' : 'Inactive'}</span></td>
                    <td className="px-5 py-4 text-right">
                      <div className="flex justify-end gap-2">
                        <button type="button" onClick={() => setEditingCustomer(customer)} className="rounded-lg border border-slate-700 px-3 py-1.5 hover:bg-slate-800">Edit</button>
                        <button
                          type="button"
                          disabled={statusMutation.isPending && statusMutation.variables?.customer.id === customer.id}
                          onClick={() => statusMutation.mutate({ customer, isActive: !customer.is_active })}
                          className="rounded-lg border border-slate-700 px-3 py-1.5 hover:bg-slate-800 disabled:opacity-50"
                        >
                          {customer.is_active ? 'Deactivate' : 'Activate'}
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : null}
        {customersQuery.data ? (
          <PaginationControls
            page={customersQuery.data.meta.current_page}
            lastPage={customersQuery.data.meta.last_page}
            total={customersQuery.data.meta.total}
            onPageChange={(page) => setQuery((current) => ({ ...current, page }))}
          />
        ) : null}
      </div>

      {editingCustomer !== undefined ? (
        <Modal title={editingCustomer ? 'Edit customer' : 'New customer'} onClose={() => setEditingCustomer(undefined)}>
          <CustomerForm
            customer={editingCustomer}
            onCancel={() => setEditingCustomer(undefined)}
            onSaved={(customer) => {
              setEditingCustomer(undefined)
              setMessage(`${customer.name} saved.`)
              setErrorMessage(undefined)
              void queryClient.invalidateQueries({ queryKey: customersQueryKey })
            }}
          />
        </Modal>
      ) : null}
    </section>
  )
}
