import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useMemo, useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link } from 'react-router-dom'
import { z } from 'zod'

import { FormField, SelectField, TextAreaField } from '@/components/forms/form-field'
import {
  ApiError,
  getBusinessSettings,
  type BusinessSetting,
  updateBusinessSettings,
} from '@/lib/api'
import { businessSettingsQueryKey } from '@/lib/business-settings-query'

const prefixSchema = z.string().trim().min(1, 'A prefix is required.').max(10, 'Use at most 10 characters.').regex(/^[A-Za-z0-9]+$/, 'Use letters and numbers only.')

const settingsSchema = z.object({
  display_name: z.string().trim().min(1, 'Display name is required.').max(255),
  email: z.union([z.literal(''), z.string().trim().email('Enter a valid email address.').max(255)]),
  phone: z.string().trim().max(50, 'Use at most 50 characters.'),
  address: z.string().trim().max(2000, 'Use at most 2,000 characters.'),
  timezone: z.string().min(1, 'Select a timezone.'),
  currency: z.string().regex(/^[A-Z]{3}$/, 'Select a currency.'),
  booking_prefix: prefixSchema,
  quotation_prefix: prefixSchema,
  billing_prefix: prefixSchema,
})

type SettingsValues = z.infer<typeof settingsSchema>
const settingsFields = new Set<keyof SettingsValues>([
  'display_name',
  'email',
  'phone',
  'address',
  'timezone',
  'currency',
  'booking_prefix',
  'quotation_prefix',
  'billing_prefix',
])

function runtimeValues(key: 'timeZone' | 'currency', fallback: string[]): string[] {
  try {
    return Intl.supportedValuesOf(key)
  } catch {
    return fallback
  }
}

const runtimeTimezones = runtimeValues('timeZone', ['Asia/Manila', 'UTC'])
const runtimeCurrencies = runtimeValues('currency', ['PHP', 'USD'])

function choices(values: string[], current?: string): string[] {
  return [...new Set(current ? [...values, current] : values)].sort()
}

function formValues(settings: BusinessSetting): SettingsValues {
  return {
    display_name: settings.display_name,
    email: settings.email ?? '',
    phone: settings.phone ?? '',
    address: settings.address ?? '',
    timezone: settings.timezone,
    currency: settings.currency,
    booking_prefix: settings.booking_prefix,
    quotation_prefix: settings.quotation_prefix,
    billing_prefix: settings.billing_prefix,
  }
}

export function BusinessSettingsRoute() {
  const queryClient = useQueryClient()
  const [formMessage, setFormMessage] = useState<string>()
  const settingsQuery = useQuery({
    queryKey: businessSettingsQueryKey,
    queryFn: getBusinessSettings,
  })
  const form = useForm<SettingsValues>({
    resolver: zodResolver(settingsSchema),
    defaultValues: {
      display_name: '',
      email: '',
      phone: '',
      address: '',
      timezone: 'Asia/Manila',
      currency: 'PHP',
      booking_prefix: 'BK',
      quotation_prefix: 'QT',
      billing_prefix: 'INV',
    },
  })
  const mutation = useMutation({
    mutationFn: updateBusinessSettings,
    onSuccess: (settings) => {
      queryClient.setQueryData(businessSettingsQueryKey, settings)
      form.reset(formValues(settings))
      setFormMessage('Business settings saved.')
    },
    onError: (error) => {
      if (error instanceof ApiError) {
        let mapped = false
        for (const [field, messages] of Object.entries(error.fieldErrors)) {
          if (settingsFields.has(field as keyof SettingsValues) && messages[0]) {
            form.setError(field as keyof SettingsValues, { message: messages[0] })
            mapped = true
          }
        }
        if (mapped) return
      }
      setFormMessage(error instanceof Error ? error.message : 'Unable to save business settings.')
    },
  })

  useEffect(() => {
    if (settingsQuery.data) form.reset(formValues(settingsQuery.data))
  }, [form, settingsQuery.data])

  const timezoneOptions = useMemo(
    () => choices(runtimeTimezones, settingsQuery.data?.timezone),
    [settingsQuery.data?.timezone],
  )
  const currencyOptions = useMemo(
    () => choices(runtimeCurrencies, settingsQuery.data?.currency),
    [settingsQuery.data?.currency],
  )

  if (settingsQuery.isPending) {
    return <main className="grid min-h-screen place-items-center bg-slate-950 text-slate-300"><p role="status">Loading business settings…</p></main>
  }

  if (settingsQuery.isError) {
    return (
      <main className="grid min-h-screen place-items-center bg-slate-950 px-6 text-slate-100">
        <div className="text-center">
          <p role="alert" className="text-rose-300">We could not load your business settings.</p>
          <button type="button" onClick={() => { void settingsQuery.refetch() }} className="mt-4 rounded-lg border border-slate-700 px-4 py-2 text-sm hover:bg-slate-800">Try again</button>
        </div>
      </main>
    )
  }

  return (
    <main className="min-h-screen bg-slate-950 px-6 py-12 text-slate-100">
      <section className="mx-auto w-full max-w-3xl rounded-2xl border border-slate-800 bg-slate-900 p-8 shadow-2xl shadow-black/20">
        <Link className="text-sm font-medium text-cyan-400 hover:text-cyan-300" to="/">← Back to overview</Link>
        <p className="mt-6 text-sm font-semibold tracking-[0.08em] text-cyan-400">TakdaOps</p>
        <h1 className="mt-2 text-3xl font-semibold tracking-tight">Business settings</h1>
        <p className="mt-2 text-sm leading-6 text-slate-400">Control the identity and defaults used by future business documents. Your canonical Organization name is unchanged.</p>

        <form
          className="mt-8 space-y-8"
          onSubmit={form.handleSubmit((values) => {
            setFormMessage(undefined)
            mutation.mutate({
              ...values,
              email: values.email || null,
              phone: values.phone || null,
              address: values.address || null,
              currency: values.currency.toUpperCase(),
              booking_prefix: values.booking_prefix.toUpperCase(),
              quotation_prefix: values.quotation_prefix.toUpperCase(),
              billing_prefix: values.billing_prefix.toUpperCase(),
            })
          })}
          noValidate
        >
          <fieldset className="grid gap-5 md:grid-cols-2">
            <legend className="mb-4 text-lg font-semibold">Business identity</legend>
            <div className="md:col-span-2"><FormField label="Display Name" id="display-name" error={form.formState.errors.display_name?.message} {...form.register('display_name')} /></div>
            <FormField label="Business Email" id="business-email" type="email" error={form.formState.errors.email?.message} {...form.register('email')} />
            <FormField label="Business Phone" id="business-phone" error={form.formState.errors.phone?.message} {...form.register('phone')} />
            <div className="md:col-span-2"><TextAreaField label="Business Address" id="business-address" error={form.formState.errors.address?.message} {...form.register('address')} /></div>
          </fieldset>

          <fieldset className="grid gap-5 md:grid-cols-2">
            <legend className="mb-4 text-lg font-semibold">Regional defaults</legend>
            <SelectField label="Timezone" id="timezone" error={form.formState.errors.timezone?.message} {...form.register('timezone')}>
              {timezoneOptions.map((timezone) => <option key={timezone} value={timezone}>{timezone}</option>)}
            </SelectField>
            <SelectField label="Currency" id="currency" error={form.formState.errors.currency?.message} {...form.register('currency')}>
              {currencyOptions.map((currency) => <option key={currency} value={currency}>{currency}</option>)}
            </SelectField>
          </fieldset>

          <fieldset className="grid gap-5 md:grid-cols-3">
            <legend className="mb-4 text-lg font-semibold">Document prefixes</legend>
            <FormField label="Booking Prefix" id="booking-prefix" maxLength={10} error={form.formState.errors.booking_prefix?.message} {...form.register('booking_prefix')} />
            <FormField label="Quotation Prefix" id="quotation-prefix" maxLength={10} error={form.formState.errors.quotation_prefix?.message} {...form.register('quotation_prefix')} />
            <FormField label="Billing Prefix" id="billing-prefix" maxLength={10} error={form.formState.errors.billing_prefix?.message} {...form.register('billing_prefix')} />
          </fieldset>

          {formMessage ? <p role="status" className={mutation.isError ? 'text-sm text-rose-300' : 'text-sm text-emerald-300'}>{formMessage}</p> : null}
          <button type="submit" disabled={mutation.isPending} className="rounded-lg bg-cyan-500 px-5 py-2.5 font-semibold text-slate-950 transition hover:bg-cyan-400 disabled:cursor-not-allowed disabled:opacity-60">
            {mutation.isPending ? 'Saving…' : 'Save settings'}
          </button>
        </form>
      </section>
    </main>
  )
}
