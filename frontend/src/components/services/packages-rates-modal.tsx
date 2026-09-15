import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, CircleAlert, Pencil, Plus } from 'lucide-react'
import { useState } from 'react'
import { createPortal } from 'react-dom'
import { useForm } from 'react-hook-form'
import { z } from 'zod'

import { FormField } from '@/components/forms/form-field'
import { Button } from '@/components/ui/button'
import { Modal } from '@/components/ui/modal'
import {
  ApiError,
  createServiceRate,
  getEventTypes,
  getPackageOptions,
  getServicePackageOptions,
  getServiceRates,
  type EventType,
  type Package,
  type Service,
  type ServiceRate,
  updateServicePackages,
  updateServiceRate,
} from '@/lib/api'
import { durationLabel, formatMoney } from '@/lib/booking-format'
import { eventTypesQueryKey } from '@/lib/event-types-query'
import { packagesQueryKey, servicePackageListQueryKey } from '@/lib/packages-query'
import { serviceRateListQueryKey, serviceRatesQueryKey } from '@/lib/service-rates-query'
import { cn } from '@/lib/utils'

const selectorQuery = { page: 1, search: '', status: 'all' as const, per_page: 100 }

const rateSchema = z.object({
  duration_minutes: z.number().int('Duration must be a whole number.').min(1, 'Duration must be at least 1 minute.'),
  unit_rate: z.string().trim().regex(/^\d+(\.\d{1,2})?$/, 'Enter a non-negative price with up to 2 decimal places.'),
})

type RateFormValues = z.infer<typeof rateSchema>

function sameIds(left: number[], right: number[]): boolean {
  if (left.length !== right.length) return false
  const rightIds = new Set(right)
  return left.every((id) => rightIds.has(id))
}

function RateDialog({
  service,
  packageItem,
  eventType,
  rate,
  onClose,
}: {
  service: Service
  packageItem: Package
  eventType: EventType
  rate: ServiceRate | null
  onClose: () => void
}) {
  const queryClient = useQueryClient()
  const [message, setMessage] = useState<string>()
  const form = useForm<RateFormValues>({
    resolver: zodResolver(rateSchema),
    defaultValues: {
      duration_minutes: rate?.duration_minutes ?? 180,
      unit_rate: rate?.unit_rate ?? '',
    },
  })
  const mutation = useMutation({
    mutationFn: (values: RateFormValues) => {
      const input = {
        event_type_id: eventType.id,
        service_id: service.id,
        package_id: packageItem.id,
        duration_minutes: values.duration_minutes,
        unit_rate: values.unit_rate,
        is_active: rate?.is_active ?? true,
      }

      return rate
        ? updateServiceRate(rate.id, input, { service_id: service.id, package_id: packageItem.id })
        : createServiceRate(input)
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: serviceRatesQueryKey })
      onClose()
    },
    onError: (error) => {
      if (error instanceof ApiError) {
        const durationError = error.fieldErrors.duration_minutes?.[0]
        const priceError = error.fieldErrors.unit_rate?.[0]
        if (durationError) form.setError('duration_minutes', { message: durationError })
        if (priceError) form.setError('unit_rate', { message: priceError })
        if (durationError || priceError) return
      }
      setMessage(error instanceof Error ? error.message : 'Unable to save rate.')
    },
  })

  return createPortal(
    <Modal
      title={rate ? 'Edit rate' : 'Add rate'}
      description={`${service.name} · ${packageItem.name} · ${eventType.name}`}
      onClose={onClose}
    >
      <form
        className="grid gap-5"
        noValidate
        onSubmit={form.handleSubmit((values) => {
          setMessage(undefined)
          mutation.mutate(values)
        })}
      >
        <FormField
          label="Duration (minutes)"
          id="context-rate-duration"
          type="number"
          min="1"
          error={form.formState.errors.duration_minutes?.message}
          {...form.register('duration_minutes', { valueAsNumber: true })}
        />
        <FormField
          label="Price"
          id="context-rate-price"
          inputMode="decimal"
          placeholder="0.00"
          error={form.formState.errors.unit_rate?.message}
          {...form.register('unit_rate')}
        />
        {message ? <p role="alert" className="text-sm text-danger">{message}</p> : null}
        <div className="flex justify-end gap-3">
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={mutation.isPending}>
            {mutation.isPending ? 'Saving...' : rate ? 'Save changes' : 'Add rate'}
          </Button>
        </div>
      </form>
    </Modal>,
    document.body,
  )
}

function PricingPanel({
  service,
  packageItem,
  savedMapped,
  draftMapped,
}: {
  service: Service
  packageItem: Package
  savedMapped: boolean
  draftMapped: boolean
}) {
  const queryClient = useQueryClient()
  const [selectedEventTypeId, setSelectedEventTypeId] = useState<number>()
  const [editingRate, setEditingRate] = useState<ServiceRate | null | undefined>()
  const [message, setMessage] = useState<string>()
  const eventTypes = useQuery({
    queryKey: [...eventTypesQueryKey, 'pricing-selector'],
    queryFn: () => getEventTypes(selectorQuery),
    enabled: savedMapped,
  })
  const availableEventTypes = eventTypes.data?.data ?? []
  const selectedEventType = availableEventTypes.find((item) => item.id === selectedEventTypeId) ?? availableEventTypes[0]
  const rateQuery = {
    ...selectorQuery,
    service_id: service.id,
    package_id: packageItem.id,
    event_type_id: selectedEventType?.id,
  }
  const rates = useQuery({
    queryKey: serviceRateListQueryKey(rateQuery),
    queryFn: () => getServiceRates(rateQuery),
    enabled: savedMapped && Boolean(selectedEventType),
  })
  const statusMutation = useMutation({
    mutationFn: (rate: ServiceRate) => updateServiceRate(rate.id, {
      event_type_id: rate.event_type.id,
      service_id: service.id,
      package_id: packageItem.id,
      duration_minutes: rate.duration_minutes,
      unit_rate: rate.unit_rate,
      is_active: !rate.is_active,
    }, { service_id: service.id, package_id: packageItem.id }),
    onSuccess: () => {
      setMessage(undefined)
      void queryClient.invalidateQueries({ queryKey: serviceRatesQueryKey })
    },
    onError: (error) => setMessage(error instanceof Error ? error.message : 'Unable to update rate status.'),
  })

  return (
    <section aria-label={`Pricing configuration for ${packageItem.name}`} className="min-w-0 lg:pl-6">
      <p className="text-xs font-bold uppercase tracking-[0.14em] text-primary">Pricing configuration</p>
      <div className="mt-1 flex flex-wrap items-center gap-2">
        <h3 className="text-xl font-semibold text-foreground">{packageItem.name}</h3>
        <span className={cn(
          'rounded-full px-2 py-0.5 text-xs font-semibold',
          savedMapped ? 'bg-success-soft text-success' : 'bg-surface-subtle text-muted',
        )}>
          {savedMapped ? 'Mapped' : 'Not mapped'}
        </span>
        {savedMapped !== draftMapped ? (
          <span className="rounded-full bg-warning-soft px-2 py-0.5 text-xs font-semibold text-warning">
            {draftMapped ? 'Pending mapping' : 'Pending removal'}
          </span>
        ) : null}
      </div>

      {!savedMapped ? (
        <div className="mt-6 border-l-4 border-warning bg-warning-soft px-4 py-3 text-sm text-warning">
          Map this Package and save your changes before configuring rates.
        </div>
      ) : (
        <>
          {eventTypes.isPending ? <p role="status" className="mt-6 text-sm text-muted">Loading event types...</p> : null}
          {eventTypes.isError ? <p role="alert" className="mt-6 text-sm text-danger">We could not load event types.</p> : null}
          {eventTypes.data && availableEventTypes.length === 0 ? <p className="mt-6 text-sm text-muted">Create an Event Type before adding rates.</p> : null}
          {availableEventTypes.length > 0 ? (
            <div className="mt-5 flex flex-wrap gap-2" role="tablist" aria-label="Event Types for pricing">
              {availableEventTypes.map((eventType) => (
                <button
                  key={eventType.id}
                  type="button"
                  role="tab"
                  aria-selected={selectedEventType?.id === eventType.id}
                  onClick={() => setSelectedEventTypeId(eventType.id)}
                  className={cn(
                    'min-h-8 rounded-full border px-3 py-1 text-xs font-semibold transition-colors',
                    selectedEventType?.id === eventType.id
                      ? 'border-primary bg-primary-soft text-primary'
                      : 'border-border bg-surface text-muted hover:text-foreground',
                  )}
                >
                  {eventType.name}
                </button>
              ))}
            </div>
          ) : null}

          {selectedEventType ? (
            <div className="mt-5">
              <div className="flex items-center justify-between gap-3">
                <p className="text-sm font-medium text-foreground">{selectedEventType.name} rates</p>
                <Button size="small" onClick={() => setEditingRate(null)}>
                  <Plus className="size-3.5" aria-hidden="true" /> Add Rate
                </Button>
              </div>
              {message ? <p role="alert" className="mt-3 text-sm text-danger">{message}</p> : null}
              {rates.isPending ? <p role="status" className="py-8 text-center text-sm text-muted">Loading rates...</p> : null}
              {rates.isError ? <p role="alert" className="py-8 text-center text-sm text-danger">We could not load rates.</p> : null}
              {rates.data?.data.length === 0 ? <p className="py-8 text-center text-sm text-muted">No rates configured for this Event Type.</p> : null}
              {rates.data?.data.length ? (
                <div className="mt-3 overflow-x-auto border-y border-border">
                  <table className="w-full text-left text-sm">
                    <thead className="bg-surface-subtle text-xs uppercase text-muted">
                      <tr>
                        <th className="px-3 py-2.5">Duration</th>
                        <th className="px-3 py-2.5">Price</th>
                        <th className="px-3 py-2.5 text-right">Actions</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-border">
                      {rates.data.data.map((rate) => (
                        <tr key={rate.id}>
                          <td className="px-3 py-3 font-medium text-foreground">{durationLabel(rate.duration_minutes)}</td>
                          <td className="px-3 py-3">
                            <span className="block font-medium text-foreground">{formatMoney(rate.unit_rate)}</span>
                            {!rate.is_active ? <span className="text-xs text-muted">Inactive</span> : null}
                          </td>
                          <td className="px-3 py-3">
                            <div className="flex justify-end gap-2">
                              <Button variant="secondary" size="small" onClick={() => setEditingRate(rate)}>
                                <Pencil className="size-3.5" aria-hidden="true" /> Edit
                              </Button>
                              <Button
                                variant="ghost"
                                size="small"
                                disabled={statusMutation.isPending && statusMutation.variables?.id === rate.id}
                                onClick={() => statusMutation.mutate(rate)}
                              >
                                {rate.is_active ? 'Deactivate' : 'Activate'}
                              </Button>
                            </div>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : null}
            </div>
          ) : null}

          {editingRate !== undefined && selectedEventType ? (
            <RateDialog
              service={service}
              packageItem={packageItem}
              eventType={selectedEventType}
              rate={editingRate}
              onClose={() => setEditingRate(undefined)}
            />
          ) : null}
        </>
      )}
    </section>
  )
}

export function PackagesRatesModal({ service, onClose }: { service: Service; onClose: () => void }) {
  const queryClient = useQueryClient()
  const [draftIds, setDraftIds] = useState<number[]>()
  const [selectedPackageId, setSelectedPackageId] = useState<number>()
  const [message, setMessage] = useState<string>()
  const [confirmDiscard, setConfirmDiscard] = useState(false)
  const packages = useQuery({
    queryKey: [...packagesQueryKey, 'options'],
    queryFn: () => getPackageOptions(selectorQuery),
  })
  const mappedPackages = useQuery({
    queryKey: servicePackageListQueryKey(service.id, selectorQuery),
    queryFn: () => getServicePackageOptions(service.id, selectorQuery),
  })
  const savedIds = mappedPackages.data?.map((item) => item.id) ?? []
  const effectiveIds = draftIds ?? savedIds
  const selectedPackage = packages.data?.find((item) => item.id === selectedPackageId) ?? packages.data?.[0]
  const dirty = draftIds !== undefined && !sameIds(draftIds, savedIds)

  const mappingMutation = useMutation({
    mutationFn: () => updateServicePackages(service.id, effectiveIds),
    onSuccess: (updatedPackages) => {
      queryClient.setQueryData(servicePackageListQueryKey(service.id, selectorQuery), updatedPackages)
      void queryClient.invalidateQueries({ queryKey: packagesQueryKey })
      onClose()
    },
    onError: (error) => {
      setDraftIds(undefined)
      setConfirmDiscard(false)
      if (error instanceof ApiError && error.fieldErrors.package_ids?.[0]) {
        setMessage(error.fieldErrors.package_ids[0])
        return
      }
      setMessage(error instanceof Error ? error.message : 'Unable to update package availability.')
    },
  })

  const requestClose = () => {
    if (dirty) {
      setConfirmDiscard(true)
      return
    }
    onClose()
  }

  const toggleMapping = (packageId: number) => {
    setMessage(undefined)
    setConfirmDiscard(false)
    setDraftIds((current) => {
      const ids = current ?? savedIds
      return ids.includes(packageId) ? ids.filter((id) => id !== packageId) : [...ids, packageId]
    })
  }

  return (
    <Modal
      size="large"
      title="Packages & Rates"
      description={`${service.name} · Configure package availability and event-specific duration pricing.`}
      onClose={requestClose}
      footer={
        confirmDiscard ? (
          <>
            <p className="mr-auto self-center text-sm text-warning">Discard unsaved mapping changes?</p>
            <Button variant="secondary" onClick={() => setConfirmDiscard(false)}>Keep editing</Button>
            <Button variant="destructive" onClick={onClose}>Discard changes</Button>
          </>
        ) : (
          <>
            <Button variant="secondary" onClick={requestClose}>Cancel</Button>
            <Button disabled={!dirty || mappingMutation.isPending} onClick={() => mappingMutation.mutate()}>
              {mappingMutation.isPending ? 'Saving...' : 'Save Changes'}
            </Button>
          </>
        )
      }
    >
      {packages.isPending || mappedPackages.isPending ? <p role="status" className="py-12 text-center text-sm text-muted">Loading packages and mappings...</p> : null}
      {packages.isError || mappedPackages.isError ? <p role="alert" className="py-12 text-center text-sm text-danger">We could not load packages and mappings.</p> : null}
      {message ? (
        <div role="alert" className="mb-5 flex gap-3 border-l-4 border-danger bg-danger-soft px-4 py-3 text-sm text-danger">
          <CircleAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
          <span>{message}</span>
        </div>
      ) : null}
      {packages.data?.length === 0 ? <p className="py-12 text-center text-sm text-muted">Create a Package before configuring a Service.</p> : null}
      {packages.data?.length ? (
        <div className="grid gap-6 lg:grid-cols-[minmax(15rem,0.72fr)_minmax(0,1.28fr)] lg:gap-0">
          <section aria-label="Packages" className="min-w-0 lg:border-r lg:border-border lg:pr-6">
            <div className="flex items-start justify-between gap-3">
              <div>
                <h3 className="font-semibold text-foreground">Packages</h3>
                <p className="mt-1 text-sm text-muted">Check availability; select a name to edit pricing.</p>
              </div>
              <span className="shrink-0 rounded-full bg-primary-soft px-2.5 py-1 text-xs font-semibold text-primary">
                {effectiveIds.length} mapped
              </span>
            </div>
            <div className="mt-4 max-h-[28rem] divide-y divide-border overflow-y-auto border-y border-border">
              {packages.data.map((packageItem) => {
                const mapped = effectiveIds.includes(packageItem.id)
                const selected = selectedPackage?.id === packageItem.id

                return (
                  <div key={packageItem.id} className={cn('flex min-h-14 items-center gap-3 px-2 py-2', selected && 'bg-primary-soft')}>
                    <label className="grid size-9 shrink-0 cursor-pointer place-items-center" title={mapped ? 'Remove Package from Service' : 'Make Package available to Service'}>
                      <input
                        type="checkbox"
                        className="size-4 accent-blue-600"
                        checked={mapped}
                        onChange={() => toggleMapping(packageItem.id)}
                        aria-label={`Map ${packageItem.name} to ${service.name}`}
                      />
                    </label>
                    <button
                      type="button"
                      aria-pressed={selected}
                      onClick={() => setSelectedPackageId(packageItem.id)}
                      className="min-w-0 flex-1 rounded-md px-2 py-1 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                      <span className="block truncate text-sm font-semibold text-foreground">{packageItem.name}</span>
                      <span className="text-xs text-muted">{packageItem.is_active ? 'Active' : 'Inactive'}</span>
                    </button>
                    {mapped ? <Check className="size-4 shrink-0 text-success" aria-label="Mapped" /> : null}
                  </div>
                )
              })}
            </div>
          </section>

          {selectedPackage ? (
            <PricingPanel
              key={selectedPackage.id}
              service={service}
              packageItem={selectedPackage}
              savedMapped={savedIds.includes(selectedPackage.id)}
              draftMapped={effectiveIds.includes(selectedPackage.id)}
            />
          ) : null}
        </div>
      ) : null}
    </Modal>
  )
}
