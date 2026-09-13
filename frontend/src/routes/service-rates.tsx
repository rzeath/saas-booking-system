import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useState } from 'react'
import { Controller, useForm, useWatch } from 'react-hook-form'
import { z } from 'zod'

import { PaginationControls } from '@/components/data/pagination-controls'
import { FormField, SelectField } from '@/components/forms/form-field'
import { Modal } from '@/components/ui/modal'
import {
  ApiError,
  createServiceRate,
  getEventTypes,
  getPackages,
  getServiceRates,
  getServices,
  type MasterDataStatus,
  type ServiceRate,
  type ServiceRateQuery,
  updateServiceRate,
} from '@/lib/api'
import { packageListQueryKey } from '@/lib/packages-query'
import { serviceRateListQueryKey, serviceRatesQueryKey } from '@/lib/service-rates-query'

const formSchema = z.object({
  event_type_id: z.number().int().min(1, 'Select an event type.'),
  service_id: z.number().int().min(1, 'Select a service.'),
  package_id: z.number().int().min(1, 'Select a package.'),
  duration_minutes: z.number().int('Duration must be a whole number.').min(1, 'Duration must be at least 1 minute.'),
  unit_rate: z.string().trim().regex(/^\d+(\.\d{1,2})?$/, 'Enter a non-negative price with up to 2 decimal places.'),
  status: z.enum(['active', 'inactive']),
})
type FormValues = z.infer<typeof formSchema>
const selectorQuery = { page: 1, search: '', status: 'all' as const, per_page: 100 }
const initialQuery: ServiceRateQuery = { page: 1, search: '', status: 'all' }

function RateForm({ rate, onSaved, onCancel }: {
  rate: ServiceRate | null
  onSaved: (rate: ServiceRate) => void
  onCancel: () => void
}) {
  const [message, setMessage] = useState<string>()
  const form = useForm<FormValues>({
    resolver: zodResolver(formSchema),
    defaultValues: {
      event_type_id: rate?.event_type.id ?? 0,
      service_id: rate?.service.id ?? 0,
      package_id: rate?.package.id ?? 0,
      duration_minutes: rate?.duration_minutes ?? 180,
      unit_rate: rate?.unit_rate ?? '',
      status: rate?.is_active === false ? 'inactive' : 'active',
    },
  })
  const serviceId = useWatch({ control: form.control, name: 'service_id' })
  const eventTypes = useQuery({ queryKey: ['event-types', 'rate-selector'], queryFn: () => getEventTypes(selectorQuery) })
  const services = useQuery({ queryKey: ['services', 'rate-selector'], queryFn: () => getServices(selectorQuery) })
  const packages = useQuery({
    queryKey: packageListQueryKey(serviceId, selectorQuery),
    queryFn: () => getPackages(serviceId, selectorQuery),
    enabled: serviceId > 0,
  })
  const mutation = useMutation({
    mutationFn: (values: FormValues) => {
      const input = {
        event_type_id: values.event_type_id,
        package_id: values.package_id,
        duration_minutes: values.duration_minutes,
        unit_rate: values.unit_rate,
        is_active: values.status === 'active',
      }
      return rate ? updateServiceRate(rate.id, input) : createServiceRate(input)
    },
    onSuccess: onSaved,
    onError: (error) => {
      if (error instanceof ApiError) {
        let mapped = false
        for (const [key, values] of Object.entries(error.fieldErrors)) {
          const field = key === 'is_active' ? 'status' : key
          if (field in form.getValues() && values[0]) {
            form.setError(field as keyof FormValues, { message: values[0] }); mapped = true
          }
        }
        if (mapped) return
      }
      setMessage(error instanceof Error ? error.message : 'Unable to save service rate.')
    },
  })
  const visibleEventTypes = eventTypes.data?.data.filter((item) => item.is_active || item.id === rate?.event_type.id) ?? []
  const visibleServices = services.data?.data.filter((item) => item.is_active || item.id === rate?.service.id) ?? []
  const visiblePackages = packages.data?.data.filter((item) => item.is_active || item.id === rate?.package.id) ?? []

  return <form className="grid gap-5 md:grid-cols-2" noValidate onSubmit={form.handleSubmit((values) => { setMessage(undefined); mutation.mutate(values) })}>
    <Controller name="event_type_id" control={form.control} render={({ field }) => <SelectField label="Event type" id="rate-event-type" error={form.formState.errors.event_type_id?.message} {...field} onChange={(event) => field.onChange(Number(event.target.value))}><option value="0">Select event type</option>{visibleEventTypes.map((item) => <option key={item.id} value={item.id}>{item.name}{item.is_active ? '' : ' (inactive)'}</option>)}</SelectField>} />
    <Controller name="service_id" control={form.control} render={({ field }) => <SelectField label="Service" id="rate-service" error={form.formState.errors.service_id?.message} {...field} onChange={(event) => { field.onChange(Number(event.target.value)); form.setValue('package_id', 0, { shouldValidate: true }) }}><option value="0">Select service</option>{visibleServices.map((item) => <option key={item.id} value={item.id}>{item.name}{item.is_active ? '' : ' (inactive)'}</option>)}</SelectField>} />
    <Controller name="package_id" control={form.control} render={({ field }) => <SelectField label="Package" id="rate-package" disabled={serviceId < 1} error={form.formState.errors.package_id?.message} {...field} onChange={(event) => field.onChange(Number(event.target.value))}><option value="0">Select package</option>{visiblePackages.map((item) => <option key={item.id} value={item.id}>{item.name}{item.is_active ? '' : ' (inactive)'}</option>)}</SelectField>} />
    <FormField label="Duration (minutes)" id="rate-duration" type="number" min="1" error={form.formState.errors.duration_minutes?.message} {...form.register('duration_minutes', { valueAsNumber: true })} />
    <FormField label="Price" id="rate-price" inputMode="decimal" placeholder="0.00" error={form.formState.errors.unit_rate?.message} {...form.register('unit_rate')} />
    <SelectField label="Status" id="rate-form-status" {...form.register('status')}><option value="active">Active</option><option value="inactive">Inactive</option></SelectField>
    {message ? <p role="alert" className="text-sm text-rose-300 md:col-span-2">{message}</p> : null}
    <div className="flex justify-end gap-3 md:col-span-2"><button type="button" onClick={onCancel} className="rounded-lg border border-slate-700 px-4 py-2 text-sm">Cancel</button><button type="submit" disabled={mutation.isPending} className="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-slate-950 disabled:opacity-60">{mutation.isPending ? 'Saving…' : rate ? 'Save changes' : 'Create service rate'}</button></div>
  </form>
}

export function ServiceRatesRoute() {
  const queryClient = useQueryClient()
  const [query, setQuery] = useState<ServiceRateQuery>(initialQuery)
  const [editing, setEditing] = useState<ServiceRate | null | undefined>()
  const [message, setMessage] = useState<string>()
  const rates = useQuery({ queryKey: serviceRateListQueryKey(query), queryFn: () => getServiceRates(query) })
  const services = useQuery({ queryKey: ['services', 'rate-filter'], queryFn: () => getServices(selectorQuery) })
  const eventTypes = useQuery({ queryKey: ['event-types', 'rate-filter'], queryFn: () => getEventTypes(selectorQuery) })
  const statusMutation = useMutation({
    mutationFn: (rate: ServiceRate) => updateServiceRate(rate.id, { event_type_id: rate.event_type.id, package_id: rate.package.id, duration_minutes: rate.duration_minutes, unit_rate: rate.unit_rate, is_active: !rate.is_active }),
    onSuccess: () => { void queryClient.invalidateQueries({ queryKey: serviceRatesQueryKey }) },
  })
  return <section>
    <div className="flex flex-wrap items-end justify-between gap-4"><div><p className="text-sm font-semibold tracking-[0.08em] text-cyan-400">Pricing</p><h1 className="mt-2 text-3xl font-semibold">Service Rates</h1><p className="mt-2 text-sm text-slate-400">Configure authoritative prices by event type, package, and duration.</p></div><button type="button" onClick={() => setEditing(null)} className="inline-flex items-center gap-2 rounded-lg bg-cyan-500 px-4 py-2.5 text-sm font-semibold text-slate-950"><Plus className="size-4" /> New service rate</button></div>
    <div className="mt-8 rounded-2xl border border-slate-800 bg-slate-900">
      <div className="grid gap-4 border-b border-slate-800 p-5 md:grid-cols-3"><SelectField label="Service filter" id="rate-service-filter" value={query.service_id ?? ''} onChange={(event) => setQuery({ ...query, page: 1, service_id: Number(event.target.value) || undefined, package_id: undefined })}><option value="">All services</option>{services.data?.data.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</SelectField><SelectField label="Event type filter" id="rate-event-type-filter" value={query.event_type_id ?? ''} onChange={(event) => setQuery({ ...query, page: 1, event_type_id: Number(event.target.value) || undefined })}><option value="">All event types</option>{eventTypes.data?.data.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</SelectField><SelectField label="Status filter" id="rate-status-filter" value={query.status} onChange={(event) => setQuery({ ...query, page: 1, status: event.target.value as MasterDataStatus })}><option value="all">All</option><option value="active">Active</option><option value="inactive">Inactive</option></SelectField></div>
      {message ? <p role="status" className="px-5 pt-4 text-sm text-emerald-300">{message}</p> : null}
      {rates.isPending ? <p role="status" className="p-8 text-center text-slate-400">Loading service rates…</p> : null}
      {rates.isError ? <p role="alert" className="p-8 text-center text-rose-300">We could not load service rates.</p> : null}
      {rates.data?.data.length === 0 ? <p className="p-8 text-center text-slate-400">No service rates match these filters.</p> : null}
      {rates.data?.data.length ? <div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead className="border-b border-slate-800 text-xs uppercase text-slate-500"><tr><th className="px-5 py-3">Service / Package</th><th className="px-5 py-3">Event type</th><th className="px-5 py-3">Duration</th><th className="px-5 py-3">Price</th><th className="px-5 py-3">Status</th><th className="px-5 py-3 text-right">Actions</th></tr></thead><tbody className="divide-y divide-slate-800">{rates.data.data.map((rate) => <tr key={rate.id}><td className="px-5 py-4"><span className="block font-medium">{rate.service.name}</span><span className="text-slate-400">{rate.package.name}</span></td><td className="px-5 py-4">{rate.event_type.name}</td><td className="px-5 py-4">{rate.duration_minutes} min</td><td className="px-5 py-4">{rate.unit_rate}</td><td className={rate.is_available ? 'px-5 py-4 text-emerald-300' : 'px-5 py-4 text-slate-500'}>{rate.is_available ? 'Available' : rate.is_active ? 'Dependency inactive' : 'Inactive'}</td><td className="px-5 py-4"><div className="flex justify-end gap-2"><button type="button" onClick={() => setEditing(rate)} className="rounded-lg border border-slate-700 px-3 py-1.5">Edit</button><button type="button" disabled={statusMutation.isPending} onClick={() => statusMutation.mutate(rate)} className="rounded-lg border border-slate-700 px-3 py-1.5">{rate.is_active ? 'Deactivate' : 'Activate'}</button></div></td></tr>)}</tbody></table></div> : null}
      {rates.data ? <PaginationControls page={rates.data.meta.current_page} lastPage={rates.data.meta.last_page} total={rates.data.meta.total} onPageChange={(page) => setQuery({ ...query, page })} /> : null}
    </div>
    {editing !== undefined ? <Modal title={editing ? 'Edit service rate' : 'New service rate'} onClose={() => setEditing(undefined)}><RateForm rate={editing} onCancel={() => setEditing(undefined)} onSaved={(rate) => { setEditing(undefined); setMessage(`${rate.service.name} · ${rate.package.name} rate saved.`); void queryClient.invalidateQueries({ queryKey: serviceRatesQueryKey }) }} /></Modal> : null}
  </section>
}
