import { z } from 'zod'

const apiBaseUrl = (import.meta.env.VITE_API_BASE_URL || '/api').replace(/\/$/, '')

const authContextSchema = z.object({
  user: z.object({ id: z.number(), name: z.string(), email: z.string() }),
  organization: z.object({ id: z.number(), name: z.string(), status: z.string() }),
})

const errorResponseSchema = z.object({
  message: z.string().optional(),
  errors: z.record(z.string(), z.array(z.string())).optional(),
})

export type AuthContext = z.infer<typeof authContextSchema>
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
