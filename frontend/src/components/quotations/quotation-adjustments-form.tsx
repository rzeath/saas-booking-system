import { zodResolver } from '@hookform/resolvers/zod'
import { Save } from 'lucide-react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'

import { FormField } from '@/components/forms/form-field'
import { Button } from '@/components/ui/button'
import { ApiError, type SaveDraftQuotationInput } from '@/lib/api'

const moneySchema = z.string()
  .trim()
  .regex(/^\d{1,11}(?:\.\d{1,2})?$/, 'Enter a non-negative amount with up to two decimal places.')

const adjustmentsSchema = z.object({
  transportation_fee: moneySchema,
  crew_meal_fee: moneySchema,
  discount_amount: moneySchema,
  valid_until: z.union([
    z.literal(''),
    z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Enter a valid date.'),
  ]),
})

type AdjustmentValues = z.infer<typeof adjustmentsSchema>
const fields = new Set<keyof AdjustmentValues>([
  'transportation_fee',
  'crew_meal_fee',
  'discount_amount',
  'valid_until',
])

export function QuotationAdjustmentsForm({
  initialValues,
  submitLabel,
  isPending,
  error,
  onSubmit,
  onCancel,
}: {
  initialValues?: Partial<AdjustmentValues>
  submitLabel: string
  isPending: boolean
  error: unknown
  onSubmit: (input: SaveDraftQuotationInput) => void
  onCancel?: () => void
}) {
  const form = useForm<AdjustmentValues>({
    resolver: zodResolver(adjustmentsSchema),
    defaultValues: {
      transportation_fee: initialValues?.transportation_fee ?? '0.00',
      crew_meal_fee: initialValues?.crew_meal_fee ?? '0.00',
      discount_amount: initialValues?.discount_amount ?? '0.00',
      valid_until: initialValues?.valid_until ?? '',
    },
  })
  const apiErrors = error instanceof ApiError ? error.fieldErrors : {}
  const hasMappedApiError = Object.entries(apiErrors)
    .some(([field, messages]) => fields.has(field as keyof AdjustmentValues) && messages[0])
  const formMessage = error instanceof ApiError
    ? Object.entries(apiErrors).find(([field, messages]) => !fields.has(field as keyof AdjustmentValues) && messages[0])?.[1][0]
      ?? (hasMappedApiError ? undefined : error.message)
    : error instanceof Error ? error.message : error ? 'Unable to save the quotation.' : undefined

  return (
    <form
      className="grid gap-5 sm:grid-cols-2"
      noValidate
      onSubmit={form.handleSubmit((values) => {
        form.clearErrors()
        onSubmit({
          transportation_fee: values.transportation_fee,
          crew_meal_fee: values.crew_meal_fee,
          discount_amount: values.discount_amount,
          valid_until: values.valid_until || null,
        })
      })}
    >
      <FormField label="Transportation fee" id="quotation-transportation-fee" inputMode="decimal" error={form.formState.errors.transportation_fee?.message ?? apiErrors.transportation_fee?.[0]} {...form.register('transportation_fee')} />
      <FormField label="Crew meal fee" id="quotation-crew-meal-fee" inputMode="decimal" error={form.formState.errors.crew_meal_fee?.message ?? apiErrors.crew_meal_fee?.[0]} {...form.register('crew_meal_fee')} />
      <FormField label="Discount" id="quotation-discount" inputMode="decimal" error={form.formState.errors.discount_amount?.message ?? apiErrors.discount_amount?.[0]} {...form.register('discount_amount')} />
      <FormField label="Valid until" id="quotation-valid-until" type="date" error={form.formState.errors.valid_until?.message ?? apiErrors.valid_until?.[0]} {...form.register('valid_until')} />
      {formMessage ? <p role="alert" className="text-sm text-danger sm:col-span-2">{formMessage}</p> : null}
      <div className="flex flex-wrap justify-end gap-3 sm:col-span-2">
        {onCancel ? <Button variant="secondary" disabled={isPending} onClick={onCancel}>Cancel</Button> : null}
        <Button type="submit" disabled={isPending}>
          <Save className="size-4" aria-hidden="true" />
          {isPending ? 'Saving...' : submitLabel}
        </Button>
      </div>
    </form>
  )
}
