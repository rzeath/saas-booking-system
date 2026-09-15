import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'

import { PaginationControls } from '@/components/data/pagination-controls'
import { FormField, SelectField } from '@/components/forms/form-field'
import { Modal } from '@/components/ui/modal'
import { ApiError, createPackage, getPackages, type MasterDataQuery, type MasterDataStatus, type Package, updatePackage } from '@/lib/api'
import { packageListQueryKey, packagesQueryKey } from '@/lib/packages-query'

const formSchema = z.object({
  name: z.string().trim().min(1, 'Package name is required.').max(255),
  status: z.enum(['active', 'inactive']),
})
type FormValues = z.infer<typeof formSchema>
const initialQuery: MasterDataQuery = { page: 1, search: '', status: 'all' }

function PackageForm({ packageItem, onSaved, onCancel }: {
  packageItem: Package | null
  onSaved: (packageItem: Package) => void
  onCancel: () => void
}) {
  const [message, setMessage] = useState<string>()
  const form = useForm<FormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: { name: packageItem?.name ?? '', status: packageItem?.is_active === false ? 'inactive' : 'active' },
  })
  const mutation = useMutation({
    mutationFn: (values: FormValues) => {
      const input = { name: values.name, is_active: values.status === 'active' }
      return packageItem ? updatePackage(packageItem.id, input) : createPackage(input)
    },
    onSuccess: onSaved,
    onError: (error) => {
      if (error instanceof ApiError && error.fieldErrors.name?.[0]) {
        form.setError('name', { message: error.fieldErrors.name[0] })
        return
      }
      setMessage(error instanceof Error ? error.message : 'Unable to save package.')
    },
  })

  return <form className="grid gap-5" noValidate onSubmit={form.handleSubmit((values) => { setMessage(undefined); mutation.mutate(values) })}>
    <FormField label="Name" id="package-name" error={form.formState.errors.name?.message} {...form.register('name')} />
    <SelectField label="Status" id="package-form-status" {...form.register('status')}><option value="active">Active</option><option value="inactive">Inactive</option></SelectField>
    {message ? <p role="alert" className="text-sm text-rose-300">{message}</p> : null}
    <div className="flex justify-end gap-3"><button type="button" onClick={onCancel} className="rounded-lg border border-slate-700 px-4 py-2 text-sm">Cancel</button><button type="submit" disabled={mutation.isPending} className="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-slate-950 disabled:opacity-60">{mutation.isPending ? 'Saving…' : packageItem ? 'Save changes' : 'Create package'}</button></div>
  </form>
}

export function PackagesRoute({ embedded = false }: { embedded?: boolean } = {}) {
  const queryClient = useQueryClient()
  const [query, setQuery] = useState<MasterDataQuery>(initialQuery)
  const [search, setSearch] = useState('')
  const [editing, setEditing] = useState<Package | null | undefined>()
  const [message, setMessage] = useState<string>()
  const packages = useQuery({ queryKey: packageListQueryKey(query), queryFn: () => getPackages(query) })
  const statusMutation = useMutation({
    mutationFn: (packageItem: Package) => updatePackage(packageItem.id, { name: packageItem.name, is_active: !packageItem.is_active }),
    onSuccess: () => { void queryClient.invalidateQueries({ queryKey: packagesQueryKey }) },
  })

  return <section>
    <div className="flex flex-wrap items-end justify-between gap-4"><div>{!embedded ? <p className="text-sm font-semibold tracking-[0.08em] text-cyan-400">Catalog</p> : null}{embedded ? <h2 className="text-lg font-semibold">Packages</h2> : <h1 className="mt-2 text-3xl font-semibold">Packages</h1>}<p className="mt-2 text-sm text-slate-400">Manage reusable packages independently from service assignments.</p></div><button type="button" onClick={() => setEditing(null)} className="inline-flex items-center gap-2 rounded-lg bg-cyan-500 px-4 py-2.5 text-sm font-semibold text-slate-950"><Plus className="size-4" /> New package</button></div>
    <div className="mt-6 rounded-2xl border border-slate-800 bg-slate-900">
      <form role="search" className="grid gap-4 border-b border-slate-800 p-5 md:grid-cols-[1fr_12rem_auto] md:items-end" onSubmit={(event) => { event.preventDefault(); setQuery({ ...query, page: 1, search: search.trim() }) }}><FormField label="Search packages" id="package-search" value={search} onChange={(event) => setSearch(event.target.value)} /><SelectField label="Status filter" id="package-status-filter" value={query.status} onChange={(event) => setQuery({ ...query, page: 1, status: event.target.value as MasterDataStatus })}><option value="all">All</option><option value="active">Active</option><option value="inactive">Inactive</option></SelectField><button type="submit" className="rounded-lg border border-slate-700 px-4 py-2.5 text-sm">Search</button></form>
      {message ? <p role="status" className="px-5 pt-4 text-sm text-emerald-300">{message}</p> : null}
      {packages.isPending ? <p role="status" className="p-8 text-center text-slate-400">Loading packages…</p> : null}
      {packages.isError ? <p role="alert" className="p-8 text-center text-rose-300">We could not load packages.</p> : null}
      {packages.data?.data.length === 0 ? <p className="p-8 text-center text-slate-400">No packages match these filters.</p> : null}
      {packages.data?.data.length ? <div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead className="border-b border-slate-800 text-xs uppercase text-slate-500"><tr><th className="px-5 py-3">Package</th><th className="px-5 py-3">Assigned services</th><th className="px-5 py-3">Status</th><th className="px-5 py-3 text-right">Actions</th></tr></thead><tbody className="divide-y divide-slate-800">{packages.data.data.map((packageItem) => <tr key={packageItem.id}><td className="px-5 py-4 font-medium">{packageItem.name}</td><td className="px-5 py-4 text-slate-400">{packageItem.services.map((service) => service.name).join(', ') || 'Not assigned'}</td><td className={packageItem.is_active ? 'px-5 py-4 text-emerald-300' : 'px-5 py-4 text-slate-500'}>{packageItem.is_active ? 'Active' : 'Inactive'}</td><td className="px-5 py-4"><div className="flex justify-end gap-2"><button type="button" onClick={() => setEditing(packageItem)} className="rounded-lg border border-slate-700 px-3 py-1.5">Edit</button><button type="button" disabled={statusMutation.isPending} onClick={() => statusMutation.mutate(packageItem)} className="rounded-lg border border-slate-700 px-3 py-1.5">{packageItem.is_active ? 'Deactivate' : 'Activate'}</button></div></td></tr>)}</tbody></table></div> : null}
      {packages.data ? <PaginationControls page={packages.data.meta.current_page} lastPage={packages.data.meta.last_page} total={packages.data.meta.total} onPageChange={(page) => setQuery({ ...query, page })} /> : null}
    </div>
    {editing !== undefined ? <Modal title={editing ? 'Edit package' : 'New package'} onClose={() => setEditing(undefined)}><PackageForm packageItem={editing} onCancel={() => setEditing(undefined)} onSaved={(packageItem) => { setEditing(undefined); setMessage(`${packageItem.name} saved.`); void queryClient.invalidateQueries({ queryKey: packagesQueryKey }) }} /></Modal> : null}
  </section>
}
