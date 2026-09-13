import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link, useNavigate } from 'react-router-dom'
import { z } from 'zod'

import { FormField } from '@/components/forms/form-field'
import { ApiError, register } from '@/lib/api'
import { authQueryKey } from '@/lib/auth-query'
import { AuthLayout } from '@/routes/auth/auth-layout'

const registerSchema = z.object({
  business_name: z.string().trim().min(1, 'Business name is required.'),
  admin_name: z.string().trim().min(1, 'Admin name is required.'),
  email: z.string().trim().email('Enter a valid email address.'),
  password: z.string().min(8, 'Use at least 8 characters.').regex(/[a-z]/, 'Include a lowercase letter.').regex(/[A-Z]/, 'Include an uppercase letter.').regex(/[0-9]/, 'Include a number.'),
  password_confirmation: z.string().min(1, 'Confirm your password.'),
}).refine((values) => values.password === values.password_confirmation, {
  message: 'Passwords do not match.',
  path: ['password_confirmation'],
})

type RegisterValues = z.infer<typeof registerSchema>
const registerFields = new Set<keyof RegisterValues>(['business_name', 'admin_name', 'email', 'password', 'password_confirmation'])

export function RegisterRoute() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [formError, setFormError] = useState<string>()
  const form = useForm<RegisterValues>({ resolver: zodResolver(registerSchema) })
  const mutation = useMutation({
    mutationFn: register,
    onSuccess: (auth) => {
      queryClient.setQueryData(authQueryKey, auth)
      navigate('/', { replace: true })
    },
    onError: (error) => {
      if (error instanceof ApiError) {
        let mapped = false
        for (const [field, messages] of Object.entries(error.fieldErrors)) {
          if (registerFields.has(field as keyof RegisterValues) && messages[0]) {
            form.setError(field as keyof RegisterValues, { message: messages[0] })
            mapped = true
          }
        }
        if (mapped) return
      }
      setFormError(error instanceof Error ? error.message : 'Unable to create your account.')
    },
  })

  return (
    <AuthLayout
      title="Create your account"
      description="Set up your business and its single administrator account."
      alternate={<>Already registered? <Link className="text-cyan-400 hover:text-cyan-300" to="/login">Sign in</Link></>}
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
        <FormField label="Business Name" id="business-name" autoComplete="organization" error={form.formState.errors.business_name?.message} {...form.register('business_name')} />
        <FormField label="Admin Name" id="admin-name" autoComplete="name" error={form.formState.errors.admin_name?.message} {...form.register('admin_name')} />
        <FormField label="Email" id="email" type="email" autoComplete="email" error={form.formState.errors.email?.message} {...form.register('email')} />
        <FormField label="Password" id="password" type="password" autoComplete="new-password" error={form.formState.errors.password?.message} {...form.register('password')} />
        <FormField label="Confirm Password" id="password-confirmation" type="password" autoComplete="new-password" error={form.formState.errors.password_confirmation?.message} {...form.register('password_confirmation')} />
        <button type="submit" disabled={mutation.isPending} className="w-full rounded-lg bg-cyan-500 px-4 py-2.5 font-semibold text-slate-950 transition hover:bg-cyan-400 disabled:cursor-not-allowed disabled:opacity-60">
          {mutation.isPending ? 'Creating account…' : 'Create account'}
        </button>
      </form>
    </AuthLayout>
  )
}
