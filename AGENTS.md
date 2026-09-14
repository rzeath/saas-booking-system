# AGENTS.md

## Project Overview

This repository contains TakdaOps, a new multi-tenant Event Booking Management System.

The product is initially focused on photobooth businesses, but the architecture should remain capable of supporting other event-service businesses in the future without redesigning the core booking model.

This is a new standalone system. It is NOT a conversion of the existing EA Creatives photobooth application.

The system is an internal management application only.

There are no public-facing booking pages, public galleries, public inquiries, public service catalogs, or public tenant websites in V1.

---

## Development Principles

Before modifying code:

1. Read this file completely.
2. Read any nested `AGENTS.md` files that apply to the files being modified.
3. Inspect the existing implementation before making assumptions.
4. Check `git status --short`.
5. Preserve unrelated staged, unstaged, and untracked work.
6. Do not overwrite or revert user changes unless explicitly requested.
7. Prefer small, focused changes over broad rewrites.
8. Do not commit changes unless explicitly requested.
9. Do not introduce dependencies without a clear need.
10. Do not create database migrations casually. Understand the relevant domain and existing schema first.

When requirements are unclear, prefer preserving the existing architecture and ask before introducing major domain behavior.

---

## Planned Technology Stack

The intended architecture is a monorepo-style repository containing a separate frontend and backend.

### Frontend

Planned stack:

- React
- TypeScript
- Vite
- React Router
- TanStack Query
- React Hook Form
- Zod
- Tailwind CSS
- shadcn/ui / Radix UI
- Lucide icons
- Vitest
- React Testing Library

Use TypeScript strictly.

Prefer reusable application components over page-specific duplication.

Use accessible UI primitives.

Server state should normally be managed through TanStack Query rather than duplicated into unnecessary local state.

Use React Hook Form + Zod for non-trivial forms and validation.

Do not trust frontend validation as authoritative business validation.

### Backend

Planned stack:

- Laravel
- PHP
- Laravel Sanctum
- MySQL
- Redis
- PHPUnit / Laravel Feature Tests

The backend is authoritative for:

- tenant ownership
- booking state transitions
- availability
- pricing
- quotation state
- payment validation
- billing calculations
- financial history

Do not duplicate critical business rules only in the frontend.

### Infrastructure

Planned local/deployment topology:

- Docker Compose
- Nginx
- PHP-FPM
- MySQL
- Redis
- Node/Vite

Do not assume Docker configuration already exists. Inspect the repository first.

---

## Repository Structure

The intended top-level structure is approximately:

```text
/
├── frontend/
├── backend/
├── docker/
├── docker-compose.yml
├── README.md
└── AGENTS.md
```

Do not create additional top-level applications or architectural layers without a concrete requirement.

---

## Product Scope

TakdaOps is an internal Event Booking Management system.

Core domains are expected to include:

- Organization / Tenant
- Admin User
- Business Settings
- Customers
- Event Types
- Services
- Packages
- Service Rates
- Staff
- Bookings
- Booking Services
- Staff Assignments
- Booking Reschedule History
- Quotations
- Quotation Items
- Payments
- Billings
- Billing Items
- Calendar
- Dashboard

The exact database schema must be designed deliberately before migrations are created.

---

## Explicitly Out of Scope for V1

Do not introduce these without explicit approval:

- public website
- public booking flow
- public inquiry form
- public gallery
- customer portal
- custom tenant domains
- subscription billing
- marketplace functionality
- multiple branches
- advanced staff shifts
- automatic staff assignment
- individually named booth/equipment units
- generic pricing-rule engine
- per-guest catering pricing
- multi-currency accounting
- multiple login users per tenant
- complex role/permission system

---

## Multi-Tenancy

The application is multi-tenant.

Each tenant represents one organization/business.

V1 rule:

```text
One Organization
    ↓
One Admin User
```

The admin user is the only system login for that tenant.

Operational Staff records are NOT login accounts.

Do not introduce organization membership tables or role systems unless the requirement changes.

### Tenant Isolation

All business-owned data must belong to an organization.

Typical tenant-scoped entities include:

- customers
- event types
- services
- packages
- rates
- staff
- bookings
- quotations
- payments
- billings
- business settings

Never trust an arbitrary `organization_id` submitted by the frontend.

Tenant context must be resolved from the authenticated admin user.

Conceptually:

```text
authenticated user
    ↓
user.organization_id
    ↓
tenant-scoped queries
```

A user from Organization A must never be able to read or mutate Organization B data by changing route IDs or request payloads.

Tenant isolation must be enforced server-side.

---

## Admin User vs Staff

These are separate concepts.

### User

The User is the tenant's admin account.

The User:

- logs into the system
- manages the tenant's data
- creates and manages bookings
- creates quotations
- records payments
- manages billing
- manages master data

### Staff

Staff are operational people who may be assigned to Booking Services.

Staff:

- do not log in
- are optional
- are manually assigned
- do not determine whether a Booking can initially be created

Do not automatically create Staff records for Users.

---

## Customer Rules

Customers are tenant-owned master data.

V1 does not require a generated Customer Code.

Expected basic customer information:

- name
- email, optional
- phone, optional
- address, optional
- notes, optional
- active/inactive state

Bookings also contain event-specific:

- contact person
- contact number

The Booking contact may differ from the Customer's normal contact information.

Historical transactional data must not silently change when Customer master data is edited later.

---

## Event Types

Event Types are organization-specific master data.

Examples may include:

- Wedding
- Birthday
- Corporate Event

Event Type participates in price resolution.

A Booking has one Event Type.

Do not duplicate independently editable Event Types on each Booking Service.

---

## Services

A Service represents a bookable event service.

Initial examples may include:

- 360 Video Booth
- Mirror Photobooth
- Standard Photobooth
- Telephone Booth

The architecture must not require all future Services to be photobooths.

### Capacity

For photobooth V1, Service capacity is pooled.

A Service has:

```text
total_units
```

Example:

```text
360 Video Booth
total_units = 3
```

Individual physical units are NOT tracked.

Do not create:

- resource/equipment unit records
- names such as `360-001`
- exact unit assignment
- resource assignment workflows

Booking Service quantity consumes the Service's pooled capacity.

---

## Packages

A Package belongs to exactly one Service.

```text
Service
    ↓
Packages
```

The same Package name may exist under different Services.

Example:

```text
360 Booth
└── Premium

Mirror Booth
└── Premium
```

This is valid.

Do not model Services and Packages as many-to-many in V1.

---

## Pricing

Pricing is backend-authoritative.

The conceptual pricing combination is:

```text
Event Type
+ Service
+ Package
+ Duration
= Rate
```

Rates belong to the tenant.

Duration should be represented using integer minutes rather than decimal hours.

Examples:

```text
120 = 2 hours
180 = 3 hours
240 = 4 hours
```

If there is no active configured Rate for a combination, that combination is not bookable.

Do not interpret a missing Rate as zero.

The frontend must not be trusted to submit the authoritative price.

The backend must resolve the appropriate Rate.

### Quantity

Booking Service quantity may be greater than 1.

```text
line_total = unit_rate × quantity
```

No quantity-based pricing or bulk-discount engine exists in V1.

### Price Overrides

Do not allow direct manual override of Booking Service base prices in V1.

Special commercial pricing should be represented through Quotation discounts.

---

## Historical Snapshots

Historical integrity is a core requirement.

Master-data changes must not rewrite historical transactions.

Transactional records should preserve appropriate snapshots of data such as:

- customer information
- service name
- package name
- event information
- pricing
- quantities
- durations

The intended lineage is:

```text
Master Data
    ↓
Booking / Booking Services
    ↓
Quotation / Quotation Items
    ↓
Billing / Billing Items
```

Do not recompute historical documents from current master data.

---

## Booking Model

A Booking represents one customer event.

Expected Booking-level information includes:

- organization
- booking number
- customer
- event type
- event name / occasion
- event date
- venue name
- venue address
- contact person
- contact number
- status
- internal notes
- creator
- timestamps

Actual purchased Services belong in Booking Services, not directly as columns on the Booking.

```text
Booking
└── Booking Services
    ├── Service
    ├── Package
    ├── Start At
    ├── End At
    ├── Duration
    ├── Quantity
    ├── Unit Price Snapshot
    ├── Line Total Snapshot
    └── Optional Staff Assignments
```

A Booking may contain multiple Booking Services.

---

## Booking Service Scheduling

The approved scheduling persistence model is:

```text
Booking
└── event_date (Philippine business date)

Booking Service
├── start_at
├── end_at
└── duration_minutes
```

Each Booking Service has its own:

- Asia/Manila start and end date-times
- duration in minutes
- quantity

Different Booking Services within the same Booking may:

- start at different times
- have different durations

Do not assume one shared duration or start time for all Booking Services.

The Booking's event date provides the Philippine event-day context. Scheduling is stored and presented directly in Asia/Manila without tenant timezone conversion.

---

## Availability

For photobooth Services, capacity is based on pooled Service quantity.

Availability operates directly on Asia/Manila date-times and must follow half-open interval semantics:

```text
[start_at, end_at)
```

Therefore:

```text
6:00 PM - 9:00 PM
9:00 PM - 12:00 AM
```

do not overlap.

Capacity logic conceptually follows:

```text
sum(overlapping reserved quantity)
+ requested quantity
<= service.total_units
```

Availability must be validated server-side.

For writes that affect availability:

1. validate
2. begin transaction
3. acquire deterministic locks where appropriate
4. re-check availability
5. persist

Do not rely only on a frontend availability check.

---

## Booking Statuses

V1 Booking statuses:

```text
PENDING
QUOTED
CONFIRMED
COMPLETED
CANCELLED
```

Expected primary flow:

```text
PENDING
→ QUOTED
→ CONFIRMED
→ COMPLETED
```

Cancellation may occur from:

```text
PENDING
QUOTED
CONFIRMED
```

Completed Bookings should not normally transition directly to Cancelled.

### Capacity Reservation

These statuses reserve Service capacity:

```text
PENDING
QUOTED
CONFIRMED
```

These do not:

```text
COMPLETED
CANCELLED
```

---

## Staff Assignment

Staff assignment is optional.

A Booking Service may have:

```text
0..N Staff Assignments
```

There is:

- no required staff count
- no staff-per-unit rule
- no automatic assignment
- no staff requirement during Booking creation

Staff is assigned manually later.

When assigning Staff, check for schedule overlap with that Staff member's other assignments.

Staff availability does not block the initial Booking if no Staff has been assigned.

---

## Quotation Model

A Booking may have multiple Quotations over time.

This preserves revision and commercial history.

Quotation statuses are expected to include:

```text
DRAFT
SENT
ACCEPTED
REJECTED
CANCELLED
EXPIRED
OUTDATED
```

Only one active Draft/Sent quotation should exist for a Booking at a time.

Do not overwrite older Quotations when creating revisions.

Example:

```text
Booking
├── QT-001 OUTDATED
├── QT-002 REJECTED
└── QT-003 ACCEPTED
```

### Quotation Items

Quotation Items are generated from Booking Services.

Quotation Items may not independently change:

- Service
- Package
- Service start time
- Duration
- Quantity
- base unit price

If these details need changing before acceptance, change the Booking first and regenerate/rebuild the relevant Quotation.

Quotation-level commercial adjustments may include:

- transportation fee
- crew meal fee
- discount
- valid-until date

---

## Booking Changes and Quotations

If a SENT Quotation exists and commercial Booking details change, the Sent Quotation becomes OUTDATED.

The Booking should return to PENDING until a new quotation is sent.

Commercial changes include things such as:

- customer
- event type
- service
- package
- quantity
- duration
- pricing-related information

Once a Quotation is ACCEPTED, commercial Booking details should not be silently edited.

V1 should block normal commercial edits after acceptance.

Future amendment workflows may be added later.

---

## Booking Confirmation

Accepting a Quotation alone does NOT confirm the Booking.

The intended confirmation flow is:

```text
Quotation ACCEPTED
        ↓
First valid Payment recorded
        ↓
Billing automatically created
        ↓
Booking becomes CONFIRMED
```

There is no configured:

- downpayment percentage
- minimum downpayment amount
- automatic required deposit calculation

The business owner decides what amount is acceptable as the first/downpayment.

---

## Rescheduling Confirmed Bookings

A CONFIRMED Booking may be rescheduled.

Rescheduling is a dedicated operation, not ordinary commercial editing.

V1 rescheduling may change:

- event date
- Booking Service start times

It does not change:

- Service
- Package
- Duration
- Quantity
- price
- commercial fees

Those are commercial amendments and are outside the normal Reschedule flow.

When rescheduling:

1. re-check Service capacity
2. exclude the Booking's existing reservations from self-conflict calculations
3. check conflicts for Staff already assigned
4. preserve financial records
5. preserve accepted Quotation
6. preserve Billing
7. preserve Payments
8. record reschedule history
9. keep Booking status CONFIRMED

Do not silently overwrite confirmed schedule history without an audit record.

---

## Payments

Payments belong to the specific ACCEPTED Quotation.

Payments may only be recorded against an Accepted Quotation.

Expected Payment states:

```text
POSTED
VOIDED
```

Never delete a Payment merely because it was entered incorrectly.

Use a void operation and preserve audit information.

Relevant payment information may include:

- amount
- payment method
- reference number, optional
- paid date/time
- notes
- status
- created by
- voided at
- void reason
- voided by

### Overpayment

V1 does not allow overpayment.

Conceptually:

```text
new_payment_amount <= remaining_balance
```

Do not allow Billing balances to become negative.

Refunds, credits, and excess-payment handling are separate future features.

---

## Billing

Billing is a separate domain.

A Billing belongs to the specific Accepted Quotation that produced it.

There should be at most one Billing for an Accepted Quotation.

The first successful Payment automatically creates Billing if one does not already exist.

Billing snapshots the Accepted Quotation.

The Booking becomes CONFIRMED as part of this transaction.

Conceptual transaction:

```text
record first Payment
    ↓
create Billing if absent
    ↓
confirm Booking
```

These operations should be atomic.

### Billing Totals

Paid amount and remaining balance should be derived from non-void Payments.

Conceptually:

```text
paid_amount = SUM(POSTED payments)
balance = billing.total - paid_amount
```

Billing payment state is derived:

```text
UNPAID
PARTIALLY_PAID
PAID
```

Do not make paid amount or balance arbitrary editable financial fields.

### Cancellation and Voids

Cancelling a Booking must not delete:

- Quotations
- Billing
- Payments
- historical snapshots

Voiding the first Payment should not silently demote a CONFIRMED Booking.

Booking cancellation/state correction must be an explicit operation.

Financial and operational state are related but not identical.

---

## Financial Integrity

Financial records require stronger safeguards than ordinary CRUD.

For Payment creation:

- validate against the Accepted Quotation
- lock relevant financial records when necessary
- re-check remaining balance inside the transaction
- prevent concurrent overpayment

Never derive authoritative remaining balance exclusively in the frontend.

Do not mutate historical financial snapshots because current master data changed.

Prefer non-destructive corrections such as voiding rather than deletion.

---

## Business Settings

Each Organization has business settings used for system identity and generated documents.

Likely settings include:

- display name
- email
- phone
- address
- logo
- currency
- booking number prefix
- quotation number prefix
- billing number prefix

Initial expected defaults may include:

```text
currency = PHP
booking prefix = BK
quotation prefix = QT
billing prefix = INV
```

Do not scatter these values as unrelated hardcoded constants throughout the application.

`Organization.name` is the canonical tenant/business identity. `BusinessSetting.display_name` is the business/document presentation name. They may initially contain the same value, but changing `display_name` must not rename the Organization record.

---

## Document Numbering

Bookings, Quotations, and Billings should have human-readable tenant-specific numbers.

Examples:

```text
BK-2027-000001
QT-2027-000001
INV-2027-000001
```

Sequences are tenant-scoped.

Do not generate sequential numbers with unsafe `MAX(...) + 1` logic.

Use transaction-safe sequence allocation.

The exact implementation should be decided during database design.

---

## Date, Time, and Money

### Time

The application timezone is Asia/Manila. It is configured globally through `APP_TIMEZONE` and is not tenant-selectable.

Business and event schedules are stored, calculated, and presented directly as Asia/Manila wall-clock date-times. Do not convert booking schedules to UTC and do not add tenant timezone snapshots.

Avoid unsafe JavaScript Date parsing/formatting behavior that can shift calendar dates unintentionally.

### Money

Do not use binary floating-point values for monetary persistence or financial calculations.

Use database decimal values or an equivalent exact-money representation.

Financial calculations must be reproducible server-side.

---

## Backend Architecture Guidelines

Prefer keeping controllers thin.

Controllers should generally:

1. authorize/resolve tenant context
2. validate request input
3. delegate domain behavior
4. return an API Resource/response

Complex domain operations should live in appropriately named services/actions rather than controllers.

Examples may include:

```text
CreateBooking
UpdateBooking
CheckServiceAvailability
CreateQuotation
AcceptQuotation
RecordPayment
CreateBilling
RescheduleBooking
VoidPayment
```

Do not create abstraction layers merely for architectural appearance.

Use transactions around operations involving multiple dependent writes.

---

## API Guidelines

Use consistent REST-style endpoints unless a domain action is clearer.

Explicit state-changing domain actions are acceptable for operations such as:

```text
/quotations/{quotation}/send
/quotations/{quotation}/accept
/payments/{payment}/void
/bookings/{booking}/reschedule
/bookings/{booking}/complete
/bookings/{booking}/cancel
```

Do not implement sensitive state transitions as arbitrary generic field updates.

API responses should expose only what the frontend needs.

Do not leak cross-tenant IDs or internal data unnecessarily.

---

## Frontend Guidelines

The frontend should reflect backend domain rules but must not be their only enforcement point.

Prefer:

- typed API interfaces
- centralized API clients
- reusable form controls
- reusable date/time controls
- accessible dialogs
- loading states
- empty states
- error states
- confirmation for destructive/state-changing actions

Avoid native form controls when the application already has an approved shared component for that purpose.

Do not introduce page-specific duplicated versions of shared UI primitives.

---

## Validation

Use layered validation.

Frontend:

- usability
- immediate feedback
- required fields
- schema validation

Backend:

- authoritative validation
- tenant ownership
- state transitions
- pricing
- availability
- financial limits
- relationships

Database:

- foreign keys
- uniqueness
- nullability
- appropriate indexes
- structural invariants

Never assume frontend validation is sufficient.

---

## Testing Expectations

Business-critical behavior must be covered by tests.

Priority backend test areas include:

- tenant isolation
- authentication
- Service capacity
- overlapping Booking Services
- back-to-back Booking Services
- quantity availability
- pricing resolution
- Booking lifecycle
- Quotation lifecycle
- stale/outdated Quotations
- accepted commercial locks
- Payment validation
- overpayment prevention
- Billing creation
- Booking confirmation
- payment void behavior
- rescheduling
- Staff conflicts
- cancellation history preservation

Frontend tests should focus on meaningful user behavior rather than implementation details.

Use stable, deterministic dates in tests.

Do not create tests that depend on today's real date unless testing current-date behavior itself.

---

## Database Design Rules

Do not start creating domain migrations until the ERD for the relevant area has been reviewed.

When designing tables:

- scope tenant-owned data correctly
- avoid redundant foreign keys unless they enforce a useful invariant or materially improve correctness/performance
- use foreign keys
- define useful unique constraints
- add indexes based on real query patterns
- preserve historical snapshots intentionally
- use nullable fields deliberately
- avoid premature generic schemas

Do not build a universal event-management schema.

Build for the current photobooth booking use case while preserving reasonable extension points.

---

## Scope Discipline

The product is photobooth-first.

Do not prematurely implement hypothetical Catering behavior.

Instead:

```text
build current capability cleanly
        ↓
avoid unnecessary photobooth-specific coupling
        ↓
extend later when a real business requirement exists
```

Examples:

Good:

```text
Service
Package
Rate
Booking Service
```

Avoid unnecessary names such as:

```text
PhotoboothBookingItem
BoothOnlyPackage
PhotoboothRate
```

when the concept is genuinely broader.

At the same time, do not introduce abstract generic engines solely for future possibilities.

---

## Change Workflow

For implementation tasks, follow this sequence:

1. Inspect repository state.
2. Read applicable `AGENTS.md`.
3. Identify existing related implementation.
4. Establish the intended change before editing.
5. Make the smallest coherent change.
6. Run focused tests.
7. Run broader affected tests when practical.
8. Inspect `git diff`.
9. Inspect `git status --short`.
10. Report exactly what changed and any remaining concerns.

Do not claim tests passed unless they were actually executed successfully.

Do not claim a file was unchanged without checking when that claim matters.

---

## Final Response Expectations

After implementation work, report the following.

### Modified Files

List modified, created, and deleted files.

### What Changed

Summarize behavior and architectural changes.

### Validation

List exact commands/tests run and their results.

### Risks / Follow-ups

Mention unresolved issues, assumptions, or deferred work.

### Git Status

State whether changes are staged, unstaged, or untracked when relevant.

### Suggested Commit Message

Provide one concise suggested commit message.

Do not create the commit unless explicitly instructed.
