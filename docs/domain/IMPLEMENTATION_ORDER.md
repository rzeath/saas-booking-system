# TakdaOps V1 Implementation Order

Phase 2A defines architecture only. The following phases should each start with reviewed migrations, preserve the existing auth foundation, use `TenantContext`, and finish with tenant-isolation and domain-behavior tests before moving forward.

## Cross-phase implementation rules

- Add database structures in dependency order and use composite foreign keys where [DOMAIN_MODEL.md](./DOMAIN_MODEL.md) specifies tenant consistency.
- Keep controllers thin; put transactional behavior in named actions/services.
- Scope route binding and queries by authenticated Organization.
- Use backend enums/validation plus database checks for statuses and structural invariants.
- Use API Resources and typed frontend API contracts; never make frontend validation authoritative.
- Use deterministic Asia/Manila dates and times in tests and exact decimal strings for money.
- Do not add Calendar/Dashboard persistence merely for presentation.

## Phase 2B — Business Settings and Document Sequences

Tables:

- `business_settings`
- `document_sequences`
- supporting `(organization_id, id)` index on existing `users` if required by composite audit foreign keys

Backend scope:

- Provision one Business Settings row with an Organization/Admin registration without changing one-admin semantics.
- Tenant-scoped settings read/update action with branding and prefix validation.
- Transaction-safe, year-aware number allocator for Booking/Quotation/Billing types.

Tests:

- Registration atomically creates one settings row with defaults.
- Tenant isolation and ignored caller tenant IDs.
- Branding/prefix validation.
- Concurrent allocation uniqueness, year/type/tenant separation, formatting, rollback behavior, and acceptable gaps.

Frontend scope:

- Protected Business Settings form with clear validation/loading/error states.
- No domain-document screens yet.

Dependencies: existing Organization/User/TenantContext foundation only.

## Phase 3 — Customers and Event Types

Tables:

- `customers`
- `event_types`

Backend scope:

- Tenant-scoped CRUD with deactivate/reactivate behavior.
- Case-insensitive per-tenant Event Type uniqueness.
- Prevent cross-tenant lookup/update/delete and avoid customer-code generation.

Tests:

- Tenant isolation for every endpoint and route ID.
- Customer optional fields, duplicate contact values, activity filtering.
- Event Type name uniqueness within, but not across, tenants.
- Referenced-record deletion policy once Booking exists (completed after Phase 6).

Frontend scope:

- Reusable list/form/active-state UI for Customers and Event Types.
- Accessible empty, loading, validation, and error states.

Dependencies: Phase 2B tenant settings patterns.

## Phase 4 — Services, Packages, and Rates

Tables:

- `services`
- `packages`
- `service_rates`

Backend scope:

- Tenant-scoped master-data actions and deactivation.
- Enforce Package -> one Service and tenant-safe composite relationships.
- Resolve backend-authoritative Rate by Event Type + Package + duration.
- Reject missing/inactive combinations; never default to zero.

Tests:

- Cross-tenant relationship rejection at request and database layers.
- Service `total_units > 0`.
- Same Package name under different Services, but no duplicate within one Service.
- Unique Rate combination, duration/money validation, active/inactive lookup.
- Confirm `service_id` is correctly derived through Package.

Frontend scope:

- Service/Package management and Rate matrix/list editing.
- Only expose active bookable combinations to later Booking forms.

Dependencies: Event Types from Phase 3; the Philippine Peso money invariant.

## Phase 5 — Staff

Tables:

- `staff`

Backend scope:

- Tenant-scoped Staff CRUD and deactivation.
- Keep Staff separate from authentication; no assignment/shift engine yet.

Tests:

- Tenant isolation, required name/phone, optional email/notes, activity behavior.
- Explicit proof that Staff has no login/User coupling.

Frontend scope:

- Staff list/form/active-state UI.

Dependencies: tenant CRUD conventions; no Booking dependency.

## Phase 6 — Bookings, Booking Services, and Availability

Tables:

- `bookings`
- `booking_services`
- `booking_service_staff_assignments`

Backend scope:

- Create/update Booking aggregate with customer/event/service/package snapshots.
- Combine the Philippine event date and per-line start time into explicit Asia/Manila `start_at`/`end_at` values.
- Resolve prices server-side and calculate exact line totals.
- Allocate Booking numbers transactionally.
- Implement pooled Service capacity with ordered Service-row locks and in-transaction re-checks.
- Manual Staff assignment with ordered Staff locks and overlap checks.
- Explicit cancel/complete actions and pre-acceptance commercial editing hooks.

Tests:

- Tenant isolation and cross-tenant foreign-key attempts.
- Price/snapshot correctness and ignored submitted prices/tenant IDs.
- Same-day, cross-midnight, half-open interval, and date-only handling.
- Half-open back-to-back intervals, partial/full overlap, quantity summation, self-exclusion, multiple proposed lines, and status-based reservation.
- Concurrent capacity attempts and deterministic lock ordering.
- Optional Staff, duplicate assignment prevention, conflicts, and inactive Staff behavior.
- Booking status transition and cancellation-preservation behavior available at this phase.

Frontend scope:

- Booking list/detail/create/edit forms with multiple independently scheduled lines.
- Typed availability feedback and optional manual Staff assignment.
- Explicit state actions with confirmation; no public booking flow.

Dependencies: Phases 2B–5.

## Phase 7 — Quotation Lifecycle

Tables:

- `quotations`
- `quotation_items`

Backend scope:

- Allocate Quotation numbers and generate item/header snapshots from Booking.
- Draft rebuild, send, accept, reject, cancel, expire, and outdate actions.
- Enforce generated active/accepted slots plus Booking-row locking.
- Apply fixed transportation fee, crew meal fee, and discount amount with exact totals.
- On commercial Booking edits, outdate SENT Quotation and return Booking to PENDING.
- Block normal commercial edits after acceptance.

Tests:

- Complete transition matrix and invalid transition rejection.
- At-most-one Draft/Sent under concurrency and at-most-one Accepted.
- Snapshot immutability and no independent item editing.
- PENDING/QUOTED synchronization for terminal sent states.
- Exact fee/discount math and positive send/accept total.
- Accepted commercial lock and tenant isolation.

Frontend scope:

- Quotation revision list/detail, draft fee/discount form, send/accept/reject/cancel actions.
- Clear OUTDATED and accepted-lock states.

Dependencies: Bookings and snapshots from Phase 6; settings/sequences from Phase 2B.

## Phase 8 — Payments and Billing

Tables:

- `payments`
- `billings`
- `billing_items`

Backend scope:

- Record Payment action that serializes on Accepted Quotation, re-checks exact remaining balance, and prevents overpayment.
- Atomically create the one Billing and item/header snapshots on first Payment and confirm Booking.
- Void Payment action with immutable audit metadata.
- Derive paid amount, balance, and UNPAID/PARTIALLY_PAID/PAID state at read time.

Tests:

- Accepted-Quotation-only Payments, positive exact amounts, and tenant isolation.
- First Payment -> Billing -> CONFIRMED atomicity and rollback on any failure.
- Unique Billing under concurrent first payments.
- Partial/full payment, concurrent overpayment prevention, and no negative balance.
- Voiding effects on derived totals without Billing deletion or Booking demotion.
- Cancellation preserves all financial data and snapshots.

Frontend scope:

- Billing detail, payment history, record-payment form, derived totals/state, and guarded void dialog.
- No refund/credit/overpayment UI.

Dependencies: Accepted Quotation from Phase 7; Billing numbering from Phase 2B.

## Phase 9 — Confirmed Rescheduling

Tables:

- `booking_reschedules`
- `booking_reschedule_items`

Backend scope:

- Dedicated confirmed-only Reschedule action.
- Lock Services and assigned Staff deterministically; exclude the Booking's current reservations and re-check proposed schedule.
- Atomically append immutable old/new history and update only event date and line endpoints.
- Preserve accepted Quotation, Billing, Payments, commercial fields, and CONFIRMED status.

Tests:

- Allowed/forbidden status and field changes.
- Capacity and Staff conflict behavior including self-exclusion and cross-midnight intervals.
- Full rollback when any proposed line conflicts.
- Exact history, actor/time audit, financial preservation, and tenant isolation.

Frontend scope:

- Dedicated reschedule workflow with old/new comparison, conflict errors, reason, and history display.

Dependencies: Phases 6–8 so operational and financial preservation can be verified end-to-end.

## Phase 10 — Calendar and Dashboard

Tables: none by default.

Backend scope:

- Tenant-scoped read endpoints/query services over existing source-of-truth tables.
- Calendar uses current Booking Service schedules and relevant statuses.
- Dashboard derives operational and financial summaries without mutating history.

Tests:

- Tenant isolation, Philippine date-range boundaries, status filters, exact totals, and query performance.
- Verify no stale denormalized source of truth is introduced.

Frontend scope:

- Calendar and dashboard read experiences with loading/empty/error states and links to domain records.

Dependencies: all prior phases.

## Review gates

Before each phase begins:

1. Review that phase's migrations against the final ERD and constraint list.
2. Resolve any open product decision before persisting it; do not silently broaden scope.
3. Verify existing authentication and tenant tests remain green.
4. Add concurrency tests wherever the phase introduces locks, sequences, capacity, quotation slots, or money.
5. Update these architecture documents if an explicitly approved requirement changes.
