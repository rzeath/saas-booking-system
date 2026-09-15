import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'

import { PaginationControls } from '@/components/data/pagination-controls'
import { FormField, SelectField } from '@/components/forms/form-field'
import { Modal } from '@/components/ui/modal'
import {
  ApiError,
  createEventType,
  type EventType,
  getEventTypes,
  type MasterDataQuery,
  type MasterDataStatus,
  updateEventType,
} from '@/lib/api'
import { eventTypeListQueryKey, eventTypesQueryKey } from '@/lib/event-types-query'

const eventTypeSchema = z.object({
  name: z.string().trim().min(1, 'Event type name is required.').max(255),
  status: z.enum(['active', 'inactive']),
})

type EventTypeFormValues = z.infer<typeof eventTypeSchema>

function EventTypeForm({
  eventType,
  onSaved,
  onCancel,
}: {
  eventType: EventType | null
  onSaved: (eventType: EventType) => void
  onCancel: () => void
}) {
  const [formMessage, setFormMessage] = useState<string>()
  const form = useForm<EventTypeFormValues>({
    resolver: zodResolver(eventTypeSchema),
    defaultValues: {
      name: eventType?.name ?? '',
      status: eventType?.is_active === false ? 'inactive' : 'active',
    },
  })
  const mutation = useMutation({
    mutationFn: (values: EventTypeFormValues) => {
      const input = { name: values.name, is_active: values.status === 'active' }
      return eventType ? updateEventType(eventType.id, input) : createEventType(input)
    },
    onSuccess: onSaved,
    onError: (error) => {
      if (error instanceof ApiError) {
        const nameError = error.fieldErrors.name?.[0]
        const statusError = error.fieldErrors.is_active?.[0]
        if (nameError) form.setError('name', { message: nameError })
        if (statusError) form.setError('status', { message: statusError })
        if (nameError || statusError) return
      }

      setFormMessage(error instanceof Error ? error.message : 'Unable to save event type.')
    },
  })

  return (
    <form
      className="grid gap-5"
      noValidate
      onSubmit={form.handleSubmit((values) => {
        setFormMessage(undefined)
        mutation.mutate(values)
      })}
    >
      <FormField label="Name" id="event-type-name" error={form.formState.errors.name?.message} {...form.register('name')} />
      <SelectField label="Status" id="event-type-form-status" error={form.formState.errors.status?.message} {...form.register('status')}>
        <option value="active">Active</option>
        <option value="inactive">Inactive</option>
      </SelectField>
      {formMessage ? <p role="alert" className="text-sm text-rose-300">{formMessage}</p> : null}
      <div className="flex justify-end gap-3">
        <button type="button" onClick={onCancel} className="rounded-lg border border-slate-700 px-4 py-2 text-sm hover:bg-slate-800">Cancel</button>
        <button type="submit" disabled={mutation.isPending} className="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-slate-950 hover:bg-cyan-400 disabled:opacity-60">
          {mutation.isPending ? 'Saving…' : eventType ? 'Save changes' : 'Create event type'}
        </button>
      </div>
    </form>
  )
}

const initialQuery: MasterDataQuery = { page: 1, search: '', status: 'all' }

export function EventTypesRoute({ embedded = false }: { embedded?: boolean } = {}) {
  const queryClient = useQueryClient()
  const [query, setQuery] = useState<MasterDataQuery>(initialQuery)
  const [search, setSearch] = useState('')
  const [editingEventType, setEditingEventType] = useState<EventType | null | undefined>()
  const [message, setMessage] = useState<string>()
  const [errorMessage, setErrorMessage] = useState<string>()
  const eventTypesQuery = useQuery({
    queryKey: eventTypeListQueryKey(query),
    queryFn: () => getEventTypes(query),
  })
  const statusMutation = useMutation({
    mutationFn: ({ eventType, isActive }: { eventType: EventType; isActive: boolean }) =>
      updateEventType(eventType.id, { name: eventType.name, is_active: isActive }),
    onSuccess: (eventType) => {
      setErrorMessage(undefined)
      setMessage(`${eventType.name} is now ${eventType.is_active ? 'active' : 'inactive'}.`)
      void queryClient.invalidateQueries({ queryKey: eventTypesQueryKey })
    },
    onError: (error) => {
      setMessage(undefined)
      setErrorMessage(error instanceof Error ? error.message : 'Unable to update event type status.')
    },
  })

  return (
    <section>
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          {!embedded ? <p className="text-sm font-semibold tracking-[0.08em] text-cyan-400">Master data</p> : null}
          {embedded ? <h2 className="text-lg font-semibold">Event Types</h2> : <h1 className="mt-2 text-3xl font-semibold tracking-tight">Event Types</h1>}
          <p className="mt-2 text-sm text-slate-400">Manage the event classifications used by future bookings and rates.</p>
        </div>
        <button type="button" onClick={() => setEditingEventType(null)} className="inline-flex items-center gap-2 rounded-lg bg-cyan-500 px-4 py-2.5 text-sm font-semibold text-slate-950 hover:bg-cyan-400">
          <Plus className="size-4" aria-hidden="true" /> New event type
        </button>
      </div>

      <div className="mt-6 rounded-2xl border border-slate-800 bg-slate-900 shadow-xl shadow-black/10">
        <form
          role="search"
          className="grid gap-4 border-b border-slate-800 p-5 md:grid-cols-[1fr_12rem_auto] md:items-end"
          onSubmit={(event) => {
            event.preventDefault()
            setQuery((current) => ({ ...current, page: 1, search: search.trim() }))
          }}
        >
          <FormField label="Search event types" id="event-type-search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Event type name" />
          <SelectField
            label="Status filter"
            id="event-type-status-filter"
            value={query.status}
            onChange={(event) => setQuery((current) => ({ ...current, page: 1, status: event.target.value as MasterDataStatus }))}
          >
            <option value="all">All</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </SelectField>
          <button type="submit" className="rounded-lg border border-slate-700 px-4 py-2.5 text-sm font-medium hover:bg-slate-800">Search</button>
        </form>

        {message ? <p role="status" className="px-5 pt-4 text-sm text-emerald-300">{message}</p> : null}
        {errorMessage ? <p role="alert" className="px-5 pt-4 text-sm text-rose-300">{errorMessage}</p> : null}
        {eventTypesQuery.isPending ? <p role="status" className="p-8 text-center text-slate-400">Loading event types…</p> : null}
        {eventTypesQuery.isError ? (
          <div className="p-8 text-center">
            <p role="alert" className="text-rose-300">We could not load event types.</p>
            <button type="button" onClick={() => { void eventTypesQuery.refetch() }} className="mt-3 rounded-lg border border-slate-700 px-3 py-2 text-sm hover:bg-slate-800">Try again</button>
          </div>
        ) : null}
        {eventTypesQuery.data && eventTypesQuery.data.data.length === 0 ? <p className="p-8 text-center text-slate-400">No event types match these filters.</p> : null}
        {eventTypesQuery.data && eventTypesQuery.data.data.length > 0 ? (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-slate-800 text-xs uppercase tracking-wide text-slate-500">
                <tr><th className="px-5 py-3">Name</th><th className="px-5 py-3">Status</th><th className="px-5 py-3 text-right">Actions</th></tr>
              </thead>
              <tbody className="divide-y divide-slate-800">
                {eventTypesQuery.data.data.map((eventType) => (
                  <tr key={eventType.id}>
                    <td className="px-5 py-4 font-medium text-slate-100">{eventType.name}</td>
                    <td className="px-5 py-4"><span className={eventType.is_active ? 'text-emerald-300' : 'text-slate-500'}>{eventType.is_active ? 'Active' : 'Inactive'}</span></td>
                    <td className="px-5 py-4 text-right">
                      <div className="flex justify-end gap-2">
                        <button type="button" onClick={() => setEditingEventType(eventType)} className="rounded-lg border border-slate-700 px-3 py-1.5 hover:bg-slate-800">Edit</button>
                        <button
                          type="button"
                          disabled={statusMutation.isPending && statusMutation.variables?.eventType.id === eventType.id}
                          onClick={() => statusMutation.mutate({ eventType, isActive: !eventType.is_active })}
                          className="rounded-lg border border-slate-700 px-3 py-1.5 hover:bg-slate-800 disabled:opacity-50"
                        >
                          {eventType.is_active ? 'Deactivate' : 'Activate'}
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : null}
        {eventTypesQuery.data ? (
          <PaginationControls
            page={eventTypesQuery.data.meta.current_page}
            lastPage={eventTypesQuery.data.meta.last_page}
            total={eventTypesQuery.data.meta.total}
            onPageChange={(page) => setQuery((current) => ({ ...current, page }))}
          />
        ) : null}
      </div>

      {editingEventType !== undefined ? (
        <Modal title={editingEventType ? 'Edit event type' : 'New event type'} onClose={() => setEditingEventType(undefined)}>
          <EventTypeForm
            eventType={editingEventType}
            onCancel={() => setEditingEventType(undefined)}
            onSaved={(eventType) => {
              setEditingEventType(undefined)
              setMessage(`${eventType.name} saved.`)
              setErrorMessage(undefined)
              void queryClient.invalidateQueries({ queryKey: eventTypesQueryKey })
            }}
          />
        </Modal>
      ) : null}
    </section>
  )
}
