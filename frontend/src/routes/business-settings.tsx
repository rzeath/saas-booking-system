import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ImageUp, Save, Trash2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useForm, useWatch } from 'react-hook-form'
import { z } from 'zod'

import { FormField, TextAreaField } from '@/components/forms/form-field'
import { Button } from '@/components/ui/button'
import {
  ApiError,
  getBusinessSettings,
  type BusinessSetting,
  updateBusinessSettings,
} from '@/lib/api'
import { businessInitials, themeAccentOptions } from '@/lib/business-branding'
import { businessSettingsQueryKey } from '@/lib/business-settings-query'
import { cn } from '@/lib/utils'

const prefixSchema = z.string().trim().min(1, 'A prefix is required.').max(10, 'Use at most 10 characters.').regex(/^[A-Za-z0-9]+$/, 'Use letters and numbers only.')
const themeAccentSchema = z.enum(['plum', 'forest', 'terracotta', 'teal', 'indigo', 'graphite'])

const settingsSchema = z.object({
  display_name: z.string().trim().min(1, 'Business name is required.').max(255),
  email: z.union([z.literal(''), z.string().trim().email('Enter a valid email address.').max(255)]),
  phone: z.string().trim().max(50, 'Use at most 50 characters.'),
  address: z.string().trim().max(2000, 'Use at most 2,000 characters.'),
  theme_accent: themeAccentSchema,
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
  'theme_accent',
  'booking_prefix',
  'quotation_prefix',
  'billing_prefix',
])
const acceptedLogoTypes = ['image/jpeg', 'image/png', 'image/webp']
const maxLogoBytes = 2 * 1024 * 1024

function formValues(settings: BusinessSetting): SettingsValues {
  return {
    display_name: settings.display_name,
    email: settings.email ?? '',
    phone: settings.phone ?? '',
    address: settings.address ?? '',
    theme_accent: settings.theme_accent,
    booking_prefix: settings.booking_prefix,
    quotation_prefix: settings.quotation_prefix,
    billing_prefix: settings.billing_prefix,
  }
}

export function BusinessSettingsRoute() {
  const queryClient = useQueryClient()
  const [formMessage, setFormMessage] = useState<string>()
  const [selectedLogo, setSelectedLogo] = useState<File>()
  const [logoPreviewUrl, setLogoPreviewUrl] = useState<string>()
  const [logoError, setLogoError] = useState<string>()
  const [removeLogo, setRemoveLogo] = useState(false)
  const settingsQuery = useQuery({ queryKey: businessSettingsQueryKey, queryFn: getBusinessSettings })
  const form = useForm<SettingsValues>({
    resolver: zodResolver(settingsSchema),
    defaultValues: {
      display_name: '',
      email: '',
      phone: '',
      address: '',
      theme_accent: 'plum',
      booking_prefix: 'BK',
      quotation_prefix: 'QT',
      billing_prefix: 'INV',
    },
  })
  const businessNameValue = useWatch({ control: form.control, name: 'display_name' })
  const selectedAccent = useWatch({ control: form.control, name: 'theme_accent' })
  const mutation = useMutation({
    mutationFn: updateBusinessSettings,
    onSuccess: (settings) => {
      queryClient.setQueryData(businessSettingsQueryKey, settings)
      form.reset(formValues(settings))
      setSelectedLogo(undefined)
      setLogoPreviewUrl(undefined)
      setLogoError(undefined)
      setRemoveLogo(false)
      setFormMessage('Business settings saved.')
    },
    onError: (error) => {
      if (error instanceof ApiError) {
        let mapped = false
        for (const [field, messages] of Object.entries(error.fieldErrors)) {
          if (field === 'logo' && messages[0]) {
            setLogoError(messages[0])
            mapped = true
          } else if (settingsFields.has(field as keyof SettingsValues) && messages[0]) {
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

  useEffect(() => () => {
    if (logoPreviewUrl && typeof URL.revokeObjectURL === 'function') URL.revokeObjectURL(logoPreviewUrl)
  }, [logoPreviewUrl])

  if (settingsQuery.isPending) {
    return <div className="grid min-h-96 place-items-center text-muted"><p role="status">Loading business settings...</p></div>
  }

  if (settingsQuery.isError) {
    return (
      <div className="grid min-h-96 place-items-center">
        <div className="text-center">
          <p role="alert" className="text-danger">We could not load your business settings.</p>
          <Button variant="secondary" className="mt-4" onClick={() => { void settingsQuery.refetch() }}>Try again</Button>
        </div>
      </div>
    )
  }

  const settings = settingsQuery.data
  const businessName = businessNameValue || settings.display_name
  const visibleLogo = removeLogo ? null : logoPreviewUrl ?? settings.logo_url

  const chooseLogo = (file: File | undefined) => {
    setLogoError(undefined)
    if (!file) return
    if (!acceptedLogoTypes.includes(file.type)) {
      setLogoError('Choose a JPG, PNG, or WebP image.')
      return
    }
    if (file.size > maxLogoBytes) {
      setLogoError('Choose an image no larger than 2 MB.')
      return
    }

    const preview = typeof URL.createObjectURL === 'function' ? URL.createObjectURL(file) : undefined
    setSelectedLogo(file)
    setLogoPreviewUrl(preview)
    setRemoveLogo(false)
  }

  return (
    <section className="mx-auto w-full max-w-4xl">
      <p className="text-sm font-semibold text-primary">Settings</p>
      <h1 className="mt-2 text-3xl font-semibold">Business Settings</h1>
      <p className="mt-2 text-sm text-muted">Manage the business identity shown across your workspace and documents.</p>

      <form
        className="mt-8 space-y-10"
        onSubmit={form.handleSubmit((values) => {
          if (logoError) return
          setFormMessage(undefined)
          mutation.mutate({
            ...values,
            email: values.email || null,
            phone: values.phone || null,
            address: values.address || null,
            booking_prefix: values.booking_prefix.toUpperCase(),
            quotation_prefix: values.quotation_prefix.toUpperCase(),
            billing_prefix: values.billing_prefix.toUpperCase(),
            logo: selectedLogo,
            remove_logo: removeLogo,
          })
        })}
        noValidate
      >
        <fieldset className="border-t border-border pt-6">
          <legend className="pr-4 text-lg font-semibold">Branding</legend>
          <div className="mt-5 grid gap-7 lg:grid-cols-[minmax(0,1fr)_18rem]">
            <div className="space-y-6">
              <FormField label="Business Name" id="display-name" error={form.formState.errors.display_name?.message} {...form.register('display_name')} />

              <div>
                <p className="text-sm font-medium">Business Logo</p>
                <div className="mt-3 flex flex-wrap items-center gap-4">
                  {visibleLogo ? (
                    <img src={visibleLogo} alt={`${businessName} logo preview`} className="size-20 rounded-lg border border-border bg-surface object-contain p-1" />
                  ) : (
                    <div className="grid size-20 place-items-center rounded-lg bg-primary-soft text-lg font-bold text-primary" aria-label={`${businessInitials(businessName)} logo fallback`}>{businessInitials(businessName)}</div>
                  )}
                  <div>
                    <div className="flex flex-wrap gap-2">
                      <label htmlFor="business-logo" className="inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-lg border border-border px-4 py-2 text-sm font-semibold hover:bg-surface-subtle">
                        <ImageUp className="size-4" aria-hidden="true" /> {visibleLogo ? 'Change Logo' : 'Upload Logo'}
                      </label>
                      <input id="business-logo" type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" onChange={(event) => chooseLogo(event.target.files?.[0])} />
                      {visibleLogo ? <Button variant="secondary" onClick={() => { setSelectedLogo(undefined); setLogoPreviewUrl(undefined); setRemoveLogo(true); setLogoError(undefined) }}><Trash2 className="size-4" aria-hidden="true" /> Remove</Button> : null}
                    </div>
                    {selectedLogo ? <p className="mt-2 text-xs text-muted">{selectedLogo.name}</p> : null}
                    {logoError ? <p role="alert" className="mt-2 text-sm text-danger">{logoError}</p> : null}
                  </div>
                </div>
              </div>

              <div>
                <p id="theme-accent-label" className="text-sm font-medium">Theme Accent</p>
                <div role="radiogroup" aria-labelledby="theme-accent-label" className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                  {themeAccentOptions.map((accent) => (
                    <label key={accent.value} className={cn('flex min-h-11 cursor-pointer items-center gap-3 rounded-lg border px-3 py-2 text-sm font-medium transition', selectedAccent === accent.value ? 'border-primary bg-primary-soft text-primary ring-1 ring-primary' : 'border-border hover:bg-surface-subtle')}>
                      <input type="radio" value={accent.value} className="sr-only" {...form.register('theme_accent')} />
                      <span className="size-5 rounded-full border border-black/10" style={{ backgroundColor: `var(--accent-${accent.value})` }} aria-hidden="true" />
                      {accent.label}
                    </label>
                  ))}
                </div>
                {form.formState.errors.theme_accent?.message ? <p className="mt-2 text-sm text-danger">{form.formState.errors.theme_accent.message}</p> : null}
              </div>
            </div>

            <div className="self-start rounded-lg border border-border bg-surface p-5">
              <p className="text-xs font-semibold uppercase text-muted">Workspace preview</p>
              <div className="mt-4 flex items-center gap-3">
                {visibleLogo ? <img src={visibleLogo} alt="" className="size-10 rounded-lg border border-border object-contain" /> : <span className="grid size-10 place-items-center rounded-lg bg-primary-soft text-xs font-bold text-primary">{businessInitials(businessName)}</span>}
                <div className="min-w-0"><p className="truncate text-sm font-bold">{businessName}</p><p className="text-[10px] text-muted">Powered by TakdaOps</p></div>
              </div>
              <div className="mt-5 rounded-lg bg-primary-soft p-3 text-sm font-semibold text-primary">Selected workspace accent</div>
            </div>
          </div>
        </fieldset>

        <fieldset className="border-t border-border pt-6">
          <legend className="pr-4 text-lg font-semibold">Business Contact</legend>
          <div className="mt-5 grid gap-5 md:grid-cols-2">
            <FormField label="Business Email" id="business-email" type="email" error={form.formState.errors.email?.message} {...form.register('email')} />
            <FormField label="Business Phone" id="business-phone" error={form.formState.errors.phone?.message} {...form.register('phone')} />
            <div className="md:col-span-2"><TextAreaField label="Business Address" id="business-address" error={form.formState.errors.address?.message} {...form.register('address')} /></div>
          </div>
        </fieldset>

        <fieldset className="border-t border-border pt-6">
          <legend className="pr-4 text-lg font-semibold">Document Prefixes</legend>
          <div className="mt-5 grid gap-5 md:grid-cols-3">
            <FormField label="Booking Prefix" id="booking-prefix" maxLength={10} error={form.formState.errors.booking_prefix?.message} {...form.register('booking_prefix')} />
            <FormField label="Quotation Prefix" id="quotation-prefix" maxLength={10} error={form.formState.errors.quotation_prefix?.message} {...form.register('quotation_prefix')} />
            <FormField label="Billing Prefix" id="billing-prefix" maxLength={10} error={form.formState.errors.billing_prefix?.message} {...form.register('billing_prefix')} />
          </div>
        </fieldset>

        {formMessage ? <p role={mutation.isError ? 'alert' : 'status'} className={mutation.isError ? 'text-sm text-danger' : 'text-sm text-success'}>{formMessage}</p> : null}
        <Button type="submit" disabled={mutation.isPending}>
          <Save className="size-4" aria-hidden="true" />
          {mutation.isPending ? 'Saving...' : 'Save Changes'}
        </Button>
      </form>
    </section>
  )
}
