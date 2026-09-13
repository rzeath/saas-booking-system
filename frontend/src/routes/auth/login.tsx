import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { z } from 'zod'

import { FormField } from '@/components/forms/form-field'
import { ApiError, login } from '@/lib/api'
import { authQueryKey } from '@/lib/auth-query'
import { AuthLayout } from '@/routes/auth/auth-layout'

const loginSchema = z.object({
  email: z.string().trim().email('Enter a valid email address.'),
  password: z.string().min(1, 'Password is required.'),
})

type LoginValues = z.infer<typeof loginSchema>

export function LoginRoute() {
  const navigate = useNavigate()
  const location = useLocation()
  const queryClient = useQueryClient()
  const [formError, setFormError] = useState<string>()
  const form = useForm<LoginValues>({ resolver: zodResolver(loginSchema) })
  const mutation = useMutation({
    mutationFn: login,
    onSuccess: (auth) => {
      queryClient.setQueryData(authQueryKey, auth)
      const from = (location.state as { from?: string } | null)?.from
      navigate(from || '/', { replace: true })
    },
    onError: (error) => {
      if (error instanceof ApiError && error.fieldErrors.email?.[0]) {
        form.setError('email', { message: error.fieldErrors.email[0] })
        return
      }
      setFormError(error instanceof Error ? error.message : 'Unable to sign in.')
    },
  })

  return (
    <AuthLayout
      title="Sign in"
      description="Use your organization administrator account."
      alternate={<>New here? <Link className="text-cyan-400 hover:text-cyan-300" to="/register">Create an account</Link></>}
    >
      <form
        className="space-y-5"
        onSubmit={form.handleSubmit((values) => {
          setFormError(undefined)
          mutation.mutate(values)
        })}
        noValidate
      >
        {formError ? <p role="alert" className="rounded-lg bg-rose-950/50 p-3 text-sm text-rose-300">{formError}</p> : null}
        <FormField label="Email" id="email" type="email" autoComplete="email" error={form.formState.errors.email?.message} {...form.register('email')} />
        <FormField label="Password" id="password" type="password" autoComplete="current-password" error={form.formState.errors.password?.message} {...form.register('password')} />
        <button type="submit" disabled={mutation.isPending} className="w-full rounded-lg bg-cyan-500 px-4 py-2.5 font-semibold text-slate-950 transition hover:bg-cyan-400 disabled:cursor-not-allowed disabled:opacity-60">
          {mutation.isPending ? 'Signing in…' : 'Sign in'}
        </button>
      </form>
    </AuthLayout>
  )
}
