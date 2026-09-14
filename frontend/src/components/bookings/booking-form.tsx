import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Trash2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Controller, useFieldArray, useForm, useWatch, type UseFormReturn } from 'react-hook-form'
import { Link, useNavigate } from 'react-router-dom'
import { z } from 'zod'

import { FormField, SelectField, TextAreaField } from '@/components/forms/form-field'
import {
  ApiError,
  checkBookingAvailability,
  checkStaffAvailability,
  createBooking,
  getBusinessSettings,
  getCustomers,
  getEventTypes,
  getServicePackageOptions,
  getServiceRates,
  getServices,
  type Booking,
  type BookingAvailability,
  type BookingService,
  type Customer,
  type EventType,
  type SaveBookingInput,
  type Service,
  updateBooking,
} from '@/lib/api'
import { durationLabel, formatMoney, multiplyMoney } from '@/lib/booking-format'
import { bookingListsQueryKey, staffAvailabilityQueryKey } from '@/lib/bookings-query'
import { businessSettingsQueryKey } from '@/lib/business-settings-query'
import { customerListQueryKey } from '@/lib/customers-query'
import { eventTypeListQueryKey } from '@/lib/event-types-query'
import { servicePackageListQueryKey } from '@/lib/packages-query'
import { serviceRateListQueryKey } from '@/lib/service-rates-query'
import { serviceListQueryKey } from '@/lib/services-query'

const serviceLineSchema = z.object({
  id: z.number().optional(),
  service_id: z.number().int().min(1, 'Select a service.'),
  package_id: z.number().int().min(1, 'Select a package.'),
  start_time: z.string().regex(/^([01]\d|2[0-3]):[0-5]\d$/, 'Enter a valid start time.'),
  duration_minutes: z.number().int().min(1, 'Select a duration.'),
  quantity: z.number().int('Quantity must be a whole number.').min(1, 'Quantity must be at least 1.'),
  staff_ids: z.array(z.number().int()),
})

const bookingFormSchema = z.object({
  customer_id: z.number().int().min(1, 'Select a customer.'),
  event_type_id: z.number().int().min(1, 'Select an event type.'),
  event_name: z.string().trim().min(1, 'Event name is required.').max(255),
  event_date: z.string().min(1, 'Event date is required.'),
  venue_name: z.string().trim().min(1, 'Venue name is required.').max(255),
  venue_address: z.string().trim().max(2000, 'Use at most 2,000 characters.'),
  contact_person: z.string().trim().min(1, 'Contact person is required.').max(255),
  contact_number: z.string().trim().min(1, 'Contact number is required.').max(50),
  internal_notes: z.string().trim().max(5000, 'Use at most 5,000 characters.'),
  booking_services: z.array(serviceLineSchema).min(1, 'Add at least one service.'),
})

type BookingFormValues = z.infer<typeof bookingFormSchema>
const selectorQuery = { page: 1, search: '', status: 'active' as const, per_page: 100 }
const blankService = (): BookingFormValues['booking_services'][number] => ({
  service_id: 0,
  package_id: 0,
  start_time: '',
  duration_minutes: 0,
  quantity: 1,
  staff_ids: [],
})

function valuesFromBooking(booking?: Booking): BookingFormValues {
  return {
    customer_id: booking?.customer.id ?? 0,
    event_type_id: booking?.event_type.id ?? 0,
    event_name: booking?.event_name ?? '',
    event_date: booking?.event_date ?? '',
    venue_name: booking?.venue_name ?? '',
    venue_address: booking?.venue_address ?? '',
    contact_person: booking?.contact_person ?? '',
    contact_number: booking?.contact_number ?? '',
    internal_notes: booking?.internal_notes ?? '',
    booking_services: booking?.booking_services.map((line) => ({
      id: line.id,
      service_id: line.service.id,
      package_id: line.package.id,
      start_time: line.start_at.slice(11, 16),
      duration_minutes: line.duration_minutes,
      quantity: line.quantity,
      staff_ids: line.staff.map((staff) => staff.id),
    })) ?? [blankService()],
  }
}

function appendCurrent<T extends { id: number }>(active: T[], current?: T): T[] {
  return current && !active.some((item) => item.id === current.id) ? [...active, current] : active
}

function availabilityInput(values: BookingFormValues, bookingId?: number) {
  return {
    ...(bookingId ? { booking_id: bookingId } : {}),
    event_date: values.event_date,
    booking_services: values.booking_services.map((line) => ({
      ...(line.id ? { id: line.id } : {}),
      service_id: line.service_id,
      package_id: line.package_id,
      start_time: line.start_time,
      duration_minutes: line.duration_minutes,
      quantity: line.quantity,
    })),
  }
}

function BookingServiceFields({
  form,
  index,
  fieldKey,
  services,
  eventTypeId,
  currency,
  savedLine,
  eventDate,
  onRemove,
  onScheduleChange,
}: {
  form: UseFormReturn<BookingFormValues>
  index: number
  fieldKey: string
  services: Service[]
  eventTypeId: number
  currency: string
  savedLine?: BookingService
  eventDate: string
  onRemove: () => void
  onScheduleChange: () => void
}) {
  const serviceId = useWatch({ control: form.control, name: `booking_services.${index}.service_id` })
  const packageId = useWatch({ control: form.control, name: `booking_services.${index}.package_id` })
  const duration = useWatch({ control: form.control, name: `booking_services.${index}.duration_minutes` })
  const quantity = useWatch({ control: form.control, name: `booking_services.${index}.quantity` })
  const startTime = useWatch({ control: form.control, name: `booking_services.${index}.start_time` })
  const staffIds = useWatch({ control: form.control, name: `booking_services.${index}.staff_ids` })
  const packages = useQuery({
    queryKey: servicePackageListQueryKey(serviceId, selectorQuery),
    queryFn: () => getServicePackageOptions(serviceId, selectorQuery),
    enabled: serviceId > 0,
  })
  const rateQuery = { ...selectorQuery, event_type_id: eventTypeId, service_id: serviceId, package_id: packageId }
  const rates = useQuery({
    queryKey: serviceRateListQueryKey(rateQuery),
    queryFn: () => getServiceRates(rateQuery),
    enabled: eventTypeId > 0 && serviceId > 0 && packageId > 0,
  })
  const staffAvailabilityInput = {
    ...(savedLine ? { booking_service_id: savedLine.id } : {}),
    event_date: eventDate,
    start_time: startTime,
    duration_minutes: duration,
  }
  const staffAvailability = useQuery({
    queryKey: staffAvailabilityQueryKey(staffAvailabilityInput),
    queryFn: () => checkStaffAvailability(staffAvailabilityInput),
    enabled: /^\d{4}-\d{2}-\d{2}$/.test(eventDate) && /^([01]\d|2[0-3]):[0-5]\d$/.test(startTime) && duration > 0,
  })
  const currentPackage = savedLine && savedLine.service.id === serviceId ? {
    id: savedLine.package.id,
    services: [{ id: savedLine.service.id, name: savedLine.service.name, is_active: false }],
    name: savedLine.package.name,
    is_active: false,
    created_at: '',
    updated_at: '',
  } : undefined
  const packageOptions = appendCurrent(packages.data ?? [], currentPackage)
  const availableRates = rates.data?.data.filter((rate) => rate.is_available) ?? []
  const selectedRate = availableRates.find((rate) => rate.duration_minutes === duration)
  const durations = [...new Set(availableRates.map((rate) => rate.duration_minutes))].sort((a, b) => a - b)
  if (savedLine && savedLine.service.id === serviceId && savedLine.package.id === packageId && !durations.includes(savedLine.duration_minutes)) {
    durations.push(savedLine.duration_minutes)
    durations.sort((a, b) => a - b)
  }
  const selectedService = services.find((service) => service.id === serviceId)
  const errors = form.formState.errors.booking_services?.[index]
  const startRegistration = form.register(`booking_services.${index}.start_time`)
  const quantityRegistration = form.register(`booking_services.${index}.quantity`, { valueAsNumber: true })
  const activeStaff = staffAvailability.data?.staff.map((staff) => ({ ...staff, is_active: true })) ?? []
  const staffOptions = savedLine?.staff.reduce(
    (options, staff) => appendCurrent(options, { ...staff, available: true }),
    activeStaff,
  ) ?? activeStaff

  return (
    <fieldset className="rounded-xl border border-slate-700 bg-slate-950/40 p-5">
      <legend className="px-2 text-sm font-semibold text-slate-300">Service {index + 1}</legend>
      <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
        <Controller name={`booking_services.${index}.service_id`} control={form.control} render={({ field }) => (
          <SelectField label="Service" id={`booking-service-${fieldKey}`} error={errors?.service_id?.message} {...field} onChange={(event) => {
            onScheduleChange()
            field.onChange(Number(event.target.value))
            form.setValue(`booking_services.${index}.package_id`, 0, { shouldValidate: true })
            form.setValue(`booking_services.${index}.duration_minutes`, 0, { shouldValidate: true })
          }}>
            <option value="0">Select service</option>
            {services.map((service) => <option key={service.id} value={service.id}>{service.name}{service.total_units > 0 ? ` · ${service.total_units} units` : ''}{service.is_active ? '' : ' (current; inactive)'}</option>)}
          </SelectField>
        )} />
        <Controller name={`booking_services.${index}.package_id`} control={form.control} render={({ field }) => (
          <SelectField label="Package" id={`booking-package-${fieldKey}`} disabled={serviceId < 1 || packages.isPending} error={errors?.package_id?.message} {...field} onChange={(event) => {
            onScheduleChange()
            field.onChange(Number(event.target.value))
            form.setValue(`booking_services.${index}.duration_minutes`, 0, { shouldValidate: true })
          }}>
            <option value="0">{packages.isPending ? 'Loading packages…' : 'Select package'}</option>
            {packageOptions.map((item) => <option key={item.id} value={item.id}>{item.name}{item.is_active ? '' : ' (current; inactive)'}</option>)}
          </SelectField>
        )} />
        <Controller name={`booking_services.${index}.duration_minutes`} control={form.control} render={({ field }) => (
          <SelectField label="Duration" id={`booking-duration-${fieldKey}`} disabled={packageId < 1 || eventTypeId < 1 || rates.isPending} error={errors?.duration_minutes?.message} {...field} onChange={(event) => { onScheduleChange(); field.onChange(Number(event.target.value)) }}>
            <option value="0">{rates.isPending ? 'Loading rates…' : 'Select duration'}</option>
            {durations.map((minutes) => <option key={minutes} value={minutes}>{durationLabel(minutes)}{availableRates.some((rate) => rate.duration_minutes === minutes) ? '' : ' (saved; unavailable)'}</option>)}
          </SelectField>
        )} />
        <FormField label="Start time" id={`booking-start-${fieldKey}`} type="time" error={errors?.start_time?.message} {...startRegistration} onChange={(event) => { onScheduleChange(); void startRegistration.onChange(event) }} />
        <FormField label="Quantity" id={`booking-quantity-${fieldKey}`} type="number" min="1" max={selectedService?.total_units} error={errors?.quantity?.message} {...quantityRegistration} onChange={(event) => { onScheduleChange(); void quantityRegistration.onChange(event) }} />
        <div className="rounded-lg border border-slate-800 p-3 text-sm">
          <span className="block text-slate-400">Current configured price</span>
          <strong className="mt-1 block text-slate-100">{selectedRate ? formatMoney(selectedRate.unit_rate, currency) : 'Unavailable'}</strong>
          {selectedRate && Number.isInteger(quantity) && quantity > 0 ? <span className="text-slate-400">Estimated line total: {formatMoney(multiplyMoney(selectedRate.unit_rate, quantity), currency)}</span> : null}
          {savedLine ? <span className="mt-2 block text-xs text-slate-500">Saved snapshot: {formatMoney(savedLine.unit_rate, currency)} each · {formatMoney(savedLine.line_total, currency)} total</span> : null}
        </div>
      </div>
      <div className="mt-5 border-t border-slate-800 pt-5">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <span className="text-sm font-medium text-slate-200">Assigned staff</span>
          {staffAvailability.isPending ? <span role="status" className="text-xs text-slate-500">Checking availability…</span> : null}
        </div>
        {staffAvailability.isError ? <p role="alert" className="mt-2 text-sm text-rose-300">Staff availability could not be loaded.</p> : null}
        {staffOptions.length > 0 ? <Controller name={`booking_services.${index}.staff_ids`} control={form.control} render={({ field }) => (
          <div className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            {staffOptions.map((staff) => {
              const selected = staffIds.includes(staff.id)
              return <label key={staff.id} className={`flex min-h-11 items-center gap-3 rounded-lg border px-3 py-2 text-sm ${staff.available || selected ? 'border-slate-700 text-slate-200' : 'border-slate-800 text-slate-500'}`}><input type="checkbox" checked={selected} disabled={!staff.available && !selected} onChange={(event) => {
                const next = event.target.checked
                  ? [...field.value, staff.id]
                  : field.value.filter((id) => id !== staff.id)
                field.onChange(next)
              }} /> <span>{staff.name}{staff.is_active ? '' : ' (current; inactive)'}{staff.available ? '' : ' · Unavailable'}</span></label>
            })}
          </div>
        )} /> : !staffAvailability.isPending && staffAvailability.isFetched ? <p className="mt-2 text-sm text-slate-500">No active staff records are available.</p> : null}
        {errors?.staff_ids?.message ? <p role="alert" className="mt-2 text-sm text-rose-300">{errors.staff_ids.message}</p> : null}
      </div>
      <div className="mt-4 flex justify-end">
        <button type="button" onClick={onRemove} className="inline-flex items-center gap-2 rounded-lg border border-rose-900 px-3 py-2 text-sm text-rose-300 hover:bg-rose-950/40"><Trash2 className="size-4" aria-hidden="true" /> Remove service</button>
      </div>
    </fieldset>
  )
}

export function BookingForm({ booking }: { booking?: Booking }) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [message, setMessage] = useState<string>()
  const [availability, setAvailability] = useState<BookingAvailability>()
  const [availabilityIsStale, setAvailabilityIsStale] = useState(false)
  const form = useForm<BookingFormValues>({ resolver: zodResolver(bookingFormSchema), defaultValues: valuesFromBooking(booking) })
  const fields = useFieldArray({ control: form.control, name: 'booking_services' })
  const eventTypeId = useWatch({ control: form.control, name: 'event_type_id' })
  const eventDate = useWatch({ control: form.control, name: 'event_date' })
  const customers = useQuery({ queryKey: customerListQueryKey(selectorQuery), queryFn: () => getCustomers(selectorQuery) })
  const eventTypes = useQuery({ queryKey: eventTypeListQueryKey(selectorQuery), queryFn: () => getEventTypes(selectorQuery) })
  const services = useQuery({ queryKey: serviceListQueryKey(selectorQuery), queryFn: () => getServices(selectorQuery) })
  const settings = useQuery({ queryKey: businessSettingsQueryKey, queryFn: getBusinessSettings })
  const currentCustomer: Customer | undefined = booking ? {
    id: booking.customer.id,
    name: booking.customer.name,
    email: booking.customer_snapshot.email,
    phone: booking.customer_snapshot.phone,
    address: booking.customer_snapshot.address,
    notes: null,
    is_active: booking.customer.is_active,
    created_at: '',
    updated_at: '',
  } : undefined
  const customerOptions = appendCurrent(customers.data?.data ?? [], currentCustomer)
  const currentEventType: EventType | undefined = booking ? { ...booking.event_type, created_at: '', updated_at: '' } : undefined
  const eventTypeOptions = appendCurrent(eventTypes.data?.data ?? [], currentEventType)
  const savedServiceOptions = booking?.booking_services.map((line) => ({ id: line.service.id, name: line.service.name, total_units: 0, is_active: false, created_at: '', updated_at: '' })) ?? []
  const serviceOptions = savedServiceOptions.reduce((items, service) => appendCurrent(items, service), services.data?.data ?? [])
  const currency = settings.data?.currency ?? 'PHP'

  useEffect(() => {
    form.reset(valuesFromBooking(booking))
  }, [booking, form])

  const saveMutation = useMutation({
    mutationFn: (input: SaveBookingInput) => booking ? updateBooking(booking.id, input) : createBooking(input),
    onSuccess: (saved) => {
      queryClient.setQueryData(['bookings', 'detail', saved.id], saved)
      void queryClient.invalidateQueries({ queryKey: bookingListsQueryKey })
      navigate(`/bookings/${saved.id}`)
    },
    onError: (error) => {
      setMessage(undefined)
      if (error instanceof ApiError) {
        let mapped = false
        for (const [key, values] of Object.entries(error.fieldErrors)) {
          const path = key.replace(/booking_services\.(\d+)\./, 'booking_services.$1.')
          if (values[0]) {
            form.setError(path as Parameters<typeof form.setError>[0], { message: values[0] })
            mapped = true
          }
        }
        if (mapped) return
      }
      setMessage(error instanceof Error ? error.message : 'Unable to save this booking.')
    },
  })
  const availabilityMutation = useMutation({
    mutationFn: checkBookingAvailability,
    onSuccess: (result) => {
      setAvailability(result)
      setAvailabilityIsStale(false)
      setMessage(undefined)
    },
    onError: (error) => setMessage(error instanceof Error ? error.message : 'Unable to check availability.'),
  })

  const loadingOptions = customers.isPending || eventTypes.isPending || services.isPending || settings.isPending
  const optionError = customers.isError || eventTypes.isError || services.isError || settings.isError
  const rootServiceError = form.formState.errors.booking_services?.root?.message ?? form.formState.errors.booking_services?.message
  const eventDateRegistration = form.register('event_date')

  return (
    <form noValidate className="mt-8 space-y-6" onSubmit={form.handleSubmit((values) => {
      setMessage(undefined)
      saveMutation.mutate({ ...values, venue_address: values.venue_address || null, internal_notes: values.internal_notes || null })
    })}>
      {optionError ? <p role="alert" className="rounded-lg border border-rose-900 bg-rose-950/30 p-4 text-sm text-rose-300">Some booking choices could not be loaded. Refresh before continuing.</p> : null}
      <div className="rounded-2xl border border-slate-800 bg-slate-900 p-6">
        <h2 className="text-lg font-semibold">Event details</h2>
        <div className="mt-5 grid gap-5 md:grid-cols-2">
          <Controller name="customer_id" control={form.control} render={({ field }) => <SelectField label="Customer" id="booking-customer" disabled={loadingOptions} error={form.formState.errors.customer_id?.message} {...field} onChange={(event) => {
            const id = Number(event.target.value)
            field.onChange(id)
            const customer = customerOptions.find((item) => item.id === id)
            if (customer) {
              form.setValue('contact_person', customer.name, { shouldValidate: true })
              form.setValue('contact_number', customer.phone ?? '', { shouldValidate: true })
            }
          }}><option value="0">Select customer</option>{customerOptions.map((customer) => <option key={customer.id} value={customer.id}>{customer.name}{customer.is_active ? '' : ' (current; inactive)'}</option>)}</SelectField>} />
          <Controller name="event_type_id" control={form.control} render={({ field }) => <SelectField label="Event type" id="booking-event-type" disabled={loadingOptions} error={form.formState.errors.event_type_id?.message} {...field} onChange={(event) => {
            setAvailabilityIsStale(true)
            field.onChange(Number(event.target.value))
            form.getValues('booking_services').forEach((_, index) => form.setValue(`booking_services.${index}.duration_minutes`, 0, { shouldValidate: true }))
          }}><option value="0">Select event type</option>{eventTypeOptions.map((item) => <option key={item.id} value={item.id}>{item.name}{item.is_active ? '' : ' (current; inactive)'}</option>)}</SelectField>} />
          <FormField label="Event name / occasion" id="booking-event-name" error={form.formState.errors.event_name?.message} {...form.register('event_name')} />
          <FormField label="Event date" id="booking-event-date" type="date" error={form.formState.errors.event_date?.message} {...eventDateRegistration} onChange={(event) => { setAvailabilityIsStale(true); void eventDateRegistration.onChange(event) }} />
          <FormField label="Venue name" id="booking-venue-name" error={form.formState.errors.venue_name?.message} {...form.register('venue_name')} />
          <FormField label="Contact person" id="booking-contact-person" error={form.formState.errors.contact_person?.message} {...form.register('contact_person')} />
          <FormField label="Contact number" id="booking-contact-number" error={form.formState.errors.contact_number?.message} {...form.register('contact_number')} />
          <div className="md:col-span-2"><TextAreaField label="Venue address" id="booking-venue-address" error={form.formState.errors.venue_address?.message} {...form.register('venue_address')} /></div>
          <div className="md:col-span-2"><TextAreaField label="Internal notes" id="booking-internal-notes" error={form.formState.errors.internal_notes?.message} {...form.register('internal_notes')} /></div>
        </div>
      </div>

      <div className="rounded-2xl border border-slate-800 bg-slate-900 p-6">
        <div className="flex flex-wrap items-center justify-between gap-3"><div><h2 className="text-lg font-semibold">Booking services</h2><p className="mt-1 text-sm text-slate-400">Prices shown are estimates. The server resolves and saves the authoritative rate.</p></div><button type="button" onClick={() => { setAvailabilityIsStale(true); fields.append(blankService()) }} className="inline-flex items-center gap-2 rounded-lg border border-cyan-800 px-3 py-2 text-sm text-cyan-300"><Plus className="size-4" aria-hidden="true" /> Add service</button></div>
        <div className="mt-5 space-y-5">{fields.fields.map((field, index) => <BookingServiceFields key={field.id} form={form} index={index} fieldKey={field.id} services={serviceOptions} eventTypeId={eventTypeId} eventDate={eventDate} currency={currency} savedLine={booking?.booking_services.find((line) => line.id === form.getValues(`booking_services.${index}.id`))} onScheduleChange={() => setAvailabilityIsStale(true)} onRemove={() => { setAvailabilityIsStale(true); fields.remove(index) }} />)}</div>
        {rootServiceError ? <p role="alert" className="mt-3 text-sm text-rose-300">{rootServiceError}</p> : null}
      </div>

      <div className="rounded-2xl border border-slate-800 bg-slate-900 p-6">
        <div className="flex flex-wrap items-center justify-between gap-4"><div><h2 className="text-lg font-semibold">Availability preview</h2><p className="mt-1 text-sm text-slate-400">This preview is advisory. Availability is checked again when the booking is saved.</p></div><button type="button" disabled={availabilityMutation.isPending} onClick={() => { void form.trigger(['event_date', 'event_type_id', 'booking_services']).then((valid) => { if (valid) availabilityMutation.mutate(availabilityInput(form.getValues(), booking?.id)) }) }} className="rounded-lg border border-cyan-800 px-4 py-2 text-sm font-medium text-cyan-300 disabled:opacity-60">{availabilityMutation.isPending ? 'Checking…' : 'Check availability'}</button></div>
        {availability && availabilityIsStale ? <p role="status" className="mt-4 text-sm text-amber-300">Availability is stale because the schedule changed. Check again.</p> : null}
        {availability && !availabilityIsStale ? <div role="status" className={`mt-4 rounded-lg border p-4 text-sm ${availability.available ? 'border-emerald-900 bg-emerald-950/30 text-emerald-300' : 'border-rose-900 bg-rose-950/30 text-rose-300'}`}><strong>{availability.available ? 'All requested services are available.' : 'One or more services exceed capacity.'}</strong><ul className="mt-2 list-disc pl-5">{availability.services.map((service) => <li key={service.service_id}>Service {service.service_id}: {service.requested_quantity} requested, {service.total_units} total units{service.available ? '' : `, ${service.over_capacity_by} over capacity`}</li>)}</ul></div> : null}
      </div>

      {message ? <p role="alert" className="text-sm text-rose-300">{message}</p> : null}
      <div className="flex justify-end gap-3"><Link to={booking ? `/bookings/${booking.id}` : '/bookings'} className="rounded-lg border border-slate-700 px-4 py-2.5 text-sm">Cancel</Link><button type="submit" disabled={saveMutation.isPending || loadingOptions || optionError} className="rounded-lg bg-cyan-500 px-5 py-2.5 text-sm font-semibold text-slate-950 disabled:opacity-60">{saveMutation.isPending ? 'Saving…' : booking ? 'Save changes' : 'Create booking'}</button></div>
    </form>
  )
}
