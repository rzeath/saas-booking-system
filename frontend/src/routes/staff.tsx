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
  createStaff,
  getStaff,
  type MasterDataQuery,
  type MasterDataStatus,
  type SaveStaffInput,
  type Staff,
  updateStaff,
} from '@/lib/api'
import { staffListQueryKey, staffQueryKey } from '@/lib/staff-query'

const staffSchema = z.object({
  name: z.string().trim().min(1, 'Staff name is required.').max(255),
  phone: z.string().trim().min(1, 'Phone is required.').max(50, 'Use at most 50 characters.'),
  email: z.union([z.literal(''), z.string().trim().email('Enter a valid email address.').max(255)]),
  notes: z.string().trim().max(5000, 'Use at most 5,000 characters.'),
  status: z.enum(['active', 'inactive']),
})

type StaffFormValues = z.infer<typeof staffSchema>
const staffFields = new Set<keyof StaffFormValues>(['name', 'phone', 'email', 'notes', 'status'])
const initialQuery: MasterDataQuery = { page: 1, search: '', status: 'all' }

function staffInput(staff: Staff): SaveStaffInput {
  return {
    name: staff.name,
    phone: staff.phone,
    email: staff.email,
    notes: staff.notes,
    is_active: staff.is_active,
  }
}

function StaffForm({
  staff,
  onSaved,
  onCancel,
}: {
  staff: Staff | null
  onSaved: (staff: Staff) => void
  onCancel: () => void
}) {
  const [formMessage, setFormMessage] = useState<string>()
  const form = useForm<StaffFormValues>({
    resolver: zodResolver(staffSchema),
    defaultValues: {
      name: staff?.name ?? '',
      phone: staff?.phone ?? '',
      email: staff?.email ?? '',
      notes: staff?.notes ?? '',
      status: staff?.is_active === false ? 'inactive' : 'active',
    },
  })
  const mutation = useMutation({
    mutationFn: (values: StaffFormValues) => {
      const input: SaveStaffInput = {
        name: values.name,
        phone: values.phone,
        email: values.email || null,
        notes: values.notes || null,
        is_active: values.status === 'active',
      }

      return staff ? updateStaff(staff.id, input) : createStaff(input)
    },
    onSuccess: onSaved,
    onError: (error) => {
      if (error instanceof ApiError) {
        let mapped = false
        for (const [field, messages] of Object.entries(error.fieldErrors)) {
          const formField = field === 'is_active' ? 'status' : field
          if (staffFields.has(formField as keyof StaffFormValues) && messages[0]) {
            form.setError(formField as keyof StaffFormValues, { message: messages[0] })
            mapped = true
          }
        }
        if (mapped) return
      }

      setFormMessage(error instanceof Error ? error.message : 'Unable to save staff member.')
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
      <div className="md:col-span-2">
        <FormField label="Name" id="staff-name" error={form.formState.errors.name?.message} {...form.register('name')} />
      </div>
      <FormField label="Phone" id="staff-phone" error={form.formState.errors.phone?.message} {...form.register('phone')} />
      <FormField label="Email" id="staff-email" type="email" error={form.formState.errors.email?.message} {...form.register('email')} />
      <div className="md:col-span-2">
        <TextAreaField label="Notes" id="staff-notes" error={form.formState.errors.notes?.message} {...form.register('notes')} />
      </div>
      <SelectField label="Status" id="staff-form-status" error={form.formState.errors.status?.message} {...form.register('status')}>
        <option value="active">Active</option>
        <option value="inactive">Inactive</option>
      </SelectField>
      {formMessage ? <p role="alert" className="text-sm text-rose-300 md:col-span-2">{formMessage}</p> : null}
      <div className="flex justify-end gap-3 md:col-span-2">
        <button type="button" onClick={onCancel} className="rounded-lg border border-slate-700 px-4 py-2 text-sm hover:bg-slate-800">Cancel</button>
        <button type="submit" disabled={mutation.isPending} className="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-slate-950 hover:bg-cyan-400 disabled:opacity-60">
          {mutation.isPending ? 'Saving…' : staff ? 'Save changes' : 'Create staff member'}
        </button>
      </div>
    </form>
  )
}

export function StaffRoute({ embedded = false }: { embedded?: boolean } = {}) {
  const queryClient = useQueryClient()
  const [query, setQuery] = useState<MasterDataQuery>(initialQuery)
  const [search, setSearch] = useState('')
  const [editingStaff, setEditingStaff] = useState<Staff | null | undefined>()
  const [message, setMessage] = useState<string>()
  const [errorMessage, setErrorMessage] = useState<string>()
  const staffQuery = useQuery({
    queryKey: staffListQueryKey(query),
    queryFn: () => getStaff(query),
  })
  const statusMutation = useMutation({
    mutationFn: ({ staff, isActive }: { staff: Staff; isActive: boolean }) =>
      updateStaff(staff.id, { ...staffInput(staff), is_active: isActive }),
    onSuccess: (staff) => {
      setErrorMessage(undefined)
      setMessage(`${staff.name} is now ${staff.is_active ? 'active' : 'inactive'}.`)
      void queryClient.invalidateQueries({ queryKey: staffQueryKey })
    },
    onError: (error) => {
      setMessage(undefined)
      setErrorMessage(error instanceof Error ? error.message : 'Unable to update staff status.')
    },
  })

  return (
    <section>
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          {!embedded ? <p className="text-sm font-semibold tracking-[0.08em] text-cyan-400">Master data</p> : null}
          {embedded ? <h2 className="text-lg font-semibold">Staff</h2> : <h1 className="mt-2 text-3xl font-semibold tracking-tight">Staff</h1>}
          <p className="mt-2 text-sm text-slate-400">Manage operational people available for future manual assignment.</p>
        </div>
        <button type="button" onClick={() => setEditingStaff(null)} className="inline-flex items-center gap-2 rounded-lg bg-cyan-500 px-4 py-2.5 text-sm font-semibold text-slate-950 hover:bg-cyan-400">
          <Plus className="size-4" aria-hidden="true" /> New staff member
        </button>
      </div>

      <div className="mt-6 rounded-2xl border border-slate-800 bg-slate-900 shadow-xl shadow-black/10">
        <form
          role="search"
          className="grid gap-4 border-b border-slate-800 p-5 md:grid-cols-[1fr_12rem_auto] md:items-end"
          onSubmit={(event) => {
            event.preventDefault()
            setQuery((current) => ({ ...current, page: 1, search: search.trim() }))
          }}
        >
          <FormField label="Search staff" id="staff-search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Name, phone, or email" />
          <SelectField
            label="Status filter"
            id="staff-status-filter"
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
        {staffQuery.isPending ? <p role="status" className="p-8 text-center text-slate-400">Loading staff…</p> : null}
        {staffQuery.isError ? (
          <div className="p-8 text-center">
            <p role="alert" className="text-rose-300">We could not load staff.</p>
            <button type="button" onClick={() => { void staffQuery.refetch() }} className="mt-3 rounded-lg border border-slate-700 px-3 py-2 text-sm hover:bg-slate-800">Try again</button>
          </div>
        ) : null}
        {staffQuery.data && staffQuery.data.data.length === 0 ? <p className="p-8 text-center text-slate-400">No staff match these filters.</p> : null}
        {staffQuery.data && staffQuery.data.data.length > 0 ? (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-slate-800 text-xs uppercase tracking-wide text-slate-500">
                <tr><th className="px-5 py-3">Name</th><th className="px-5 py-3">Phone</th><th className="px-5 py-3">Email</th><th className="px-5 py-3">Status</th><th className="px-5 py-3 text-right">Actions</th></tr>
              </thead>
              <tbody className="divide-y divide-slate-800">
                {staffQuery.data.data.map((staff) => (
                  <tr key={staff.id}>
                    <td className="px-5 py-4 font-medium text-slate-100">{staff.name}</td>
                    <td className="px-5 py-4 text-slate-300">{staff.phone}</td>
                    <td className="px-5 py-4 text-slate-400">{staff.email ?? 'No email'}</td>
                    <td className="px-5 py-4"><span className={staff.is_active ? 'text-emerald-300' : 'text-slate-500'}>{staff.is_active ? 'Active' : 'Inactive'}</span></td>
                    <td className="px-5 py-4 text-right">
                      <div className="flex justify-end gap-2">
                        <button type="button" onClick={() => setEditingStaff(staff)} className="rounded-lg border border-slate-700 px-3 py-1.5 hover:bg-slate-800">Edit</button>
                        <button
                          type="button"
                          disabled={statusMutation.isPending && statusMutation.variables?.staff.id === staff.id}
                          onClick={() => statusMutation.mutate({ staff, isActive: !staff.is_active })}
                          className="rounded-lg border border-slate-700 px-3 py-1.5 hover:bg-slate-800 disabled:opacity-50"
                        >
                          {staff.is_active ? 'Deactivate' : 'Activate'}
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : null}
        {staffQuery.data ? (
          <PaginationControls
            page={staffQuery.data.meta.current_page}
            lastPage={staffQuery.data.meta.last_page}
            total={staffQuery.data.meta.total}
            onPageChange={(page) => setQuery((current) => ({ ...current, page }))}
          />
        ) : null}
      </div>

      {editingStaff !== undefined ? (
        <Modal title={editingStaff ? 'Edit staff member' : 'New staff member'} onClose={() => setEditingStaff(undefined)}>
          <StaffForm
            staff={editingStaff}
            onCancel={() => setEditingStaff(undefined)}
            onSaved={(staff) => {
              setEditingStaff(undefined)
              setMessage(`${staff.name} saved.`)
              setErrorMessage(undefined)
              void queryClient.invalidateQueries({ queryKey: staffQueryKey })
            }}
          />
        </Modal>
      ) : null}
    </section>
  )
}
