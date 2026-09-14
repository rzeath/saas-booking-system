# TakdaOps V1 Domain Rules

This is the concise implementation checklist for the approved V1 invariants. Detailed fields and constraints are in [DOMAIN_MODEL.md](./DOMAIN_MODEL.md).

## Tenancy and identity

1. One Organization has exactly one Admin User in normal V1 operation. The existing unique `users.organization_id` prevents a second admin; registration creates Organization and User atomically.
2. The authenticated User determines `organization_id` through `TenantContext`. Client-supplied tenant IDs are ignored.
3. Every tenant-owned query and route binding is tenant-scoped. Derived child records are loaded through their scoped aggregate.
4. Operational Staff are not Users, cannot log in, and are never created automatically from Users.
5. `organizations.name` is canonical tenant identity. `business_settings.display_name` owns editable display/document identity.

## Master data and pricing

1. Customer, Event Type, Service, Package, Rate, and Staff records are tenant-owned and deactivated rather than deleted after use.
2. Customers have no generated customer code. Duplicate names/contact values are allowed.
3. A Booking has exactly one Event Type; Booking Services do not select another.
4. Service capacity is `total_units` pooled across interchangeable units. There are no equipment/unit records or exact unit assignments.
5. Packages are independent tenant-owned records. Services and Packages are many-to-many through tenant-safe mappings; an unmapped combination is invalid for rates and bookings.
6. A Rate is identified by Organization + Event Type + Service + Package + duration minutes. The Service/Package pair must be mapped.
7. One configuration row exists per Rate combination. Missing or inactive means unavailable, never zero.
8. The backend resolves Rate and computes `line_total = unit_rate * quantity`. The frontend cannot submit authoritative prices or direct Booking Service price overrides.
9. Booking Service quantity is a positive integer. V1 has no quantity discount/bulk-pricing engine.
10. Commercial discounts are fixed monetary amounts on Quotation, not percentage rules.

## Scheduling and availability

1. Booking stores the Philippine `event_date`. Each Booking Service stores Asia/Manila `start_at`/`end_at` wall-clock date-times plus positive `duration_minutes`.
2. Backend schedule construction combines the explicit event date and start time directly in the globally configured `Asia/Manila` application timezone. There is no tenant timezone or UTC conversion for business schedules.
3. Full endpoints support cross-midnight services. The backend enforces `end = start + duration`.
4. Intervals are half-open: `[start, end)`. They overlap only when `existing.start < requested.end AND existing.end > requested.start`; back-to-back intervals do not overlap.
5. `PENDING`, `QUOTED`, and `CONFIRMED` Bookings reserve capacity. `COMPLETED` and `CANCELLED` do not.
6. Availability requires `sum(overlapping quantity) + requested quantity <= Service.total_units` for each Service.
7. Capacity-affecting writes start a transaction, lock all affected Service rows in ascending ID order, re-query overlap totals, validate, then write. Service rows are the stable mutex; correctness does not depend on fragile gap locking.
8. Booking updates exclude the Booking's own current lines from overlap totals, then validate the complete replacement schedule as one set, including overlaps among its proposed lines.
9. Reducing `total_units` uses the same Service lock and cannot fall below already reserved concurrent quantity.
10. Staff are optional and manually assigned. Booking creation never requires Staff.
11. Assignment/reschedule locks affected Staff rows in ascending ID order and rejects overlaps with other non-cancelled/non-completed assigned Booking Services. A line excludes its own assignment during reschedule.

## Booking lifecycle

1. Statuses are `PENDING`, `QUOTED`, `CONFIRMED`, `COMPLETED`, `CANCELLED`.
2. Normal flow is `PENDING -> QUOTED -> CONFIRMED -> COMPLETED`.
3. Cancellation is allowed from PENDING, QUOTED, or CONFIRMED. Completion is an explicit action, never time-driven.
4. Booking holds customer/event snapshots; Booking Service holds Service/Package/price/quantity/schedule snapshots.
5. Before Quotation acceptance, commercial edits are explicit aggregate operations that re-resolve pricing and update affected Booking snapshots.
6. If commercial details change while a SENT Quotation exists, that Quotation becomes `OUTDATED` and Booking becomes `PENDING` atomically.
7. Once a Quotation is ACCEPTED, normal edits to customer, Event Type, Service, Package, duration, quantity, rate, and commercial fees are blocked.
8. Cancelling or completing a Booking preserves Booking Services, Quotations, Billing, Payments, assignments, and snapshots.

## Quotation lifecycle

1. Statuses are `DRAFT`, `SENT`, `ACCEPTED`, `REJECTED`, `CANCELLED`, `EXPIRED`, `OUTDATED`.
2. A Booking has many retained revisions, but at most one combined DRAFT/SENT and at most one ACCEPTED Quotation. Generated-slot unique indexes enforce both ceilings; actions also lock the Booking.
3. Draft items are generated from current Booking Services. They cannot independently alter Service, Package, schedule, duration, quantity, or base unit rate.
4. Sending is `DRAFT -> SENT` and `Booking PENDING -> QUOTED` in one transaction. A sent Quotation has immutable items and commercial snapshots.
5. Acceptance is only `SENT -> ACCEPTED`. Acceptance does not confirm the Booking; the Booking remains `QUOTED` until the first valid Payment transaction.
6. `SENT -> REJECTED|CANCELLED|EXPIRED|OUTDATED` returns Booking to `PENDING` when no SENT Quotation remains. The database active-slot rule means V1 cannot have another SENT quotation concurrently.
7. A DRAFT may become CANCELLED or be explicitly rebuilt from current Booking data. Terminal Quotations are not reopened or overwritten.
8. Quotation total is exact: `subtotal + transportation_fee + crew_meal_fee - discount_amount`. Components are non-negative and discount cannot exceed gross.
9. A Quotation must total more than zero before send/accept because V1 has no zero-payment confirmation path.

## Payments and billing

1. A positive Payment belongs to one specific ACCEPTED Quotation and has status POSTED or VOIDED.
2. The first valid Payment transaction locks the Accepted Quotation (and related Booking), re-checks status and remaining balance, records the Payment, creates the one Billing if absent, snapshots accepted items, and changes Booking to CONFIRMED atomically.
3. There is no minimum or percentage downpayment rule. Any positive, non-overpaying first Payment accepted by the admin confirms the Booking.
4. Unique `billings.quotation_id` prevents duplicate Billing. Payment writers serialize on the guaranteed Quotation row, including when Billing does not yet exist.
5. Before every Payment insert: `sum(POSTED payments) + new amount <= Billing/Accepted Quotation total`. The check is repeated under lock; the frontend balance is advisory only.
6. Billing `paid_amount`, `balance`, and state are derived from POSTED Payments. They are not editable persisted fields.
7. Overpayment, negative balance, refunds, credits, and excess-payment handling are outside V1.
8. Payments are never physically deleted as the correction path. Voiding records who, when, and why, and excludes the amount from derived totals.
9. Voiding the first/all Payments does not delete Billing or automatically demote a CONFIRMED Booking. Operational correction/cancellation is a separate explicit action.
10. Booking cancellation preserves accepted Quotation, Billing, Payments, and all financial snapshots.

## Confirmed rescheduling

1. Only a CONFIRMED Booking uses the dedicated reschedule action.
2. V1 reschedule may change Booking `event_date` and Booking Service start/end timestamps only. Service, Package, duration, quantity, price, fees, accepted Quotation, Billing, and Payments remain unchanged.
3. The action locks affected Services and Staff deterministically, excludes the Booking's old reservations/assignments, re-checks capacity and Staff conflicts, writes an immutable Reschedule header/items, updates current schedules, and keeps status CONFIRMED in one transaction.
4. Each history row preserves old/new event dates, old/new line endpoints, optional reason, acting Admin User, and change time.
5. Accepted Quotation and Billing retain the originally accepted schedule snapshots. Current operational schedule comes from Booking/Booking Services; change history explains the difference.

## Historical and deletion integrity

1. Snapshot lineage is Master Data -> Booking -> Quotation -> Billing. Each boundary copies the data required to render that record without current master data.
2. Exact prices, quantities, durations, Service/Package names, customer/event details, currency, and seller identity are not recomputed on historical documents.
3. Historical/financial parent foreign keys restrict deletion. Master records with references are deactivated.
4. Cascades are limited to configuration/counter rows on Organization deletion and removable Staff assignments when a pre-acceptance Booking Service is explicitly removed.
5. Calendar and Dashboard are queries/read models, not V1 source-of-truth tables.

## Document numbering

1. Booking, Quotation, and Billing numbers are allocated under a locked `(organization_id, document_type, year)` sequence row; `MAX(...) + 1` is forbidden.
2. The year is determined at document creation in the application timezone, not from the Booking event date.
3. Allocation and document insert share one transaction. Existing numbers and snapshots never change when prefixes change.
4. Rollbacks do not consume numbers. Gaps from retained cancelled/voided business history are acceptable; numbers are never reused.
