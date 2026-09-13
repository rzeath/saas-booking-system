# TakdaOps V1 Domain Model

## Purpose and scope

This document is the authoritative V1 data-model proposal for TakdaOps. Phase 2A is design-only: the tables below are proposals for later migrations, not an implementation in the current repository.

TakdaOps is an internal, multi-tenant Event Booking Management system for photobooth and related event-service businesses. The model deliberately excludes public booking, customer accounts, organization memberships, roles, named equipment units, automatic staff assignment, generic pricing rules, subscriptions, notifications, and reporting aggregates.

## Existing foundation

The following foundation already exists and must be extended rather than replaced:

- `organizations`: `id`, `name`, `status`, timestamps.
- `users`: `id`, unique `organization_id`, `name`, globally unique `email`, password, timestamps. The unique organization foreign key enforces at most one admin per organization; transactional registration supplies the V1 exactly-one operational invariant.
- `TenantContext`: obtains the authenticated `User` and derives its organization. Domain requests must continue to ignore caller-supplied tenant identifiers.
- Stateful Sanctum authentication, guest/authenticated API route groups, transactional organization/admin registration, and tenant/authentication feature tests.
- Laravel cache and queue-support tables. These are infrastructure tables, not TakdaOps business-domain tables.

No additional authentication, membership, role, or tenant table is proposed.

### Repository integration baseline

- The backend is Laravel with Sanctum's stateful session/CSRF flow. Public API surface is limited to health and guest registration/login; `/api/me` and logout are authenticated. Future domain routes belong inside the authenticated API group.
- `RegisterAdmin` already creates Organization and User in one transaction. Phase 2B may extend that transaction to provision Business Settings, but must not alter authentication or allow another admin.
- Existing feature tests cover registration rollback, one-admin database enforcement, tenant derivation, login/logout/current context, and health. Every later domain phase must keep this suite green and add cross-tenant route/query cases.
- The React/TypeScript frontend currently contains only protected/guest route guards, login/registration forms, an authenticated home shell, a centralized API client, and TanStack Query auth state. Later domain UI should extend these patterns rather than replace the auth shell.
- Docker Compose already supplies Nginx, PHP-FPM, MySQL 8.4, Redis, and Node/Vite on the `takdaops` network. The design relies on MySQL-enforced `CHECK` constraints, generated columns, transactions, and row locks available in that topology.
- TakdaOps naming is already present in application metadata, environment examples, Compose naming, README, and visible UI. Phase 2A adds no runtime or infrastructure changes.

## Shared persistence conventions

- Primary and foreign keys are unsigned `BIGINT` values.
- Application timestamps are stored in UTC using `DATETIME(6)` where domain precision matters. Date-only business values use `DATE`.
- Money uses non-negative `DECIMAL(13,2)`. Backend code must use decimal strings or an exact-money abstraction, never binary floating point.
- Currency is an ISO 4217 three-letter code. V1 uses one currency per organization, snapshotted onto commercial documents.
- Statuses are uppercase string codes with database `CHECK` constraints and matching backend enums.
- Active/inactive master data uses `is_active`; referenced master data is deactivated, not deleted.
- Human-readable names are `VARCHAR(255)` unless a smaller bound is stated. Notes and addresses are `TEXT` and nullable unless required below.
- Transactional and audit records are not soft-deleted or physically deleted through normal application flows.
- Every direct tenant key is assigned from `TenantContext`, never from request input.

Supporting composite unique indexes such as `(organization_id, id)` are intentional even though `id` is already globally unique. They allow composite foreign keys to enforce that two related rows belong to the same tenant. A later migration may add `(organization_id, id)` to `users` without changing authentication semantics.

## Tenant ownership policy

| Table | Tenant key | Reason |
| --- | --- | --- |
| `organizations` | Root | Existing tenant root. |
| `users` | Direct, existing | Authentication resolves the tenant from this key. |
| `business_settings` | Direct | One configuration row per tenant. |
| `document_sequences` | Direct | Allocation is tenant- and year-scoped. |
| `customers` | Direct | Tenant master data and common list boundary. |
| `event_types` | Direct | Tenant master data and one side of a cross-parent pricing rule. |
| `services` | Direct | Tenant master data and the capacity-locking target. |
| `packages` | Direct | Redundancy is justified by a composite foreign key proving that the parent Service belongs to the same tenant. |
| `service_rates` | Direct | Required to prove that Event Type and Package share a tenant and to support the hot pricing lookup. |
| `staff` | Direct | Tenant master data and staff-conflict query boundary. |
| `bookings` | Direct | Aggregate root, document number scope, and primary tenant query boundary. |
| `booking_services` | Direct | Required to enforce same-tenant Booking, Service, and Package relationships and support availability queries. |
| `booking_service_staff_assignments` | Direct | Required to enforce same-tenant Booking Service and Staff relationships. |
| `booking_reschedules` | Direct | Immutable tenant audit stream with Booking/admin consistency. |
| `booking_reschedule_items` | Derived through reschedule | Single parent; no second tenant-owned parent creates ambiguity. |
| `quotations` | Direct | Aggregate/document root, numbering scope, and same-tenant Booking enforcement. |
| `quotation_items` | Derived through quotation | Immutable child snapshot. The source Booking Service is lineage, not ownership. |
| `payments` | Direct | Financial query/isolation boundary and same-tenant Quotation/admin enforcement. |
| `billings` | Direct | Financial document/numbering boundary and same-tenant Quotation enforcement. |
| `billing_items` | Derived through billing | Immutable child snapshot with a single owning Billing. |

Derived children must only be loaded through their tenant-scoped aggregate. Their route binding must never be global and unscoped.

## Tenant and configuration

### Organization (`organizations`, existing)

Purpose: canonical tenant identity and lifecycle root.

- Keep existing fields and meaning. `organizations.name` is the canonical internal/registration identity shown in tenant administration.
- `status` controls whether the tenant may use the system.
- Do not edit the existing migration in Phase 2A.
- Organization creation remains coupled transactionally to creation of its one Admin User.

### Admin User (`users`, existing)

Purpose: the sole V1 login account for an Organization.

- Keep the existing unique `organization_id`; no memberships or roles are added.
- Admin audit columns such as `created_by`, `changed_by`, and `voided_by` reference this table.
- A supporting unique `(organization_id, id)` index is proposed only for same-tenant composite foreign keys. It does not change login behavior.
- Operational Staff remain completely separate records.

### Business Settings (`business_settings`, V1)

Purpose: the Organization's operational defaults and generated-document identity.

Important fields:

- `id`; `organization_id` (unique).
- `display_name` (required), initialized from `organizations.name`.
- nullable `email`, `phone`, `address`, `logo_path`.
- `timezone` (IANA zone; default `Asia/Manila`).
- `currency` (`CHAR(3)`; default `PHP`).
- `booking_prefix`, `quotation_prefix`, `billing_prefix` (`VARCHAR(10)`; defaults `BK`, `QT`, `INV`).
- timestamps.

`organizations.name` remains canonical tenant identity. `business_settings.display_name` deliberately owns the editable document/display identity, avoiding two columns both called “business name.” Commercial documents snapshot the setting values they need so later edits do not rewrite history.

Constraints and indexes:

- Unique `organization_id` gives exactly one settings row per Organization once provisioned.
- Prefixes must be non-empty and limited to an implementation-approved uppercase character set.
- Timezone and currency validity are backend-authoritative; the database can enforce length/non-empty shape but not the IANA registry.
- Organization deletion may cascade to this configuration-only row, but operational Organization deletion is restricted by business records.

### Document Sequence (`document_sequences`, V1)

Purpose: concurrency-safe, tenant-specific, year-aware allocation for Booking, Quotation, and Billing numbers.

Important fields:

- `id`, `organization_id`.
- `document_type`: `BOOKING`, `QUOTATION`, or `BILLING`.
- `year` (`SMALLINT UNSIGNED`).
- `next_number` (`BIGINT UNSIGNED`, initially `1`).
- timestamps.

Constraints and indexes:

- Unique `(organization_id, document_type, year)`.
- Checks: `year >= 2000`, `next_number >= 1`, and allowed document types.
- Cascade with Organization is acceptable because this row is only a counter; generated document numbers remain stored on historical documents.

Allocation occurs inside the same transaction as document creation. `INSERT IGNORE` first ensures the row exists, then `SELECT ... FOR UPDATE` locks it, the current value is allocated, and `next_number` is incremented. The year is the document-creation year in the Organization timezone, not the Booking's event year. The prefix is read from Business Settings, but is not part of the sequence key; changing a prefix does not reset the year's sequence. Numbers use `<prefix>-<YYYY>-<six-or-more-digit sequence>`. Rollbacks do not consume a number; gaps caused by later cancellation or retained historical documents are acceptable.

## Master data

### Customer (`customers`, V1)

Purpose: reusable tenant-owned customer master data, not a CRM.

Fields: `id`, `organization_id`, required `name`, nullable `email`, `phone`, `address`, `notes`, `is_active` default true, timestamps.

Constraints and indexes:

- Composite unique `(organization_id, id)` supports tenant-safe references.
- Index `(organization_id, is_active, name)` for tenant lists/search.
- No customer code and no uniqueness constraint on name, email, or phone.
- Restrict deletion once referenced by a Booking; use deactivation.

Customer identity/contact snapshots live on each Booking. Quotation and Billing then copy the Booking snapshots to remain independently renderable.

### Event Type (`event_types`, V1)

Purpose: tenant-owned event classification used by Bookings and pricing.

Fields: `id`, `organization_id`, `name`, `is_active` default true, timestamps.

Constraints and indexes:

- Unique `(organization_id, name)` under the database's case-insensitive application collation.
- Composite unique `(organization_id, id)`.
- Index `(organization_id, is_active, name)`.
- Restrict deletion once referenced; use deactivation.

A Booking has one Event Type. Booking Services never choose an independent Event Type. Its name is snapshotted on the Booking and downstream documents.

### Service (`services`, V1)

Purpose: a tenant-owned bookable service with pooled interchangeable capacity.

Fields: `id`, `organization_id`, `name`, `total_units` (`INT UNSIGNED`), `is_active` default true, timestamps.

Constraints and indexes:

- Unique `(organization_id, name)` and composite unique `(organization_id, id)`.
- Check `total_units > 0`.
- Index `(organization_id, is_active, name)`.
- Restrict deletion when Packages or Booking Services reference it; use deactivation.

The Service row is also the serialization/locking target for capacity-affecting writes. No named unit, equipment, or resource table exists.

### Package (`packages`, V1)

Purpose: a named commercial package belonging to exactly one Service.

Fields: `id`, `organization_id`, `service_id`, `name`, `is_active` default true, timestamps.

Constraints and indexes:

- Composite foreign key `(organization_id, service_id)` to `services(organization_id, id)`.
- Unique `(organization_id, service_id, name)`; the same name may exist under different Services.
- Composite unique `(organization_id, id)` and `(organization_id, service_id, id)` support tenant/pair-safe child references.
- Index `(organization_id, service_id, is_active, name)`.
- Restrict Service/Package deletion once referenced; use deactivation.

There is no Service/Package many-to-many pivot.

### Service Rate (`service_rates`, V1)

Purpose: the single configured price for an Event Type, Package, and duration.

Fields: `id`, `organization_id`, `event_type_id`, `package_id`, `duration_minutes` (`INT UNSIGNED`), `unit_rate` (`DECIMAL(13,2)`), `is_active` default true, timestamps.

Constraints and indexes:

- Composite tenant-safe foreign keys to Event Type and Package.
- Unique `(organization_id, event_type_id, package_id, duration_minutes)`.
- Checks `duration_minutes > 0` and `unit_rate >= 0`.
- Pricing lookup index `(organization_id, event_type_id, package_id, duration_minutes, is_active)`; the unique index may cover most of this lookup, with activity filtered afterward.
- Restrict referenced master deletion.

`service_id` is not stored: Package already determines exactly one Service. V1 keeps one mutable configuration row per combination. Price changes update that row; active toggling disables/re-enables it. Historical prices are preserved by Booking Service, Quotation Item, and Billing Item snapshots, so duplicate replacement Rate rows are unnecessary. Missing or inactive Rates make the combination unavailable; they never imply a zero price.

### Staff (`staff`, V1)

Purpose: tenant-owned operational people who may be manually assigned to Booking Services.

Fields: `id`, `organization_id`, required `name` and `phone`, nullable `email` and `notes`, `is_active` default true, timestamps.

Constraints and indexes:

- Composite unique `(organization_id, id)`.
- Index `(organization_id, is_active, name)`.
- No login, `user_id`, role, shift, capacity, or default assignment fields.
- Restrict deletion when assignments exist; use deactivation.

## Booking and scheduling

### Booking (`bookings`, V1)

Purpose: tenant-owned aggregate root for one customer event.

Important fields:

- `id`, `organization_id`, `booking_number`, `customer_id`, `event_type_id`.
- `event_name`, `event_date` (tenant-local `DATE`), `timezone` (IANA snapshot).
- required `venue_name`, nullable `venue_address`.
- required `contact_person`, `contact_number`.
- Customer snapshots: `customer_name`, nullable `customer_email`, `customer_phone`, `customer_address`.
- `event_type_name` snapshot.
- `status`: `PENDING`, `QUOTED`, `CONFIRMED`, `COMPLETED`, `CANCELLED`.
- nullable `internal_notes`.
- `created_by`; nullable `completed_at`, `completed_by`, `cancelled_at`, `cancelled_by`, `cancellation_reason`.
- timestamps.

Constraints and indexes:

- Unique `(organization_id, booking_number)` and composite unique `(organization_id, id)`.
- Supporting unique `(id, organization_id)`/scope indexes should follow the exact column order required by child composite foreign keys; migration review must avoid creating duplicate indexes that differ only semantically.
- Composite tenant-safe foreign keys to Customer and Event Type; composite tenant-safe admin audit foreign keys.
- Indexes `(organization_id, status, event_date)`, `(organization_id, customer_id, event_date)`, and `(organization_id, event_type_id)`.
- Status and audit-field consistency is primarily a transition-service invariant, supplemented by feasible checks (for example, cancellation metadata is present only for `CANCELLED`).
- Restrict master, Organization, and User deletion. Booking deletion is not a normal application operation.

The Booking snapshot is the first historical boundary: Customer or Event Type master edits do not change an existing event. Before quotation acceptance, explicit Booking edits may intentionally refresh relevant snapshots and prices in one domain operation. After acceptance, commercial fields are locked. A confirmed reschedule may change only `event_date` and Booking Service timestamps while preserving commercial snapshots.

### Booking Service (`booking_services`, V1)

Purpose: one purchased Service/Package line with its own schedule, quantity, and authoritative price snapshot.

Important fields:

- `id`, `organization_id`, `booking_id`, `service_id`, `package_id`.
- `start_at_utc`, `end_at_utc` (`DATETIME(6)`).
- `duration_minutes` (`INT UNSIGNED`).
- `quantity` (`INT UNSIGNED`).
- `service_name`, `package_name` snapshots.
- `unit_rate` and `line_total` (`DECIMAL(13,2)`) snapshots.
- `sort_order` (`SMALLINT UNSIGNED`, default 0), timestamps.

Constraints and indexes:

- Composite tenant-safe foreign key to Booking.
- Composite foreign key `(organization_id, service_id, package_id)` to the Package's `(organization_id, service_id, id)` proves that the selected Package belongs to the selected Service and tenant.
- A tenant-safe Service foreign key is retained because Service is the capacity target.
- Checks: `start_at_utc < end_at_utc`, `duration_minutes > 0`, `quantity > 0`, `unit_rate >= 0`, and `line_total = unit_rate * quantity`.
- Backend validation additionally guarantees `end_at_utc = start_at_utc + duration_minutes`; that cross-column temporal calculation should not rely solely on database portability.
- Supporting unique `(booking_id, id)` and `(organization_id, id)` keys enable same-Booking and same-tenant child constraints.
- Index `(organization_id, service_id, start_at_utc, end_at_utc, booking_id)` for overlap candidates; index `(organization_id, booking_id, sort_order)` for aggregate loading.
- Restrict Booking, Service, and Package deletion. A pre-acceptance line may be removed only through an aggregate action that first handles draft quotation items/assignments; accepted lines are immutable.

`event_date` is not duplicated here. Full UTC endpoints safely represent cross-midnight schedules and support direct overlap predicates. The Booking's `event_date` and `timezone` retain the intended local calendar context. Input local date/time must be resolved in that snapshotted IANA timezone and converted server-side; JavaScript must not parse date-only values as UTC.

### Booking Service Staff Assignment (`booking_service_staff_assignments`, V1)

Purpose: optional manual many-to-many assignment of Staff to Booking Services.

Fields: `id`, `organization_id`, `booking_service_id`, `staff_id`, `assigned_by`, `assigned_at`.

Constraints and indexes:

- Composite tenant-safe foreign keys to Booking Service, Staff, and Admin User.
- Unique `(booking_service_id, staff_id)` prevents duplicate assignment.
- Index `(organization_id, staff_id, booking_service_id)` supports conflict lookup.
- Cascade only when a removable pre-acceptance Booking Service is explicitly deleted; restrict Staff/User deletion. Confirmed/financially historical Booking Services cannot be deleted.

No assignment count is required. Staff conflicts are checked only when assigning Staff or rescheduling an already assigned Booking Service.

### Booking Reschedule (`booking_reschedules`, V1)

Purpose: immutable header for one confirmed-booking reschedule operation.

Fields: `id`, `organization_id`, `booking_id`, `previous_event_date`, `new_event_date`, nullable `reason`, `changed_by`, `changed_at` (UTC).

Constraints and indexes:

- Composite tenant-safe foreign keys to Booking and Admin User.
- Composite unique `(organization_id, id)` and supporting unique `(booking_id, id)`.
- Index `(organization_id, booking_id, changed_at)`.
- Restrict all parent deletion. No update/delete endpoint exists.

### Booking Reschedule Item (`booking_reschedule_items`, V1)

Purpose: immutable per-line schedule delta belonging to a Booking Reschedule.

Fields: `id`, `booking_id` (scope key), `booking_reschedule_id`, `booking_service_id`, `previous_start_at_utc`, `previous_end_at_utc`, `new_start_at_utc`, `new_end_at_utc`.

Constraints and indexes:

- Composite foreign keys through `booking_id` to Booking Reschedule and Booking Service ensure both belong to the same Booking; unique `(booking_reschedule_id, booking_service_id)`.
- Checks that each start precedes its corresponding end.
- Index `(booking_service_id, booking_reschedule_id)`.
- Restrict deletion. Tenant ownership derives from the immutable Reschedule aggregate; `booking_id` is present for relational integrity, not as a second ownership root.

The two-table shape avoids opaque JSON while remaining much smaller than generic event sourcing. Duration does not change during a V1 reschedule, so old/new duration fields are unnecessary.

## Commercial documents

### Quotation (`quotations`, V1)

Purpose: immutable-on-send commercial revision for a Booking. A Booking can retain many Quotations.

Important fields:

- `id`, `organization_id`, `booking_id`, `quotation_number`.
- `status`: `DRAFT`, `SENT`, `ACCEPTED`, `REJECTED`, `CANCELLED`, `EXPIRED`, `OUTDATED`.
- nullable `valid_until`; nullable `sent_at`, `accepted_at`, `closed_at`.
- Seller snapshots: `business_display_name`, nullable business email/phone/address/logo path. A snapshotted logo path must address an immutable/versioned asset; replacing bytes at the same path would break history.
- Customer snapshots: name and nullable email/phone/address.
- Event snapshots: Event Type name, event name/date/timezone, venue name/address, contact person/number.
- `currency`, `subtotal`, `transportation_fee`, `crew_meal_fee`, `discount_amount`, `total` (`DECIMAL(13,2)`).
- generated nullable `active_slot`: `1` for `DRAFT`/`SENT`, otherwise `NULL`.
- generated nullable `accepted_slot`: `1` for `ACCEPTED`, otherwise `NULL`.
- `created_by`, timestamps.

Constraints and indexes:

- Unique `(organization_id, quotation_number)`, composite unique `(organization_id, id)`, and supporting unique `(booking_id, id)`.
- Composite tenant-safe foreign keys to Booking and Admin User.
- Unique `(booking_id, active_slot)` gives at most one combined Draft/Sent quotation because MySQL permits multiple `NULL` values in a unique index.
- Unique `(booking_id, accepted_slot)` gives at most one Accepted quotation per Booking.
- Checks: all monetary components non-negative; `total = subtotal + transportation_fee + crew_meal_fee - discount_amount`; discount cannot exceed gross; allowed statuses.
- A quotation must have `total > 0` before it can be sent/accepted because V1 confirmation requires a positive first Payment and has no zero-total confirmation path.
- Index `(organization_id, booking_id, status, created_at)` and `(organization_id, status, valid_until)`.
- Restrict all parent deletion; no physical deletion after send.

The generated-slot constraints are real MySQL generated-column indexes, not conditional-index pseudo-syntax. Application actions must still lock the Booking row while creating/sending/accepting a Quotation to coordinate item generation and return domain-friendly errors.

### Quotation Item (`quotation_items`, V1)

Purpose: immutable commercial snapshot generated from exactly one Booking Service.

Fields: `id`, `booking_id` (scope key), `quotation_id`, `booking_service_id`, `service_name`, `package_name`, `start_at_utc`, `end_at_utc`, `duration_minutes`, `quantity`, `unit_rate`, `line_total`, `sort_order`.

Constraints and indexes:

- Composite foreign keys through `booking_id` to Quotation and source Booking Service ensure the item source belongs to the quoted Booking; unique `(quotation_id, booking_service_id)`.
- Checks mirror Booking Service temporal, quantity, and exact-total constraints.
- Supporting unique `(quotation_id, id)` and index `(quotation_id, sort_order)`.
- Restrict source/parent deletion after send. Draft item replacement is performed explicitly within the aggregate transaction.

Quotation Items copy, rather than recompute, Booking Service snapshots. They cannot independently edit Service, Package, schedule, duration, quantity, or base price. An accepted Quotation remains historically unchanged when a confirmed Booking is later rescheduled.

## Financial records

### Payment (`payments`, V1)

Purpose: immutable financial entry against one Accepted Quotation, corrected only by voiding.

Fields: `id`, `organization_id`, `quotation_id`, `amount` (`DECIMAL(13,2)`), `payment_method` (`VARCHAR(50)` stable code), nullable `reference_number`, `paid_at` (UTC), nullable `notes`, `status` (`POSTED` or `VOIDED`), `created_by`, nullable `voided_at`, `void_reason`, `voided_by`, timestamps.

Constraints and indexes:

- Composite tenant-safe foreign keys to Quotation and both Admin User references.
- Check `amount > 0` and allowed status.
- Status/void metadata consistency checks where practical: POSTED has no void metadata; VOIDED requires all void fields.
- Indexes `(organization_id, quotation_id, status, paid_at)` and `(organization_id, paid_at)`; reference numbers are not globally unique.
- Restrict parent/User/Organization deletion. No physical-delete path.

Payment methods are stable backend codes from an implementation-approved V1 list; the schema does not create a configurable payment-method domain. A Payment carries no mutable balance. Paid amount is the exact sum of POSTED Payments for its Quotation.

### Billing (`billings`, V1)

Purpose: one retained invoice-like financial document created by the first valid Payment on an Accepted Quotation.

Important fields:

- `id`, `organization_id`, `quotation_id` (unique), `billing_number`, `issued_at` (UTC).
- The same seller, customer, and event snapshot groups as Quotation.
- `currency`, `subtotal`, `transportation_fee`, `crew_meal_fee`, `discount_amount`, `total`.
- `created_by`, timestamps.

Constraints and indexes:

- Unique `quotation_id`; unique `(organization_id, billing_number)`; composite unique `(organization_id, id)`; supporting unique `(quotation_id, id)`.
- Composite tenant-safe foreign keys to Accepted Quotation and Admin User. Acceptance itself remains an application-state precondition.
- Exact-total and non-negative checks mirror Quotation; `total > 0`.
- Index `(organization_id, issued_at)`.
- Restrict every parent deletion. Billing persists even when all Payments are later voided or the Booking is cancelled.

`paid_amount`, `balance`, and payment state are derived at read time from POSTED Payments against `quotation_id`:

```text
paid_amount = SUM(posted payment.amount)
balance = billing.total - paid_amount
UNPAID          when paid_amount = 0
PARTIALLY_PAID  when 0 < paid_amount < billing.total
PAID            when paid_amount = billing.total
```

They are not editable/stored columns.

### Billing Item (`billing_items`, V1)

Purpose: immutable copy of one accepted Quotation Item at Billing creation.

Fields: `id`, `quotation_id` (scope key), `billing_id`, `quotation_item_id`, service/package names, start/end UTC, duration, quantity, unit rate, line total, sort order.

Constraints and indexes:

- Composite foreign keys through `quotation_id` to Billing and source Quotation Item ensure every item comes from the Billing's accepted Quotation; unique `(billing_id, quotation_item_id)`.
- Checks mirror Quotation Item.
- Index `(billing_id, sort_order)`.
- Restrict deletion.

Billing and all items are copied atomically from the Accepted Quotation. They never recompute from current Booking or master data.

## Snapshot lineage

```text
Customer + Event Type + Service + Package + Rate + Business Settings
    -> Booking + Booking Services
    -> Quotation + Quotation Items
    -> Billing + Billing Items
```

- Booking owns the first customer/event/service/package/rate snapshots used during editable planning.
- Quotation copies all fields needed to render the exact commercial offer independently.
- Billing copies the accepted offer independently for financial permanence.
- Foreign keys retain lineage and restrict deletion; snapshots retain meaning after permitted master-data renames.
- Confirmed rescheduling changes only current Booking scheduling and appends Reschedule records. It does not rewrite accepted Quotation or Billing schedules.

## Database constraint and deletion checklist

Unless a field is explicitly described as nullable in its domain section, it is required. Every proposed table uses an unsigned `BIGINT id` primary key. The following is the migration-review checklist; “tenant-safe” means the foreign key includes `organization_id` and targets a matching composite unique key.

| Table | Foreign keys and delete action | Uniques and key indexes | Checks / nullability notes |
| --- | --- | --- | --- |
| `business_settings` | Organization CASCADE | unique Organization | Contact/address/logo nullable; timezone/currency/prefixes required and non-empty. |
| `document_sequences` | Organization CASCADE | unique `(organization_id, document_type, year)` | Type allow-list; `year >= 2000`; `next_number >= 1`. |
| `customers` | Organization RESTRICT | `(organization_id, id)`; list index `(organization_id, is_active, name)` | Email/phone/address/notes nullable. |
| `event_types` | Organization RESTRICT | unique `(organization_id, name)` and `(organization_id, id)`; active-name list index | Name required. |
| `services` | Organization RESTRICT | unique `(organization_id, name)` and `(organization_id, id)`; active-name list index | `total_units > 0`. |
| `packages` | Organization RESTRICT; tenant-safe Service RESTRICT | unique `(organization_id, service_id, name)`, `(organization_id, id)`, `(organization_id, service_id, id)`; active Service list index | All domain fields required. |
| `service_rates` | Organization, tenant-safe Event Type, and tenant-safe Package all RESTRICT | unique Rate tuple; pricing lookup index | `duration_minutes > 0`; `unit_rate >= 0`. |
| `staff` | Organization RESTRICT | `(organization_id, id)`; active-name list index | Email/notes nullable; name/phone required. |
| `bookings` | Organization, tenant-safe Customer/Event Type/Admin references all RESTRICT | unique tenant booking number; tenant/scope keys; status-date, customer-date, Event Type indexes | Customer contact snapshots, venue address, notes, and lifecycle audit fields nullable; status allow-list and lifecycle consistency. |
| `booking_services` | Organization, tenant-safe Booking/Service/Package pair all RESTRICT | `(organization_id, id)`, `(booking_id, id)`; Booking-order and Service-overlap indexes | Positive duration/quantity; ordered endpoints; non-negative money; exact line total. |
| `booking_service_staff_assignments` | Organization RESTRICT; Booking Service CASCADE only for an allowed pre-acceptance line removal; tenant-safe Staff/Admin RESTRICT | unique `(booking_service_id, staff_id)`; Staff-conflict index | All fields required. |
| `booking_reschedules` | Organization, tenant-safe Booking/Admin all RESTRICT | `(organization_id, id)`, `(booking_id, id)`; Booking history index | Reason nullable; append-only. |
| `booking_reschedule_items` | same-Booking composite Reschedule and Booking Service references RESTRICT | unique `(booking_reschedule_id, booking_service_id)`; Booking Service history index | Both intervals ordered; at least one schedule value changes (application invariant). |
| `quotations` | Organization, tenant-safe Booking/Admin all RESTRICT | unique tenant quotation number; `(organization_id, id)`, `(booking_id, id)`; generated active and accepted slot uniques; Booking/status and expiry indexes | Valid-until and lifecycle timestamps nullable as state permits; snapshot contacts nullable; allowed status; exact/non-negative totals; positive total required before send. |
| `quotation_items` | same-Booking composite Quotation and Booking Service references RESTRICT | unique `(quotation_id, booking_service_id)`, `(quotation_id, id)`; item-order index | Positive duration/quantity; ordered endpoints; non-negative money; exact line total. |
| `payments` | Organization, tenant-safe Quotation/creator/voider all RESTRICT | Quotation-status-paid-at and tenant-paid-at indexes | Reference/notes/void data nullable until void; positive amount; status allow-list and void metadata consistency. |
| `billings` | Organization, tenant-safe Quotation/Admin all RESTRICT | unique Quotation and tenant billing number; `(organization_id, id)`, `(quotation_id, id)`; issued-at index | Snapshot contacts nullable; exact/non-negative totals; positive total. |
| `billing_items` | same-Quotation composite Billing and Quotation Item references RESTRICT | unique `(billing_id, quotation_item_id)`; Billing-order index | Positive duration/quantity; ordered endpoints; non-negative money; exact line total. |

Aggregate cardinalities such as “a Booking has at least one Booking Service” and “a sent Quotation/Billing has at least one item” cannot be enforced by an ordinary foreign key. Their creation actions enforce them inside the aggregate transaction.

Organization/User are existing tables. Their current Organization-to-User cascade is preserved. Once restrictive business/audit references exist, an Organization or audited User with history cannot be physically removed; Organization deactivation is the normal lifecycle operation.

## Deferred concepts

The following are explicitly not V1 tables or columns: organization memberships, roles, customer logins, equipment/resources, staff shifts, automatic assignments, public content, inquiry/gallery data, subscription records, generic price rules, per-guest pricing, refunds/credits, notification/email/SMS logs, and reporting aggregates.
