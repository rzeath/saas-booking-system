import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'

import { FormField, SelectField, TextAreaField } from '@/components/forms/form-field'
import {
  ApiError,
  createCustomer,
  type Customer,
  type SaveCustomerInput,
  updateCustomer,
} from '@/lib/api'

const customerSchema = z.object({
  name: z.string().trim().min(1, 'Customer name is required.').max(255),
  email: z.union([z.literal(''), z.string().trim().email('Enter a valid email address.').max(255)]),
  phone: z.string().trim().max(50, 'Use at most 50 characters.'),
  address: z.string().trim().max(2000, 'Use at most 2,000 characters.'),
  notes: z.string().trim().max(5000, 'Use at most 5,000 characters.'),
  status: z.enum(['active', 'inactive']),
})

type CustomerFormValues = z.infer<typeof customerSchema>

const customerFields = new Set<keyof CustomerFormValues>([
  'name',
  'email',
  'phone',
  'address',
  'notes',
  'status',
])

export function CustomerForm({
  customer,
  onSaved,
  onCancel,
  showStatus = true,
}: {
  customer: Customer | null
  onSaved: (customer: Customer) => void
  onCancel: () => void
  showStatus?: boolean
}) {
  const [formMessage, setFormMessage] = useState<string>()
  const form = useForm<CustomerFormValues>({
    resolver: zodResolver(customerSchema),
    defaultValues: {
      name: customer?.name ?? '',
      email: customer?.email ?? '',
      phone: customer?.phone ?? '',
      address: customer?.address ?? '',
      notes: customer?.notes ?? '',
      status: customer?.is_active === false ? 'inactive' : 'active',
    },
  })
  const mutation = useMutation({
    mutationFn: (values: CustomerFormValues) => {
      const input: SaveCustomerInput = {
        name: values.name,
        email: values.email || null,
        phone: values.phone || null,
        address: values.address || null,
        notes: values.notes || null,
        is_active: values.status === 'active',
      }

      return customer ? updateCustomer(customer.id, input) : createCustomer(input)
    },
    onSuccess: onSaved,
    onError: (error) => {
      if (error instanceof ApiError) {
        let mapped = false
        for (const [field, messages] of Object.entries(error.fieldErrors)) {
          const formField = field === 'is_active' ? 'status' : field
          if (customerFields.has(formField as keyof CustomerFormValues) && messages[0]) {
            form.setError(formField as keyof CustomerFormValues, { message: messages[0] })
            mapped = true
          }
        }
        if (mapped) return
      }

      setFormMessage(error instanceof Error ? error.message : 'Unable to save customer.')
    },
  })

  return (
    <form
      className="grid gap-5 md:grid-cols-2"
      noValidate
      onSubmit={form.handleSubmit((values) => {
        setFormMessage(undefined)
        mutation.mutate(values)
      })}
    >
      <div className="md:col-span-2"><FormField label="Name" id="customer-name" error={form.formState.errors.name?.message} {...form.register('name')} /></div>
      <FormField label="Email" id="customer-email" type="email" error={form.formState.errors.email?.message} {...form.register('email')} />
      <FormField label="Phone" id="customer-phone" error={form.formState.errors.phone?.message} {...form.register('phone')} />
      <div className="md:col-span-2"><TextAreaField label="Address" id="customer-address" error={form.formState.errors.address?.message} {...form.register('address')} /></div>
      <div className="md:col-span-2"><TextAreaField label="Notes" id="customer-notes" error={form.formState.errors.notes?.message} {...form.register('notes')} /></div>
      {showStatus ? (
        <SelectField label="Status" id="customer-form-status" error={form.formState.errors.status?.message} {...form.register('status')}>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </SelectField>
      ) : null}
      {formMessage ? <p role="alert" className="text-sm text-rose-300 md:col-span-2">{formMessage}</p> : null}
      <div className="flex justify-end gap-3 md:col-span-2">
        <button type="button" onClick={onCancel} className="rounded-lg border border-slate-700 px-4 py-2 text-sm hover:bg-slate-800">Cancel</button>
        <button type="submit" disabled={mutation.isPending} className="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-slate-950 hover:bg-cyan-400 disabled:opacity-60">
          {mutation.isPending ? 'Saving…' : customer ? 'Save changes' : 'Create customer'}
        </button>
      </div>
    </form>
  )
}
