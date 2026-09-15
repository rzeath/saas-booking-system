import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'

import { PaginationControls } from '@/components/data/pagination-controls'
import { FormField, SelectField } from '@/components/forms/form-field'
import { PackagesRatesModal } from '@/components/services/packages-rates-modal'
import { Modal } from '@/components/ui/modal'
import {
  ApiError,
  createService,
  getServices,
  type MasterDataQuery,
  type MasterDataStatus,
  type Service,
  updateService,
} from '@/lib/api'
import { serviceListQueryKey, servicesQueryKey } from '@/lib/services-query'

const serviceFormSchema = z.object({
  name: z.string().trim().min(1, 'Service name is required.').max(255),
  total_units: z.number().int('Total units must be a whole number.').min(1, 'Total units must be at least 1.'),
  status: z.enum(['active', 'inactive']),
})
type ServiceFormValues = z.infer<typeof serviceFormSchema>
const initialQuery: MasterDataQuery = { page: 1, search: '', status: 'all' }

function ServiceForm({ service, onSaved, onCancel }: {
  service: Service | null
  onSaved: (service: Service) => void
  onCancel: () => void
}) {
  const [message, setMessage] = useState<string>()
  const form = useForm<ServiceFormValues>({
    resolver: zodResolver(serviceFormSchema),
    defaultValues: { name: service?.name ?? '', total_units: service?.total_units ?? 1, status: service?.is_active === false ? 'inactive' : 'active' },
  })
  const mutation = useMutation({
    mutationFn: (values: ServiceFormValues) => {
      const input = { name: values.name, total_units: values.total_units, is_active: values.status === 'active' }
      return service ? updateService(service.id, input) : createService(input)
    },
    onSuccess: onSaved,
    onError: (error) => {
      if (error instanceof ApiError) {
        if (error.fieldErrors.name?.[0]) form.setError('name', { message: error.fieldErrors.name[0] })
        if (error.fieldErrors.total_units?.[0]) form.setError('total_units', { message: error.fieldErrors.total_units[0] })
        if (error.fieldErrors.name || error.fieldErrors.total_units) return
      }
      setMessage(error instanceof Error ? error.message : 'Unable to save service.')
    },
  })

  return <form className="grid gap-5" noValidate onSubmit={form.handleSubmit((values) => { setMessage(undefined); mutation.mutate(values) })}>
    <FormField label="Name" id="service-name" error={form.formState.errors.name?.message} {...form.register('name')} />
    <FormField label="Total units" id="service-total-units" type="number" min="1" error={form.formState.errors.total_units?.message} {...form.register('total_units', { valueAsNumber: true })} />
    <SelectField label="Status" id="service-form-status" error={form.formState.errors.status?.message} {...form.register('status')}><option value="active">Active</option><option value="inactive">Inactive</option></SelectField>
    {message ? <p role="alert" className="text-sm text-rose-300">{message}</p> : null}
    <div className="flex justify-end gap-3"><button type="button" onClick={onCancel} className="rounded-lg border border-slate-700 px-4 py-2 text-sm">Cancel</button><button type="submit" disabled={mutation.isPending} className="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-slate-950 disabled:opacity-60">{mutation.isPending ? 'Saving…' : service ? 'Save changes' : 'Create service'}</button></div>
  </form>
}

export function ServicesRoute({ embedded = false }: { embedded?: boolean } = {}) {
  const queryClient = useQueryClient()
  const [query, setQuery] = useState<MasterDataQuery>(initialQuery)
  const [search, setSearch] = useState('')
  const [editing, setEditing] = useState<Service | null | undefined>()
  const [mappingService, setMappingService] = useState<Service>()
  const [message, setMessage] = useState<string>()
  const services = useQuery({ queryKey: serviceListQueryKey(query), queryFn: () => getServices(query) })
  const statusMutation = useMutation({ mutationFn: (service: Service) => updateService(service.id, { name: service.name, total_units: service.total_units, is_active: !service.is_active }), onSuccess: () => { void queryClient.invalidateQueries({ queryKey: servicesQueryKey }) } })

  return <section>
    <div className="flex flex-wrap items-end justify-between gap-4"><div>{!embedded ? <p className="text-sm font-semibold tracking-[0.08em] text-cyan-400">Catalog</p> : null}{embedded ? <h2 className="text-lg font-semibold">Services</h2> : <h1 className="mt-2 text-3xl font-semibold">Services</h1>}<p className="mt-2 text-sm text-slate-400">Manage bookable services, pooled capacity, package availability, and pricing.</p></div><button type="button" onClick={() => setEditing(null)} className="inline-flex items-center gap-2 rounded-lg bg-cyan-500 px-4 py-2.5 text-sm font-semibold text-slate-950"><Plus className="size-4" /> New service</button></div>
    <div className="mt-6 rounded-2xl border border-slate-800 bg-slate-900">
      <form role="search" className="grid gap-4 border-b border-slate-800 p-5 md:grid-cols-[1fr_12rem_auto] md:items-end" onSubmit={(event) => { event.preventDefault(); setQuery({ ...query, page: 1, search: search.trim() }) }}><FormField label="Search services" id="service-search" value={search} onChange={(event) => setSearch(event.target.value)} /><SelectField label="Status filter" id="service-status-filter" value={query.status} onChange={(event) => setQuery({ ...query, page: 1, status: event.target.value as MasterDataStatus })}><option value="all">All</option><option value="active">Active</option><option value="inactive">Inactive</option></SelectField><button type="submit" className="rounded-lg border border-slate-700 px-4 py-2.5 text-sm">Search</button></form>
      {message ? <p role="status" className="px-5 pt-4 text-sm text-emerald-300">{message}</p> : null}
      {services.isPending ? <p role="status" className="p-8 text-center text-slate-400">Loading services…</p> : null}
      {services.isError ? <p role="alert" className="p-8 text-center text-rose-300">We could not load services.</p> : null}
      {services.data?.data.length === 0 ? <p className="p-8 text-center text-slate-400">No services match these filters.</p> : null}
      {services.data?.data.length ? <div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead className="border-b border-slate-800 text-xs uppercase text-slate-500"><tr><th className="px-5 py-3">Service</th><th className="px-5 py-3">Total units</th><th className="px-5 py-3">Status</th><th className="px-5 py-3 text-right">Actions</th></tr></thead><tbody className="divide-y divide-slate-800">{services.data.data.map((service) => <tr key={service.id}><td className="px-5 py-4 font-medium">{service.name}</td><td className="px-5 py-4">{service.total_units}</td><td className={service.is_active ? 'px-5 py-4 text-emerald-300' : 'px-5 py-4 text-slate-500'}>{service.is_active ? 'Active' : 'Inactive'}</td><td className="px-5 py-4"><div className="flex flex-wrap justify-end gap-2"><button type="button" onClick={() => setMappingService(service)} className="rounded-lg bg-primary-soft px-3 py-1.5 font-semibold text-primary hover:bg-blue-100">Packages &amp; Rates</button><button type="button" onClick={() => setEditing(service)} className="rounded-lg border border-slate-700 px-3 py-1.5">Edit</button><button type="button" disabled={statusMutation.isPending} onClick={() => statusMutation.mutate(service)} className="rounded-lg border border-slate-700 px-3 py-1.5 disabled:opacity-50">{service.is_active ? 'Deactivate' : 'Activate'}</button></div></td></tr>)}</tbody></table></div> : null}
      {services.data ? <PaginationControls page={services.data.meta.current_page} lastPage={services.data.meta.last_page} total={services.data.meta.total} onPageChange={(page) => setQuery({ ...query, page })} /> : null}
    </div>
    {editing !== undefined ? <Modal title={editing ? 'Edit service' : 'New service'} onClose={() => setEditing(undefined)}><ServiceForm service={editing} onCancel={() => setEditing(undefined)} onSaved={(service) => { setEditing(undefined); setMessage(`${service.name} saved.`); void queryClient.invalidateQueries({ queryKey: servicesQueryKey }) }} /></Modal> : null}
    {mappingService ? <PackagesRatesModal service={mappingService} onClose={() => setMappingService(undefined)} /> : null}
  </section>
}
