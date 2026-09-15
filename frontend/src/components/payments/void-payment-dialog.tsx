import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Ban } from 'lucide-react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'

import { TextAreaField } from '@/components/forms/form-field'
import { Button } from '@/components/ui/button'
import { Modal } from '@/components/ui/modal'
import { ApiError, type Payment, type PaymentMutationResult, voidPayment } from '@/lib/api'
import { paymentMethodLabel } from '@/lib/billing-format'
import { billingDetailQueryKey, billingListsQueryKey, billingPaymentListsQueryKey } from '@/lib/billings-query'
import { formatMoney } from '@/lib/booking-format'
import { bookingDetailQueryKey, bookingListsQueryKey } from '@/lib/bookings-query'
import { paymentListsQueryKey } from '@/lib/payments-query'
import { quotationDetailQueryKey } from '@/lib/quotations-query'

const voidSchema = z.object({
  void_reason: z.string().trim().min(1, 'Enter a reason for voiding this payment.').max(5000),
})

type VoidValues = z.infer<typeof voidSchema>

export function VoidPaymentDialog({ payment, onClose, onSuccess }: {
  payment: Payment
  onClose: () => void
  onSuccess: (result: PaymentMutationResult) => void
}) {
  const queryClient = useQueryClient()
  const form = useForm<VoidValues>({ resolver: zodResolver(voidSchema), defaultValues: { void_reason: '' } })
  const mutation = useMutation({
    mutationFn: (values: VoidValues) => voidPayment(payment.id, values.void_reason.trim()),
    onSuccess: (result) => {
      void queryClient.invalidateQueries({ queryKey: billingDetailQueryKey(result.billing.id) })
      void queryClient.invalidateQueries({ queryKey: billingPaymentListsQueryKey(result.billing.id) })
      void queryClient.invalidateQueries({ queryKey: billingListsQueryKey })
      void queryClient.invalidateQueries({ queryKey: paymentListsQueryKey })
      void queryClient.invalidateQueries({ queryKey: quotationDetailQueryKey(payment.quotation.id) })
      void queryClient.invalidateQueries({ queryKey: bookingDetailQueryKey(payment.booking.id) })
      void queryClient.invalidateQueries({ queryKey: bookingListsQueryKey })
      onSuccess(result)
    },
  })
  const apiError = mutation.error instanceof ApiError ? mutation.error : undefined
  const formMessage = apiError?.fieldErrors.payment?.[0]
    ?? (apiError && !apiError.fieldErrors.void_reason ? apiError.message : undefined)

  return (
    <Modal title="Void Payment" description="The payment remains in history and the Billing balance will be recalculated." onClose={() => { if (!mutation.isPending) onClose() }}>
      <div className="rounded-lg border border-warning/30 bg-warning-soft p-4 text-sm text-warning">
        <p className="font-semibold">{formatMoney(payment.amount)} via {paymentMethodLabel(payment.payment_method)}</p>
        <p className="mt-1">Reference: {payment.reference_number ?? 'No reference'}</p>
      </div>
      <form className="mt-5" noValidate onSubmit={form.handleSubmit((values) => mutation.mutate(values))}>
        <TextAreaField label="Void reason" id="void-reason" error={form.formState.errors.void_reason?.message ?? apiError?.fieldErrors.void_reason?.[0]} {...form.register('void_reason')} />
        {formMessage ? <p role="alert" className="mt-3 text-sm text-danger">{formMessage}</p> : null}
        <div className="mt-5 flex justify-end gap-3">
          <Button variant="secondary" disabled={mutation.isPending} onClick={onClose}>Keep Payment</Button>
          <Button type="submit" variant="destructive" disabled={mutation.isPending}>
            <Ban className="size-4" aria-hidden="true" />
            {mutation.isPending ? 'Voiding...' : 'Void Payment'}
          </Button>
        </div>
      </form>
    </Modal>
  )
}
