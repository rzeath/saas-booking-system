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
export type MasterDataStatus = 'active' | 'inactive' | 'all'
export type MasterDataQuery = {
  page: number
  search: string
  status: MasterDataStatus
  per_page?: number
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
