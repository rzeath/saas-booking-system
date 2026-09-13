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
  timezone: z.string(),
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
  service: relatedMasterDataSchema,
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
  package_id: number
  duration_minutes: number
  unit_rate: string
  is_active: boolean
}
export type Staff = z.infer<typeof staffSchema>
export type StaffPage = z.infer<typeof staffPageSchema>
export type SaveStaffInput = Omit<Staff, 'id' | 'created_at' | 'updated_at'>
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

export async function getPackages(serviceId: number, query: MasterDataQuery): Promise<PackagePage> {
  const response = await request(`/services/${serviceId}/packages?${masterDataQueryString(query)}`)
  return packagePageSchema.parse(await response.json())
}

export async function createPackage(serviceId: number, input: SavePackageInput): Promise<Package> {
  await initializeCsrf()
  const response = await request(`/services/${serviceId}/packages`, { method: 'POST', body: JSON.stringify(input) })
  return packageSchema.parse(await response.json())
}

export async function updatePackage(id: number, input: SavePackageInput): Promise<Package> {
  await initializeCsrf()
  const response = await request(`/packages/${id}`, { method: 'PUT', body: JSON.stringify(input) })
  return packageSchema.parse(await response.json())
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
