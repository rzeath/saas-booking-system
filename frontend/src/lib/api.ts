import { z } from 'zod'

const apiBaseUrl = (import.meta.env.VITE_API_BASE_URL || '/api').replace(/\/$/, '')

const authContextSchema = z.object({
  user: z.object({ id: z.number(), name: z.string(), email: z.string() }),
  organization: z.object({ id: z.number(), name: z.string(), status: z.string() }),
})

const businessSettingSchema = z.object({
  display_name: z.string(),
  email: z.string().nullable(),
  phone: z.string().nullable(),
  address: z.string().nullable(),
  logo_path: z.string().nullable(),
  currency: z.string(),
  booking_prefix: z.string(),
  quotation_prefix: z.string(),
  billing_prefix: z.string(),
})

const customerSchema = z.object({
  id: z.number(),
  name: z.string(),
  email: z.string().nullable(),
  phone: z.string().nullable(),
  address: z.string().nullable(),
  notes: z.string().nullable(),
  is_active: z.boolean(),
  created_at: z.string(),
  updated_at: z.string(),
})

const eventTypeSchema = z.object({
  id: z.number(),
  name: z.string(),
  is_active: z.boolean(),
  created_at: z.string(),
  updated_at: z.string(),
})

const serviceSchema = z.object({
  id: z.number(),
  name: z.string(),
  total_units: z.number(),
  is_active: z.boolean(),
  created_at: z.string(),
  updated_at: z.string(),
})

const relatedMasterDataSchema = z.object({
  id: z.number(),
  name: z.string(),
  is_active: z.boolean(),
})

const packageSchema = z.object({
  id: z.number(),
  services: z.array(relatedMasterDataSchema),
  name: z.string(),
  is_active: z.boolean(),
  created_at: z.string(),
  updated_at: z.string(),
})

const serviceRateSchema = z.object({
  id: z.number(),
  event_type: relatedMasterDataSchema,
  service: relatedMasterDataSchema,
  package: relatedMasterDataSchema,
  duration_minutes: z.number(),
  unit_rate: z.string(),
  is_active: z.boolean(),
  is_available: z.boolean(),
  created_at: z.string(),
  updated_at: z.string(),
})

const staffSchema = z.object({
  id: z.number(),
  name: z.string(),
  phone: z.string(),
  email: z.string().nullable(),
  notes: z.string().nullable(),
  is_active: z.boolean(),
  created_at: z.string(),
  updated_at: z.string(),
})

const bookingStatusSchema = z.enum([
  'PENDING',
  'QUOTED',
  'CONFIRMED',
  'COMPLETED',
  'CANCELLED',
])

const bookingServiceSchema = z.object({
  id: z.number(),
  service: z.object({ id: z.number(), name: z.string() }),
  package: z.object({ id: z.number(), name: z.string() }),
  start_at: z.string(),
  end_at: z.string(),
  duration_minutes: z.number(),
  quantity: z.number(),
  unit_rate: z.string(),
  line_total: z.string(),
  sort_order: z.number(),
  staff: z.array(relatedMasterDataSchema),
})

const bookingSchema = z.object({
  id: z.number(),
  booking_number: z.string(),
  status: bookingStatusSchema,
  customer: relatedMasterDataSchema,
  customer_snapshot: z.object({
    name: z.string(),
    email: z.string().nullable(),
    phone: z.string().nullable(),
    address: z.string().nullable(),
  }),
  event_type: relatedMasterDataSchema,
  event_type_snapshot: z.object({ name: z.string() }),
  event_name: z.string(),
  event_date: z.string(),
  venue_name: z.string(),
  venue_address: z.string().nullable(),
  contact_person: z.string(),
  contact_number: z.string(),
  internal_notes: z.string().nullable(),
  booking_services: z.array(bookingServiceSchema),
  cancelled_at: z.string().nullable(),
  cancellation_reason: z.string().nullable(),
  created_at: z.string(),
  updated_at: z.string(),
})

const availabilityServiceSchema = z.object({
  service_id: z.number(),
  available: z.boolean(),
  total_units: z.number(),
  requested_quantity: z.number(),
  required_quantity: z.number(),
  over_capacity_by: z.number(),
})

const bookingAvailabilitySchema = z.object({
  available: z.boolean(),
  services: z.array(availabilityServiceSchema),
})

const staffAvailabilitySchema = z.object({
  staff: z.array(z.object({
    id: z.number(),
    name: z.string(),
    available: z.boolean(),
  })),
})

const paginationSchema = {
  links: z.object({
    prev: z.string().nullable(),
    next: z.string().nullable(),
  }),
  meta: z.object({
    current_page: z.number(),
    last_page: z.number(),
    per_page: z.number(),
    total: z.number(),
  }),
}

const customerPageSchema = z.object({
  data: z.array(customerSchema),
  ...paginationSchema,
})

const eventTypePageSchema = z.object({
  data: z.array(eventTypeSchema),
  ...paginationSchema,
})

const servicePageSchema = z.object({ data: z.array(serviceSchema), ...paginationSchema })
const packagePageSchema = z.object({ data: z.array(packageSchema), ...paginationSchema })
const serviceRatePageSchema = z.object({ data: z.array(serviceRateSchema), ...paginationSchema })
const staffPageSchema = z.object({ data: z.array(staffSchema), ...paginationSchema })
const bookingPageSchema = z.object({ data: z.array(bookingSchema), ...paginationSchema })

const errorResponseSchema = z.object({
  message: z.string().optional(),
  errors: z.record(z.string(), z.array(z.string())).optional(),
})

export type AuthContext = z.infer<typeof authContextSchema>
export type BusinessSetting = z.infer<typeof businessSettingSchema>
export type UpdateBusinessSettingInput = Omit<BusinessSetting, 'logo_path'>
export type Customer = z.infer<typeof customerSchema>
export type CustomerPage = z.infer<typeof customerPageSchema>
export type SaveCustomerInput = Omit<Customer, 'id' | 'created_at' | 'updated_at'>
export type EventType = z.infer<typeof eventTypeSchema>
export type EventTypePage = z.infer<typeof eventTypePageSchema>
export type SaveEventTypeInput = Omit<EventType, 'id' | 'created_at' | 'updated_at'>
export type Service = z.infer<typeof serviceSchema>
export type ServicePage = z.infer<typeof servicePageSchema>
export type SaveServiceInput = Omit<Service, 'id' | 'created_at' | 'updated_at'>
export type Package = z.infer<typeof packageSchema>
export type PackagePage = z.infer<typeof packagePageSchema>
export type SavePackageInput = Pick<Package, 'name' | 'is_active'>
export type ServiceRate = z.infer<typeof serviceRateSchema>
export type ServiceRatePage = z.infer<typeof serviceRatePageSchema>
export type SaveServiceRateInput = {
  event_type_id: number
  service_id: number
  package_id: number
  duration_minutes: number
  unit_rate: string
  is_active: boolean
}
export type Staff = z.infer<typeof staffSchema>
export type StaffPage = z.infer<typeof staffPageSchema>
export type SaveStaffInput = Omit<Staff, 'id' | 'created_at' | 'updated_at'>
export type BookingStatus = z.infer<typeof bookingStatusSchema>
export type BookingService = z.infer<typeof bookingServiceSchema>
export type Booking = z.infer<typeof bookingSchema>
export type BookingPage = z.infer<typeof bookingPageSchema>
export type BookingAvailability = z.infer<typeof bookingAvailabilitySchema>
export type StaffAvailability = z.infer<typeof staffAvailabilitySchema>
export type SaveBookingServiceInput = {
  id?: number
  service_id: number
  package_id: number
  start_time: string
  duration_minutes: number
  quantity: number
  staff_ids: number[]
}
export type SaveBookingInput = {
  customer_id: number
  event_type_id: number
  event_name: string
  event_date: string
  venue_name: string
  venue_address: string | null
  contact_person: string
  contact_number: string
  internal_notes: string | null
  booking_services: SaveBookingServiceInput[]
}
export type BookingAvailabilityInput = {
  booking_id?: number
  event_date: string
  booking_services: Omit<SaveBookingServiceInput, 'staff_ids'>[]
}
export type StaffAvailabilityInput = {
  booking_service_id?: number
  event_date: string
  start_time: string
  duration_minutes: number
}
export type BookingQuery = {
  page: number
  search: string
  status: BookingStatus | ''
  event_date_from: string
  event_date_to: string
  customer_id: number
  event_type_id: number
  per_page?: number
}
export type MasterDataStatus = 'active' | 'inactive' | 'all'
export type MasterDataQuery = {
  page: number
  search: string
  status: MasterDataStatus
  per_page?: number
}
export type ServiceRateQuery = MasterDataQuery & {
  service_id?: number
  package_id?: number
  event_type_id?: number
  duration_minutes?: number
}
export type LoginInput = { email: string; password: string }
export type RegisterInput = {
  business_name: string
  admin_name: string
  email: string
  password: string
  password_confirmation: string
}

export class ApiError extends Error {
  readonly status: number
  readonly fieldErrors: Record<string, string[]>

  constructor(
    message: string,
    status: number,
    fieldErrors: Record<string, string[]> = {},
  ) {
    super(message)
    this.status = status
    this.fieldErrors = fieldErrors
  }
}

function csrfToken(): string | undefined {
  const cookie = document.cookie.split('; ').find((entry) => entry.startsWith('XSRF-TOKEN='))
  return cookie ? decodeURIComponent(cookie.split('=').slice(1).join('=')) : undefined
}

async function request(path: string, init: RequestInit = {}): Promise<Response> {
  const token = csrfToken()
  const response = await fetch(`${apiBaseUrl}${path}`, {
    ...init,
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      ...(init.body ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { 'X-XSRF-TOKEN': token } : {}),
      ...init.headers,
    },
  })

  if (!response.ok) {
    const parsed = errorResponseSchema.safeParse(await response.json().catch(() => ({})))
    const error = parsed.success ? parsed.data : {}
    throw new ApiError(error.message || 'The request could not be completed.', response.status, error.errors)
  }

  return response
}

async function initializeCsrf(): Promise<void> {
  const response = await fetch('/sanctum/csrf-cookie', {
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  })

  if (!response.ok) throw new ApiError('Unable to initialize a secure session.', response.status)
}

export async function getCurrentAuth(): Promise<AuthContext | null> {
  try {
    const response = await request('/me')
    return authContextSchema.parse(await response.json())
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) return null
    throw error
  }
}

export async function login(input: LoginInput): Promise<AuthContext> {
  await initializeCsrf()
  const response = await request('/auth/login', { method: 'POST', body: JSON.stringify(input) })
  return authContextSchema.parse(await response.json())
}

export async function register(input: RegisterInput): Promise<AuthContext> {
  await initializeCsrf()
  const response = await request('/auth/register', { method: 'POST', body: JSON.stringify(input) })
  return authContextSchema.parse(await response.json())
}

export async function logout(): Promise<void> {
  await initializeCsrf()
  await request('/auth/logout', { method: 'POST' })
}

export async function getBusinessSettings(): Promise<BusinessSetting> {
  const response = await request('/business-settings')
  return businessSettingSchema.parse(await response.json())
}

export async function updateBusinessSettings(
  input: UpdateBusinessSettingInput,
): Promise<BusinessSetting> {
  await initializeCsrf()
  const response = await request('/business-settings', {
    method: 'PUT',
    body: JSON.stringify(input),
  })

  return businessSettingSchema.parse(await response.json())
}

function masterDataQueryString(query: MasterDataQuery): string {
  const params = new URLSearchParams({
    page: String(query.page),
    status: query.status,
  })

  if (query.search) params.set('search', query.search)
  if (query.per_page) params.set('per_page', String(query.per_page))

  return params.toString()
}

function serviceRateQueryString(query: ServiceRateQuery): string {
  const params = new URLSearchParams(masterDataQueryString(query))
  if (query.service_id) params.set('service_id', String(query.service_id))
  if (query.package_id) params.set('package_id', String(query.package_id))
  if (query.event_type_id) params.set('event_type_id', String(query.event_type_id))
  if (query.duration_minutes) params.set('duration_minutes', String(query.duration_minutes))
  return params.toString()
}

function bookingQueryString(query: BookingQuery): string {
  const params = new URLSearchParams({ page: String(query.page) })
  if (query.search) params.set('search', query.search)
  if (query.status) params.set('status', query.status)
  if (query.event_date_from) params.set('event_date_from', query.event_date_from)
  if (query.event_date_to) params.set('event_date_to', query.event_date_to)
  if (query.customer_id) params.set('customer_id', String(query.customer_id))
  if (query.event_type_id) params.set('event_type_id', String(query.event_type_id))
  if (query.per_page) params.set('per_page', String(query.per_page))
  return params.toString()
}

export async function getCustomers(query: MasterDataQuery): Promise<CustomerPage> {
  const response = await request(`/customers?${masterDataQueryString(query)}`)
  return customerPageSchema.parse(await response.json())
}

export async function createCustomer(input: SaveCustomerInput): Promise<Customer> {
  await initializeCsrf()
  const response = await request('/customers', {
    method: 'POST',
    body: JSON.stringify(input),
  })

  return customerSchema.parse(await response.json())
}

export async function updateCustomer(id: number, input: SaveCustomerInput): Promise<Customer> {
  await initializeCsrf()
  const response = await request(`/customers/${id}`, {
    method: 'PUT',
    body: JSON.stringify(input),
  })

  return customerSchema.parse(await response.json())
}

export async function getEventTypes(query: MasterDataQuery): Promise<EventTypePage> {
  const response = await request(`/event-types?${masterDataQueryString(query)}`)
  return eventTypePageSchema.parse(await response.json())
}

export async function createEventType(input: SaveEventTypeInput): Promise<EventType> {
  await initializeCsrf()
  const response = await request('/event-types', {
    method: 'POST',
    body: JSON.stringify(input),
  })

  return eventTypeSchema.parse(await response.json())
}

export async function updateEventType(
  id: number,
  input: SaveEventTypeInput,
): Promise<EventType> {
  await initializeCsrf()
  const response = await request(`/event-types/${id}`, {
    method: 'PUT',
    body: JSON.stringify(input),
  })

  return eventTypeSchema.parse(await response.json())
}

export async function getServices(query: MasterDataQuery): Promise<ServicePage> {
  const response = await request(`/services?${masterDataQueryString(query)}`)
  return servicePageSchema.parse(await response.json())
}

export async function createService(input: SaveServiceInput): Promise<Service> {
  await initializeCsrf()
  const response = await request('/services', { method: 'POST', body: JSON.stringify(input) })
  return serviceSchema.parse(await response.json())
}

export async function updateService(id: number, input: SaveServiceInput): Promise<Service> {
  await initializeCsrf()
  const response = await request(`/services/${id}`, { method: 'PUT', body: JSON.stringify(input) })
  return serviceSchema.parse(await response.json())
}

export async function getPackages(query: MasterDataQuery): Promise<PackagePage> {
  const response = await request(`/packages?${masterDataQueryString(query)}`)
  return packagePageSchema.parse(await response.json())
}

export async function getServicePackages(serviceId: number, query: MasterDataQuery): Promise<PackagePage> {
  const response = await request(`/services/${serviceId}/packages?${masterDataQueryString(query)}`)
  return packagePageSchema.parse(await response.json())
}

async function collectPackagePages(
  query: MasterDataQuery,
  loadPage: (pageQuery: MasterDataQuery) => Promise<PackagePage>,
): Promise<Package[]> {
  const packages: Package[] = []
  let page = 1
  let lastPage = 1

  do {
    const result = await loadPage({ ...query, page, per_page: 100 })
    packages.push(...result.data)
    lastPage = result.meta.last_page
    page += 1
  } while (page <= lastPage)

  return packages
}

export function getPackageOptions(query: MasterDataQuery): Promise<Package[]> {
  return collectPackagePages(query, getPackages)
}

export function getServicePackageOptions(serviceId: number, query: MasterDataQuery): Promise<Package[]> {
  return collectPackagePages(query, (pageQuery) => getServicePackages(serviceId, pageQuery))
}

export async function createPackage(input: SavePackageInput): Promise<Package> {
  await initializeCsrf()
  const response = await request('/packages', { method: 'POST', body: JSON.stringify(input) })
  return packageSchema.parse(await response.json())
}

export async function updatePackage(id: number, input: SavePackageInput): Promise<Package> {
  await initializeCsrf()
  const response = await request(`/packages/${id}`, { method: 'PUT', body: JSON.stringify(input) })
  return packageSchema.parse(await response.json())
}

export async function updateServicePackages(serviceId: number, packageIds: number[]): Promise<Package[]> {
  await initializeCsrf()
  const response = await request(`/services/${serviceId}/packages`, {
    method: 'PUT',
    body: JSON.stringify({ package_ids: packageIds }),
  })
  const body = z.object({ data: z.array(packageSchema) }).parse(await response.json())
  return body.data
}

export async function getServiceRates(query: ServiceRateQuery): Promise<ServiceRatePage> {
  const response = await request(`/service-rates?${serviceRateQueryString(query)}`)
  return serviceRatePageSchema.parse(await response.json())
}

export async function createServiceRate(input: SaveServiceRateInput): Promise<ServiceRate> {
  await initializeCsrf()
  const response = await request('/service-rates', { method: 'POST', body: JSON.stringify(input) })
  return serviceRateSchema.parse(await response.json())
}

export async function updateServiceRate(id: number, input: SaveServiceRateInput): Promise<ServiceRate> {
  await initializeCsrf()
  const response = await request(`/service-rates/${id}`, { method: 'PUT', body: JSON.stringify(input) })
  return serviceRateSchema.parse(await response.json())
}

export async function getStaff(query: MasterDataQuery): Promise<StaffPage> {
  const response = await request(`/staff?${masterDataQueryString(query)}`)
  return staffPageSchema.parse(await response.json())
}

export async function createStaff(input: SaveStaffInput): Promise<Staff> {
  await initializeCsrf()
  const response = await request('/staff', { method: 'POST', body: JSON.stringify(input) })
  return staffSchema.parse(await response.json())
}

export async function updateStaff(id: number, input: SaveStaffInput): Promise<Staff> {
  await initializeCsrf()
  const response = await request(`/staff/${id}`, { method: 'PUT', body: JSON.stringify(input) })
  return staffSchema.parse(await response.json())
}

export async function getBookings(query: BookingQuery): Promise<BookingPage> {
  const response = await request(`/bookings?${bookingQueryString(query)}`)
  return bookingPageSchema.parse(await response.json())
}

export async function getBooking(id: number): Promise<Booking> {
  const response = await request(`/bookings/${id}`)
  return bookingSchema.parse(await response.json())
}

export async function createBooking(input: SaveBookingInput): Promise<Booking> {
  await initializeCsrf()
  const response = await request('/bookings', { method: 'POST', body: JSON.stringify(input) })
  return bookingSchema.parse(await response.json())
}

export async function updateBooking(id: number, input: SaveBookingInput): Promise<Booking> {
  await initializeCsrf()
  const response = await request(`/bookings/${id}`, { method: 'PUT', body: JSON.stringify(input) })
  return bookingSchema.parse(await response.json())
}

export async function cancelBooking(id: number, reason: string | null): Promise<Booking> {
  await initializeCsrf()
  const response = await request(`/bookings/${id}/cancel`, {
    method: 'POST',
    body: JSON.stringify({ reason }),
  })
  return bookingSchema.parse(await response.json())
}

export async function checkBookingAvailability(
  input: BookingAvailabilityInput,
): Promise<BookingAvailability> {
  await initializeCsrf()
  const response = await request('/bookings/availability', {
    method: 'POST',
    body: JSON.stringify(input),
  })
  return bookingAvailabilitySchema.parse(await response.json())
}

export async function checkStaffAvailability(
  input: StaffAvailabilityInput,
): Promise<StaffAvailability> {
  await initializeCsrf()
  const response = await request('/bookings/staff-availability', {
    method: 'POST',
    body: JSON.stringify(input),
  })
  return staffAvailabilitySchema.parse(await response.json())
}
