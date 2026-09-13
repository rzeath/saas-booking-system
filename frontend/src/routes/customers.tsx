import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'

import { PaginationControls } from '@/components/data/pagination-controls'
import { FormField, SelectField, TextAreaField } from '@/components/forms/form-field'
import { Modal } from '@/components/ui/modal'
import {
  ApiError,
  createCustomer,
  type Customer,
  getCustomers,
  type MasterDataQuery,
  type MasterDataStatus,
  type SaveCustomerInput,
  updateCustomer,
} from '@/lib/api'
import { customerListQueryKey, customersQueryKey } from '@/lib/customers-query'

const customerSchema = z.object({
  name: z.string().trim().min(1, 'Customer name is required.').max(255),
  email: z.union([z.literal(''), z.string().trim().email('Enter a valid email address.').max(255)]),
  phone: z.string().trim().max(50, 'Use at most 50 characters.'),
  address: z.string().trim().max(2000, 'Use at most 2,000 characters.'),
  notes: z.string().trim().max(5000, 'Use at most 5,000 characters.'),
  status: z.enum(['active', 'inactive']),
})

type CustomerFormValues = z.infer<typeof customerSchema>
const customerFields = new Set<keyof CustomerFormValues>([
  'name',
  'email',
  'phone',
  'address',
  'notes',
  'status',
])

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

function CustomerForm({
  customer,
  onSaved,
  onCancel,
}: {
  customer: Customer | null
  onSaved: (customer: Customer) => void
  onCancel: () => void
}) {
  const [formMessage, setFormMessage] = useState<string>()
  const form = useForm<CustomerFormValues>({
    resolver: zodResolver(customerSchema),
    defaultValues: {
      name: customer?.name ?? '',
      email: customer?.email ?? '',
      phone: customer?.phone ?? '',
      address: customer?.address ?? '',
      notes: customer?.notes ?? '',
      status: customer?.is_active === false ? 'inactive' : 'active',
    },
  })
  const mutation = useMutation({
    mutationFn: (values: CustomerFormValues) => {
      const input: SaveCustomerInput = {
        name: values.name,
        email: values.email || null,
        phone: values.phone || null,
        address: values.address || null,
        notes: values.notes || null,
        is_active: values.status === 'active',
      }

      return customer ? updateCustomer(customer.id, input) : createCustomer(input)
    },
    onSuccess: onSaved,
    onError: (error) => {
      if (error instanceof ApiError) {
        let mapped = false
        for (const [field, messages] of Object.entries(error.fieldErrors)) {
          const formField = field === 'is_active' ? 'status' : field
          if (customerFields.has(formField as keyof CustomerFormValues) && messages[0]) {
            form.setError(formField as keyof CustomerFormValues, { message: messages[0] })
            mapped = true
          }
        }
        if (mapped) return
      }

      setFormMessage(error instanceof Error ? error.message : 'Unable to save customer.')
    },
  })

  return (
    <form
      className="grid gap-5 md:grid-cols-2"
      noValidate
      onSubmit={form.handleSubmit((values) => {
        setFormMessage(undefined)
        mutation.mutate(values)
      })}
    >
      <div className="md:col-span-2"><FormField label="Name" id="customer-name" error={form.formState.errors.name?.message} {...form.register('name')} /></div>
      <FormField label="Email" id="customer-email" type="email" error={form.formState.errors.email?.message} {...form.register('email')} />
      <FormField label="Phone" id="customer-phone" error={form.formState.errors.phone?.message} {...form.register('phone')} />
      <div className="md:col-span-2"><TextAreaField label="Address" id="customer-address" error={form.formState.errors.address?.message} {...form.register('address')} /></div>
      <div className="md:col-span-2"><TextAreaField label="Notes" id="customer-notes" error={form.formState.errors.notes?.message} {...form.register('notes')} /></div>
      <SelectField label="Status" id="customer-form-status" error={form.formState.errors.status?.message} {...form.register('status')}>
        <option value="active">Active</option>
        <option value="inactive">Inactive</option>
      </SelectField>
      {formMessage ? <p role="alert" className="text-sm text-rose-300 md:col-span-2">{formMessage}</p> : null}
      <div className="flex justify-end gap-3 md:col-span-2">
        <button type="button" onClick={onCancel} className="rounded-lg border border-slate-700 px-4 py-2 text-sm hover:bg-slate-800">Cancel</button>
        <button type="submit" disabled={mutation.isPending} className="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-slate-950 hover:bg-cyan-400 disabled:opacity-60">
          {mutation.isPending ? 'Saving…' : customer ? 'Save changes' : 'Create customer'}
        </button>
      </div>
    </form>
  )
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
