import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { CreditCard } from 'lucide-react'
import { useForm, useWatch } from 'react-hook-form'
import { z } from 'zod'

import { FormField, SelectField, TextAreaField } from '@/components/forms/form-field'
import { Button } from '@/components/ui/button'
import { Modal } from '@/components/ui/modal'
import {
  ApiError,
  type PaymentMutationResult,
  type PaymentSummary,
  type Quotation,
  recordQuotationPayment,
} from '@/lib/api'
import {
  defaultManilaDateTime,
  isFutureManilaInput,
  manilaInputToApi,
  moneyToCents,
} from '@/lib/billing-format'
import { billingDetailQueryKey, billingListsQueryKey, billingPaymentListsQueryKey } from '@/lib/billings-query'
import { formatMoney } from '@/lib/booking-format'
import { bookingDetailQueryKey, bookingListsQueryKey } from '@/lib/bookings-query'
import { paymentListsQueryKey } from '@/lib/payments-query'
import { quotationDetailQueryKey, quotationListsQueryKey } from '@/lib/quotations-query'

const paymentSchema = z.object({
  amount: z.string().trim().refine((value) => {
    const cents = moneyToCents(value)
    return cents !== null && cents > 0n
  }, 'Enter an amount greater than zero with up to two decimal places.'),
  paid_at: z.string().min(1, 'Enter the payment date and time.').refine(
    (value) => !value || !isFutureManilaInput(value),
    'The payment date and time cannot be in the future in Asia/Manila.',
  ),
  payment_method: z.enum(['CASH', 'GCASH', 'BANK_TRANSFER', 'CHECK']),
  reference_number: z.string(),
  internal_note: z.string(),
}).superRefine((values, context) => {
  if (values.payment_method !== 'CASH' && values.reference_number.trim() === '') {
    context.addIssue({ code: 'custom', path: ['reference_number'], message: 'Enter a reference number for this payment method.' })
  }
})

type PaymentValues = z.infer<typeof paymentSchema>
const mappedFields = new Set<keyof PaymentValues>(['amount', 'paid_at', 'payment_method', 'reference_number', 'internal_note'])

export function RecordPaymentDialog({
  quotationId,
  quotationNumber,
  total,
  summary,
  onClose,
  onSuccess,
}: {
  quotationId: number
  quotationNumber: string
  total: string
  summary: PaymentSummary
  onClose: () => void
  onSuccess: (result: PaymentMutationResult) => void
}) {
  const queryClient = useQueryClient()
  const form = useForm<PaymentValues>({
    resolver: zodResolver(paymentSchema),
    defaultValues: {
      amount: '',
      paid_at: defaultManilaDateTime(),
      payment_method: 'CASH',
      reference_number: '',
      internal_note: '',
    },
  })
  const method = useWatch({ control: form.control, name: 'payment_method' })
  const enteredAmount = useWatch({ control: form.control, name: 'amount' })
  const mutation = useMutation({
    mutationFn: (values: PaymentValues) => recordQuotationPayment(quotationId, {
      amount: values.amount.trim(),
      paid_at: manilaInputToApi(values.paid_at),
      payment_method: values.payment_method,
      reference_number: values.reference_number.trim() || null,
      internal_note: values.internal_note.trim() || null,
    }),
    onSuccess: (result) => {
      queryClient.setQueryData<Quotation>(quotationDetailQueryKey(quotationId), (current) => current
        ? { ...current, billing: result.billing, booking: result.booking }
        : current)
      void queryClient.invalidateQueries({ queryKey: quotationDetailQueryKey(quotationId) })
      void queryClient.invalidateQueries({ queryKey: quotationListsQueryKey })
      void queryClient.invalidateQueries({ queryKey: bookingDetailQueryKey(result.booking.id) })
      void queryClient.invalidateQueries({ queryKey: bookingListsQueryKey })
      void queryClient.invalidateQueries({ queryKey: billingDetailQueryKey(result.billing.id) })
      void queryClient.invalidateQueries({ queryKey: billingPaymentListsQueryKey(result.billing.id) })
      void queryClient.invalidateQueries({ queryKey: billingListsQueryKey })
      void queryClient.invalidateQueries({ queryKey: paymentListsQueryKey })
      onSuccess(result)
    },
  })
  const apiErrors = mutation.error instanceof ApiError ? mutation.error.fieldErrors : {}
  const formMessage = mutation.error instanceof ApiError
    ? Object.entries(apiErrors).find(([field, messages]) => !mappedFields.has(field as keyof PaymentValues) && messages[0])?.[1][0]
      ?? (Object.keys(apiErrors).some((field) => mappedFields.has(field as keyof PaymentValues)) ? undefined : mutation.error.message)
    : mutation.error instanceof Error ? mutation.error.message : undefined
  const enteredCents = moneyToCents(enteredAmount)
  const formattedEntered = enteredCents !== null ? formatMoney(enteredAmount) : 'Not entered'

  const submit = form.handleSubmit((values) => {
    const amountCents = moneyToCents(values.amount)
    const remainingCents = moneyToCents(summary.remaining_balance)

    if (amountCents === null || remainingCents === null || amountCents > remainingCents) {
      form.setError('amount', { message: `Amount cannot exceed the remaining balance of ${formatMoney(summary.remaining_balance)}.` })
      return
    }

    mutation.mutate(values)
  })

  return (
    <Modal
      title="Record Payment"
      description={`Record a payment against ${quotationNumber}. Billing is created automatically on the first payment.`}
      onClose={() => { if (!mutation.isPending) onClose() }}
    >
      <div className="grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-border bg-border sm:grid-cols-4">
        {[
          ['Total', formatMoney(total)],
          ['Paid', formatMoney(summary.amount_paid)],
          ['Remaining', formatMoney(summary.remaining_balance)],
          ['Entering', formattedEntered],
        ].map(([label, value]) => <div key={label} className="bg-surface-subtle p-3"><p className="text-xs font-medium text-muted">{label}</p><p className="mt-1 text-sm font-semibold tabular-nums">{value}</p></div>)}
      </div>

      <form className="mt-5 grid gap-5 sm:grid-cols-2" noValidate onSubmit={submit}>
        <FormField label="Amount" id="payment-amount" inputMode="decimal" placeholder="0.00" error={form.formState.errors.amount?.message ?? apiErrors.amount?.[0]} {...form.register('amount')} />
        <FormField label="Paid at" id="payment-paid-at" type="datetime-local" step="60" error={form.formState.errors.paid_at?.message ?? apiErrors.paid_at?.[0]} {...form.register('paid_at')} />
        <SelectField label="Payment method" id="payment-method" error={form.formState.errors.payment_method?.message ?? apiErrors.payment_method?.[0]} {...form.register('payment_method')}>
          <option value="CASH">Cash</option>
          <option value="GCASH">GCash</option>
          <option value="BANK_TRANSFER">Bank Transfer</option>
          <option value="CHECK">Check</option>
        </SelectField>
        <FormField label={method === 'CASH' ? 'Reference number (optional)' : 'Reference number'} id="payment-reference" error={form.formState.errors.reference_number?.message ?? apiErrors.reference_number?.[0]} {...form.register('reference_number')} />
        <div className="sm:col-span-2"><TextAreaField label="Internal note (optional)" id="payment-note" error={form.formState.errors.internal_note?.message ?? apiErrors.internal_note?.[0]} {...form.register('internal_note')} /></div>
        {formMessage ? <p role="alert" className="text-sm text-danger sm:col-span-2">{formMessage}</p> : null}
        <div className="flex flex-wrap justify-end gap-3 sm:col-span-2">
          <Button variant="secondary" disabled={mutation.isPending} onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={mutation.isPending || summary.payment_status === 'PAID'}>
            <CreditCard className="size-4" aria-hidden="true" />
            {mutation.isPending ? 'Recording...' : 'Record Payment'}
          </Button>
        </div>
      </form>
    </Modal>
  )
}
