import { z } from 'zod'

const healthResponseSchema = z.object({
  status: z.literal('ok'),
})

const apiBaseUrl = (import.meta.env.VITE_API_BASE_URL || '/api').replace(/\/$/, '')

export type HealthResponse = z.infer<typeof healthResponseSchema>

export async function getApiHealth(): Promise<HealthResponse> {
  const response = await fetch(`${apiBaseUrl}/health`, {
    headers: {
      Accept: 'application/json',
    },
  })

  if (!response.ok) {
    throw new Error('The API health check failed.')
  }

  return healthResponseSchema.parse(await response.json())
}
