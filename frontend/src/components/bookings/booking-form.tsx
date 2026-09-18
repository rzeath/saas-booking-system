import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Trash2 } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'
import { Controller, useFieldArray, useForm, useWatch, type UseFormReturn } from 'react-hook-form'
import { Link, useNavigate } from 'react-router-dom'
import { z } from 'zod'

import { CustomerPicker } from '@/components/bookings/customer-picker'
import { FormField, SelectField, TextAreaField } from '@/components/forms/form-field'
import { Button } from '@/components/ui/button'
import { buttonVariants } from '@/components/ui/button-variants'
import {
  ApiError,
  checkBookingAvailability,
  checkStaffAvailability,
  createBooking,
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
import { durationLabel, formatMoney, multiplyMoney, sumMoney } from '@/lib/booking-format'
import { bookingListsQueryKey, staffAvailabilityQueryKey } from '@/lib/bookings-query'
import { eventTypeListQueryKey } from '@/lib/event-types-query'
import { servicePackageListQueryKey } from '@/lib/packages-query'
import { formatBusinessDate, formatManilaTime } from '@/lib/quotation-format'
import { serviceRateListQueryKey } from '@/lib/service-rates-query'
import { serviceListQueryKey } from '@/lib/services-query'

const serviceLineSchema = z.object({
  id: z.number().optional(),
  service_id: z.number().int().min(1, 'Select a service.'),
  package_id: z.number().int().min(1, 'Select a package.'),
  duration_minutes: z.number().int().min(1, 'Select a duration.'),
  quantity: z.number().int('Quantity must be a whole number.').min(1, 'Quantity must be at least 1.'),
  staff_ids: z.array(z.number().int()),
})

const bookingFormSchema = z.object({
  customer_id: z.number().int().min(1, 'Select a customer.'),
  event_type_id: z.number().int().min(1, 'Select an event type.'),
  event_name: z.string().trim().min(1, 'Event name is required.').max(255),
  event_date: z.string().min(1, 'Event date is required.'),
  start_time: z.string().regex(/^([01]\d|2[0-3]):[0-5]\d$/, 'Enter a valid start time.'),
  venue_name: z.string().trim().min(1, 'Venue name is required.').max(255),
  venue_address: z.string().trim().max(2000, 'Use at most 2,000 characters.'),
  contact_person: z.string().trim().min(1, 'Contact person is required.').max(255),
  contact_number: z.string().trim().min(1, 'Contact number is required.').max(50),
  internal_notes: z.string().trim().max(5000, 'Use at most 5,000 characters.'),
  booking_services: z.array(serviceLineSchema).min(1, 'Add at least one service.'),
})

type BookingFormValues = z.infer<typeof bookingFormSchema>
type ServicePricing = { unitRate: string; lineTotal: string }
const selectorQuery = { page: 1, search: '', status: 'active' as const, per_page: 100 }
const blankService = (): BookingFormValues['booking_services'][number] => ({
  service_id: 0,
  package_id: 0,
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
    start_time: booking?.start_time ?? '',
    venue_name: booking?.venue_name ?? '',
    venue_address: booking?.venue_address ?? '',
    contact_person: booking?.contact_person ?? '',
    contact_number: booking?.contact_number ?? '',
    internal_notes: booking?.internal_notes ?? '',
    booking_services: booking?.booking_services.map((line) => ({
      id: line.id,
      service_id: line.service.id,
      package_id: line.package.id,
      duration_minutes: line.duration_minutes,
      quantity: line.quantity,
      staff_ids: line.staff.map((staff) => staff.id),
    })) ?? [blankService()],
  }
}

function customerFromBooking(booking?: Booking): Customer | undefined {
  return booking ? {
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
}

function appendCurrent<T extends { id: number }>(active: T[], current?: T): T[] {
  return current && !active.some((item) => item.id === current.id) ? [...active, current] : active
}

function availabilityInput(values: BookingFormValues, bookingId?: number) {
  return {
    ...(bookingId ? { booking_id: bookingId } : {}),
    event_date: values.event_date,
    start_time: values.start_time,
    booking_services: values.booking_services.map((line) => ({
      ...(line.id ? { id: line.id } : {}),
      service_id: line.service_id,
      package_id: line.package_id,
      duration_minutes: line.duration_minutes,
      quantity: line.quantity,
    })),
  }
}

function derivedEnd(eventDate: string, startTime: string, durationMinutes: number): { date: string; time: string } | null {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(eventDate) || !/^([01]\d|2[0-3]):[0-5]\d$/.test(startTime) || durationMinutes < 1) return null

  const [year, month, day] = eventDate.split('-').map(Number)
  const [hour, minute] = startTime.split(':').map(Number)
  const end = new Date(Date.UTC(year, month - 1, day, hour, minute + durationMinutes))

  return {
    date: end.toISOString().slice(0, 10),
    time: end.toISOString().slice(11, 16),
  }
}

function BookingSummarySchedule({ eventDate, startTime, durationMinutes }: { eventDate: string; startTime: string; durationMinutes: number }) {
  if (!eventDate || !startTime) return <p className="text-sm text-muted">Add the event date and start time.</p>

  const end = derivedEnd(eventDate, startTime, durationMinutes)
  if (!end) {
    return (
      <div>
        <p className="font-medium text-foreground">{formatBusinessDate(eventDate)}</p>
        <p className="mt-0.5 text-sm text-muted">{formatManilaTime(startTime)}</p>
        <p className="mt-1 text-xs text-muted">Select a service duration to calculate the end.</p>
      </div>
    )
  }

  if (end.date === eventDate) {
    return (
      <div>
        <p className="font-medium text-foreground">{formatBusinessDate(eventDate)}</p>
        <p className="mt-0.5 text-sm text-muted">{formatManilaTime(startTime)} – {formatManilaTime(end.time)}</p>
      </div>
    )
  }

  return (
    <div>
      <p className="font-medium text-foreground">{formatBusinessDate(eventDate)} · {formatManilaTime(startTime)}</p>
      <p className="mt-0.5 text-sm text-muted">→ {formatBusinessDate(end.date)} · {formatManilaTime(end.time)}</p>
    </div>
  )
}

function BookingServiceFields({
  form,
  index,
  fieldKey,
  services,
  eventTypeId,
  savedLine,
  eventDate,
  startTime,
  onRemove,
  onScheduleChange,
  onPricingChange,
}: {
  form: UseFormReturn<BookingFormValues>
  index: number
  fieldKey: string
  services: Service[]
  eventTypeId: number
  savedLine?: BookingService
  eventDate: string
  startTime: string
  onRemove: () => void
  onScheduleChange: () => void
  onPricingChange: (fieldKey: string, pricing?: ServicePricing) => void
}) {
  const serviceId = useWatch({ control: form.control, name: `booking_services.${index}.service_id` })
  const packageId = useWatch({ control: form.control, name: `booking_services.${index}.package_id` })
  const duration = useWatch({ control: form.control, name: `booking_services.${index}.duration_minutes` })
  const quantity = useWatch({ control: form.control, name: `booking_services.${index}.quantity` })
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
  const quantityRegistration = form.register(`booking_services.${index}.quantity`, { valueAsNumber: true })
  const activeStaff = staffAvailability.data?.staff.map((staff) => ({ ...staff, is_active: true })) ?? []
  const staffOptions = savedLine?.staff.reduce(
    (options, staff) => appendCurrent(options, { ...staff, available: true }),
    activeStaff,
  ) ?? activeStaff
  const matchesSavedSelection = Boolean(
    savedLine
    && savedLine.service.id === serviceId
    && savedLine.package.id === packageId
    && savedLine.duration_minutes === duration
    && savedLine.quantity === quantity,
  )
  const unitRate = selectedRate?.unit_rate ?? (matchesSavedSelection ? savedLine?.unit_rate : undefined)
  const lineTotal = selectedRate && Number.isInteger(quantity) && quantity > 0
    ? multiplyMoney(selectedRate.unit_rate, quantity)
    : matchesSavedSelection ? savedLine?.line_total : undefined

  useEffect(() => {
    onPricingChange(fieldKey, unitRate && lineTotal ? { unitRate, lineTotal } : undefined)
    return () => onPricingChange(fieldKey, undefined)
  }, [fieldKey, lineTotal, onPricingChange, unitRate])

  return (
    <fieldset className="border-b border-border py-4 first:pt-0 last:border-b-0 last:pb-0">
      <legend className="sr-only">Service {index + 1}</legend>
      <div className="mb-3 flex items-center justify-between gap-3 border-b border-border pb-2.5">
        <h3 className="text-sm font-semibold text-foreground">Service {index + 1}</h3>
        <Button variant="ghost" size="small" onClick={onRemove} className="text-danger hover:bg-danger-soft hover:text-danger">
          <Trash2 className="size-3.5" aria-hidden="true" /> Remove
        </Button>
      </div>
      <div className="grid gap-3.5 sm:grid-cols-2">
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
        <div className="max-w-36"><FormField label="Quantity" id={`booking-quantity-${fieldKey}`} type="number" min="1" max={selectedService?.total_units} error={errors?.quantity?.message} {...quantityRegistration} onChange={(event) => { onScheduleChange(); void quantityRegistration.onChange(event) }} /></div>
      </div>
      <div className="mt-3 border-t border-border pt-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <span className="text-[13px] font-medium text-foreground">Staff</span>
          {staffAvailability.isPending ? <span role="status" className="text-xs text-muted">Loading staff options…</span> : null}
        </div>
        {staffAvailability.isError ? <p role="alert" className="mt-2 text-sm text-danger">Staff availability could not be loaded.</p> : null}
        {staffOptions.length > 0 ? <Controller name={`booking_services.${index}.staff_ids`} control={form.control} render={({ field }) => (
          <div className="mt-2 flex flex-wrap gap-1.5">
            {staffOptions.map((staff) => {
              const selected = staffIds.includes(staff.id)
              return <label key={staff.id} className={`flex min-h-8 items-center gap-2 rounded-lg border px-2.5 py-1.5 text-xs ${staff.available || selected ? 'border-border bg-surface text-foreground' : 'border-border bg-surface-subtle text-muted'}`}><input type="checkbox" className="size-3.5 accent-primary" checked={selected} disabled={!staff.available && !selected} onChange={(event) => {
                const next = event.target.checked
                  ? [...field.value, staff.id]
                  : field.value.filter((id) => id !== staff.id)
                field.onChange(next)
              }} /> <span>{staff.name}{staff.is_active ? '' : ' (current; inactive)'}{staff.available ? '' : ' · Unavailable'}</span></label>
            })}
          </div>
        )} /> : !staffAvailability.isPending && staffAvailability.isFetched ? <p className="mt-2 text-sm text-muted">No active staff records are available.</p> : null}
        {errors?.staff_ids?.message ? <p role="alert" className="mt-2 text-sm text-danger">{errors.staff_ids.message}</p> : null}
      </div>
      <div className="mt-3 grid grid-cols-2 gap-4 border-t border-border pt-3">
        <div>
          <p className="text-xs font-medium text-muted">Rate</p>
          <p className="mt-1 font-semibold tabular-nums text-foreground">{unitRate ? formatMoney(unitRate) : 'Unavailable'}</p>
        </div>
        <div className="text-right">
          <p className="text-xs font-medium text-muted">Line Total</p>
          <p className="mt-1 font-semibold tabular-nums text-foreground">{lineTotal ? formatMoney(lineTotal) : 'Unavailable'}</p>
        </div>
        {savedLine ? <p className="col-span-2 border-t border-border pt-2 text-xs text-muted">Saved snapshot: {formatMoney(savedLine.unit_rate)} each · {formatMoney(savedLine.line_total)} total</p> : null}
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
  const [chosenCustomer, setChosenCustomer] = useState<Customer>()
  const [servicePricing, setServicePricing] = useState<Record<string, ServicePricing>>({})
  const form = useForm<BookingFormValues>({ resolver: zodResolver(bookingFormSchema), defaultValues: valuesFromBooking(booking) })
  const fields = useFieldArray({ control: form.control, name: 'booking_services' })
  const customerId = useWatch({ control: form.control, name: 'customer_id' })
  const eventTypeId = useWatch({ control: form.control, name: 'event_type_id' })
  const eventDate = useWatch({ control: form.control, name: 'event_date' })
  const startTime = useWatch({ control: form.control, name: 'start_time' })
  const serviceLines = useWatch({ control: form.control, name: 'booking_services' })
  const eventTypes = useQuery({ queryKey: eventTypeListQueryKey(selectorQuery), queryFn: () => getEventTypes(selectorQuery) })
  const services = useQuery({ queryKey: serviceListQueryKey(selectorQuery), queryFn: () => getServices(selectorQuery) })
  const currentEventType: EventType | undefined = booking ? { ...booking.event_type, created_at: '', updated_at: '' } : undefined
  const eventTypeOptions = appendCurrent(eventTypes.data?.data ?? [], currentEventType)
  const savedServiceOptions = booking?.booking_services.map((line) => ({ id: line.service.id, name: line.service.name, total_units: 0, is_active: false, created_at: '', updated_at: '' })) ?? []
  const serviceOptions = savedServiceOptions.reduce((items, service) => appendCurrent(items, service), services.data?.data ?? [])
  const savedCustomer = customerFromBooking(booking)
  const selectedCustomer = chosenCustomer?.id === customerId
    ? chosenCustomer
    : savedCustomer?.id === customerId ? savedCustomer : undefined
  const updateServicePricing = useCallback((fieldKey: string, pricing?: ServicePricing) => {
    setServicePricing((current) => {
      if (!pricing) {
        if (!(fieldKey in current)) return current
        const next = { ...current }
        delete next[fieldKey]
        return next
      }

      if (current[fieldKey]?.unitRate === pricing.unitRate && current[fieldKey]?.lineTotal === pricing.lineTotal) return current
      return { ...current, [fieldKey]: pricing }
    })
  }, [])
  const maximumDuration = Math.max(0, ...serviceLines.map((line) => line.duration_minutes || 0))
  const configuredServiceCount = serviceLines.filter((line) => line.service_id > 0).length
  const activeLineTotals = fields.fields.map((field) => servicePricing[field.id]?.lineTotal).filter((value): value is string => Boolean(value))
  const summaryTotal = fields.fields.length > 0 && activeLineTotals.length === fields.fields.length
    ? formatMoney(sumMoney(activeLineTotals))
    : '—'

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

  const loadingOptions = eventTypes.isPending || services.isPending
  const optionError = eventTypes.isError || services.isError
  const rootServiceError = form.formState.errors.booking_services?.root?.message ?? form.formState.errors.booking_services?.message
  const eventDateRegistration = form.register('event_date')
  const startTimeRegistration = form.register('start_time')

  return (
    <form noValidate className="mt-5 grid max-w-[1500px] gap-4 xl:grid-cols-[minmax(0,3fr)_minmax(17rem,1fr)] xl:items-start" onSubmit={form.handleSubmit((values) => {
      setMessage(undefined)
      saveMutation.mutate({ ...values, venue_address: values.venue_address || null, internal_notes: values.internal_notes || null })
    })}>
      <div className="min-w-0 space-y-4">
        {optionError ? <p role="alert" className="rounded-lg border border-danger/20 bg-danger-soft px-5 py-3 text-sm text-danger">Some booking choices could not be loaded. Refresh before continuing.</p> : null}

        <section aria-labelledby="booking-customer-heading" className="rounded-xl border border-border bg-surface p-5">
          <div>
            <h2 id="booking-customer-heading" className="text-base font-semibold text-foreground">Customer</h2>
            <p className="mt-1 text-sm text-muted">Select the customer for this booking.</p>
          </div>
          <div className="mt-4">
            <Controller name="customer_id" control={form.control} render={({ field }) => (
              <CustomerPicker
                value={field.value}
                selectedCustomer={selectedCustomer}
                error={form.formState.errors.customer_id?.message}
                disabled={saveMutation.isPending}
                onSelect={(customer) => {
                  field.onChange(customer.id)
                  setChosenCustomer(customer)
                  if (form.getValues('contact_person').trim() === '') {
                    form.setValue('contact_person', customer.name, { shouldValidate: true })
                  }
                  if (form.getValues('contact_number').trim() === '') {
                    form.setValue('contact_number', customer.phone ?? '', { shouldValidate: true })
                  }
                }}
              />
            )} />
          </div>
        </section>

        <section aria-labelledby="booking-event-heading" className="rounded-xl border border-border bg-surface p-5">
          <div>
            <h2 id="booking-event-heading" className="text-base font-semibold text-foreground">Event Details</h2>
            <p className="mt-1 text-sm text-muted">Set the shared event schedule and venue.</p>
          </div>
          <div className="mt-4 grid gap-x-4 gap-y-3 sm:grid-cols-2">
            <Controller name="event_type_id" control={form.control} render={({ field }) => <SelectField label="Event type" id="booking-event-type" disabled={loadingOptions} error={form.formState.errors.event_type_id?.message} {...field} onChange={(event) => {
              setAvailabilityIsStale(true)
              field.onChange(Number(event.target.value))
              form.getValues('booking_services').forEach((_, index) => form.setValue(`booking_services.${index}.duration_minutes`, 0, { shouldValidate: true }))
            }}><option value="0">Select event type</option>{eventTypeOptions.map((item) => <option key={item.id} value={item.id}>{item.name}{item.is_active ? '' : ' (current; inactive)'}</option>)}</SelectField>} />
            <FormField label="Event name / occasion" id="booking-event-name" error={form.formState.errors.event_name?.message} {...form.register('event_name')} />
            <FormField label="Event date" id="booking-event-date" type="date" error={form.formState.errors.event_date?.message} {...eventDateRegistration} onChange={(event) => { setAvailabilityIsStale(true); void eventDateRegistration.onChange(event) }} />
            <FormField label="Start time" id="booking-start-time" type="time" error={form.formState.errors.start_time?.message} {...startTimeRegistration} onChange={(event) => { setAvailabilityIsStale(true); void startTimeRegistration.onChange(event) }} />
            <FormField label="Venue name" id="booking-venue-name" error={form.formState.errors.venue_name?.message} {...form.register('venue_name')} />
            <TextAreaField label="Venue address" id="booking-venue-address" rows={2} className="!min-h-20" error={form.formState.errors.venue_address?.message} {...form.register('venue_address')} />
          </div>
          <div className="mt-4 border-t border-border pt-4">
            <h3 className="text-sm font-semibold text-foreground">Event contact</h3>
            <p className="mt-1 text-xs text-muted">Contact details for coordination on this event.</p>
            <div className="mt-3 grid gap-x-4 gap-y-3 sm:grid-cols-2">
              <FormField label="Contact person" id="booking-contact-person" error={form.formState.errors.contact_person?.message} {...form.register('contact_person')} />
              <FormField label="Contact number" id="booking-contact-number" error={form.formState.errors.contact_number?.message} {...form.register('contact_number')} />
            </div>
          </div>
        </section>

        <section aria-labelledby="booking-services-heading" className="rounded-xl border border-border bg-surface p-5">
          <div>
            <h2 id="booking-services-heading" className="text-base font-semibold text-foreground">Services</h2>
            <p className="mt-1 text-sm text-muted">Add the services included in this booking.</p>
          </div>
          <div className="mt-4 border-t border-border pt-4">
            {fields.fields.map((field, index) => (
              <BookingServiceFields
                key={field.id}
                form={form}
                index={index}
                fieldKey={field.id}
                services={serviceOptions}
                eventTypeId={eventTypeId}
                eventDate={eventDate}
                startTime={startTime}
                savedLine={booking?.booking_services.find((line) => line.id === form.getValues(`booking_services.${index}.id`))}
                onScheduleChange={() => setAvailabilityIsStale(true)}
                onPricingChange={updateServicePricing}
                onRemove={() => {
                  setAvailabilityIsStale(true)
                  updateServicePricing(field.id, undefined)
                  fields.remove(index)
                }}
              />
            ))}
          </div>
          {rootServiceError ? <p role="alert" className="mt-3 text-sm text-danger">{rootServiceError}</p> : null}
          <Button variant="secondary" size="small" className="mt-4" onClick={() => { setAvailabilityIsStale(true); fields.append(blankService()) }}>
            <Plus className="size-4" aria-hidden="true" /> Add Service
          </Button>

          <div className="mt-4 border-t border-border pt-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div>
                <h3 className="text-sm font-semibold text-foreground">Service availability</h3>
                <p className="mt-1 text-xs text-muted">Check capacity for the configured services. Capacity is validated again when saved.</p>
              </div>
              <Button variant="secondary" size="small" disabled={availabilityMutation.isPending} onClick={() => { void form.trigger(['event_date', 'start_time', 'event_type_id', 'booking_services']).then((valid) => { if (valid) availabilityMutation.mutate(availabilityInput(form.getValues(), booking?.id)) }) }}>
                {availabilityMutation.isPending ? 'Checking…' : 'Check availability'}
              </Button>
            </div>
            {availability && availabilityIsStale ? <p role="status" className="mt-3 text-sm text-warning">Availability is stale because the schedule changed. Check again.</p> : null}
            {availability && !availabilityIsStale ? (
              <div role="status" className={`mt-3 rounded-lg border px-4 py-3 text-sm ${availability.available ? 'border-success/20 bg-success-soft text-success' : 'border-danger/20 bg-danger-soft text-danger'}`}>
                <strong>{availability.available ? 'All requested services are available.' : 'One or more services exceed capacity.'}</strong>
                <ul className="mt-1.5 space-y-1 text-xs">
                  {availability.services.map((service) => (
                    <li key={service.service_id}>
                      {serviceOptions.find((option) => option.id === service.service_id)?.name ?? 'Service'}: {service.requested_quantity} requested · {service.total_units} total units{service.available ? '' : ` · ${service.over_capacity_by} over capacity`}
                    </li>
                  ))}
                </ul>
              </div>
            ) : null}
          </div>
        </section>

        <section aria-labelledby="booking-additional-heading" className="rounded-xl border border-border bg-surface p-5">
          <h2 id="booking-additional-heading" className="text-base font-semibold text-foreground">Additional Details</h2>
          <p className="mt-1 text-sm text-muted">Optional internal information for this booking.</p>
          <div className="mt-4"><TextAreaField label="Notes" id="booking-internal-notes" error={form.formState.errors.internal_notes?.message} {...form.register('internal_notes')} /></div>
        </section>

        {message ? <p role="alert" className="rounded-lg border border-danger/20 bg-danger-soft px-5 py-3 text-sm text-danger">{message}</p> : null}
      </div>

      <aside aria-labelledby="booking-summary-heading" className="rounded-xl border border-border bg-surface p-5 xl:sticky xl:top-7">
        <h2 id="booking-summary-heading" className="text-base font-semibold text-foreground">Booking Summary</h2>
        <dl className="mt-4 divide-y divide-border">
          <div className="pb-4">
            <dt className="text-xs font-medium text-muted">Schedule</dt>
            <dd className="mt-1.5"><BookingSummarySchedule eventDate={eventDate} startTime={startTime} durationMinutes={maximumDuration} /></dd>
          </div>
          <div className="py-4">
            <dt className="text-xs font-medium text-muted">Services</dt>
            <dd className="mt-1 font-medium text-foreground">{configuredServiceCount === 0 ? 'No services yet' : `${configuredServiceCount} ${configuredServiceCount === 1 ? 'service' : 'services'}`}</dd>
          </div>
          <div className="py-4">
            <dt className="text-xs font-medium text-muted">Total</dt>
            <dd className="mt-1 text-xl font-semibold tabular-nums text-foreground">{summaryTotal}</dd>
          </div>
        </dl>
        <div className="mt-1 grid gap-2">
          <button type="submit" disabled={saveMutation.isPending || loadingOptions || optionError} className={buttonVariants({ className: 'w-full' })}>
            {saveMutation.isPending ? 'Saving…' : booking ? 'Save Changes' : 'Create Booking'}
          </button>
          <Link to={booking ? `/bookings/${booking.id}` : '/bookings'} className={buttonVariants({ variant: 'secondary', className: 'w-full' })}>Cancel</Link>
        </div>
      </aside>
    </form>
  )
}
