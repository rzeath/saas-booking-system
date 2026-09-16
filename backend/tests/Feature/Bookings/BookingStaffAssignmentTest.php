<?php

namespace Tests\Feature\Bookings;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingService;
use App\Models\Customer;
use App\Models\EventType;
use App\Models\Organization;
use App\Models\Package;
use App\Models\Service;
use App\Models\ServiceRate;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BookingStaffAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_assignment_schema_has_tenant_audit_and_identity_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('booking_service_staff_assignments', [
            'organization_id',
            'booking_service_id',
            'staff_id',
            'assigned_by',
            'assigned_at',
        ]));
    }

    public function test_booking_service_accepts_multiple_staff_and_detail_returns_them(): void
    {
        [$admin, $organization] = $this->admin();
        [$customer, $eventType, $service, $package] = $this->catalog($organization);
        $staff = Staff::factory()->count(2)->for($organization)->sequence(
            ['name' => 'Zoe Santos'],
            ['name' => 'Ana Reyes'],
        )->create();

        $response = $this->actingAs($admin)->postJson('/api/v1/bookings', $this->payload(
            $customer,
            $eventType,
            $service,
            $package,
            staffIds: $staff->pluck('id')->all(),
        ))->assertCreated()
            ->assertJsonCount(2, 'booking_services.0.staff')
            ->assertJsonPath('booking_services.0.staff.0.name', 'Ana Reyes')
            ->assertJsonPath('booking_services.0.staff.1.name', 'Zoe Santos');

        $lineId = $response->json('booking_services.0.id');
        foreach ($staff as $member) {
            $this->assertDatabaseHas('booking_service_staff_assignments', [
                'organization_id' => $organization->id,
                'booking_service_id' => $lineId,
                'staff_id' => $member->id,
                'assigned_by' => $admin->id,
            ]);
        }

        $this->actingAs($admin)->getJson("/api/v1/bookings/{$response->json('id')}")
            ->assertOk()
            ->assertJsonCount(2, 'booking_services.0.staff');
    }

    public function test_persisted_booking_service_staff_endpoints_list_check_and_update_assignments(): void
    {
        [$admin, $organization] = $this->admin();
        [$customer, $eventType, $service, $package] = $this->catalog($organization);
        $assigned = Staff::factory()->for($organization)->create(['name' => 'Assigned Staff']);
        $replacement = Staff::factory()->for($organization)->create(['name' => 'Replacement Staff']);
        $created = $this->actingAs($admin)->postJson('/api/v1/bookings', $this->payload(
            $customer,
            $eventType,
            $service,
            $package,
            staffIds: [$assigned->id],
        ))->assertCreated();
        $lineId = $created->json('booking_services.0.id');

        $this->actingAs($admin)->getJson("/api/v1/booking-services/{$lineId}/staff-assignments")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $assigned->id);
        $this->actingAs($admin)->getJson("/api/v1/booking-services/{$lineId}/staff-availability")
            ->assertOk()
            ->assertJsonFragment(['id' => $assigned->id, 'available' => true])
            ->assertJsonFragment(['id' => $replacement->id, 'available' => true]);
        $this->actingAs($admin)->putJson("/api/v1/booking-services/{$lineId}/staff-assignments", [
            'staff_ids' => [$replacement->id],
        ])->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $replacement->id);

        $this->assertDatabaseMissing('booking_service_staff_assignments', [
            'booking_service_id' => $lineId,
            'staff_id' => $assigned->id,
        ]);
        $this->assertDatabaseHas('booking_service_staff_assignments', [
            'organization_id' => $organization->id,
            'booking_service_id' => $lineId,
            'staff_id' => $replacement->id,
            'assigned_by' => $admin->id,
        ]);

        $this->actingAs($admin)->putJson("/api/v1/booking-services/{$lineId}/staff-assignments", [
            'staff_ids' => [],
        ])->assertOk()
            ->assertJsonCount(0, 'data');
        $this->assertDatabaseMissing('booking_service_staff_assignments', [
            'booking_service_id' => $lineId,
            'staff_id' => $replacement->id,
        ]);
    }

    public function test_persisted_booking_service_staff_endpoints_hide_foreign_lines(): void
    {
        [$admin, $organization] = $this->admin();
        [$foreignAdmin, $foreignOrganization] = $this->admin();
        $staff = Staff::factory()->for($organization)->create();
        [$customer, $eventType, $service, $package] = $this->catalog($foreignOrganization);
        $booking = Booking::factory()->create([
            'organization_id' => $foreignOrganization->id,
            'customer_id' => $customer->id,
            'event_type_id' => $eventType->id,
            'created_by' => $foreignAdmin->id,
        ]);
        $line = BookingService::factory()
            ->forBooking($booking)
            ->forPackage($package, $service)
            ->create();

        $this->actingAs($admin)->getJson("/api/v1/booking-services/{$line->id}/staff-availability")
            ->assertNotFound();
        $this->actingAs($admin)->getJson("/api/v1/booking-services/{$line->id}/staff-assignments")
            ->assertNotFound();
        $this->actingAs($admin)->putJson("/api/v1/booking-services/{$line->id}/staff-assignments", [
            'staff_ids' => [$staff->id],
        ])->assertNotFound();
    }

    public function test_persisted_assignment_update_rejects_staff_schedule_conflicts(): void
    {
        [$admin, $organization] = $this->admin();
        [$customer, $eventType, $service, $package] = $this->catalog($organization);
        $staff = Staff::factory()->for($organization)->create();
        $unassigned = $this->actingAs($admin)->postJson('/api/v1/bookings', $this->payload(
            $customer,
            $eventType,
            $service,
            $package,
        ))->assertCreated();
        $this->actingAs($admin)->postJson('/api/v1/bookings', $this->payload(
            $customer,
            $eventType,
            $service,
            $package,
            staffIds: [$staff->id],
        ))->assertCreated();

        $lineId = $unassigned->json('booking_services.0.id');
        $this->actingAs($admin)->putJson("/api/v1/booking-services/{$lineId}/staff-assignments", [
            'staff_ids' => [$staff->id],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('staff_ids');
        $this->assertDatabaseMissing('booking_service_staff_assignments', [
            'booking_service_id' => $lineId,
            'staff_id' => $staff->id,
        ]);
    }

    public function test_duplicate_and_cross_tenant_staff_assignments_are_rejected(): void
    {
        [$admin, $organization] = $this->admin();
        [, $otherOrganization] = $this->admin();
        [$customer, $eventType, $service, $package] = $this->catalog($organization);
        $staff = Staff::factory()->for($organization)->create();
        $foreignStaff = Staff::factory()->for($otherOrganization)->create();
        $inactiveStaff = Staff::factory()->inactive()->for($organization)->create();

        $this->actingAs($admin)->postJson('/api/v1/bookings', $this->payload(
            $customer,
            $eventType,
            $service,
            $package,
            staffIds: [$staff->id, $staff->id],
        ))->assertUnprocessable()
            ->assertJsonValidationErrors('booking_services.0.staff_ids');

        $this->actingAs($admin)->postJson('/api/v1/bookings', $this->payload(
            $customer,
            $eventType,
            $service,
            $package,
            staffIds: [$foreignStaff->id],
        ))->assertUnprocessable()
            ->assertJsonValidationErrors('booking_services');

        $this->actingAs($admin)->postJson('/api/v1/bookings', $this->payload(
            $customer,
            $eventType,
            $service,
            $package,
            staffIds: [$inactiveStaff->id],
        ))->assertUnprocessable()
            ->assertJsonValidationErrors('booking_services.0.staff_ids');

        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('booking_service_staff_assignments', 0);
    }

    public function test_database_prevents_duplicate_assignment_rows(): void
    {
        [$admin, $organization] = $this->admin();
        [$customer, $eventType, $service, $package] = $this->catalog($organization);
        $staff = Staff::factory()->for($organization)->create();
        $created = $this->actingAs($admin)->postJson('/api/v1/bookings', $this->payload(
            $customer,
            $eventType,
            $service,
            $package,
            staffIds: [$staff->id],
        ))->assertCreated();

        $this->expectException(QueryException::class);
        DB::table('booking_service_staff_assignments')->insert([
            'organization_id' => $organization->id,
            'booking_service_id' => $created->json('booking_services.0.id'),
            'staff_id' => $staff->id,
            'assigned_by' => $admin->id,
            'assigned_at' => now('UTC'),
        ]);
    }

    public function test_overlap_is_rejected_but_back_to_back_assignment_is_allowed(): void
    {
        [$admin, $organization] = $this->admin();
        [$customer, $eventType, $service, $package] = $this->catalog($organization);
        $staff = Staff::factory()->for($organization)->create();

        $this->actingAs($admin)->postJson('/api/v1/bookings', $this->payload(
            $customer,
            $eventType,
            $service,
            $package,
            startTime: '10:00',
            duration: 120,
            staffIds: [$staff->id],
        ))->assertCreated();

        $this->actingAs($admin)->postJson('/api/v1/bookings', $this->payload(
            $customer,
            $eventType,
            $service,
            $package,
            startTime: '11:00',
            duration: 120,
            staffIds: [$staff->id],
        ))->assertUnprocessable()
            ->assertJsonValidationErrors('booking_services.0.staff_ids');

        $this->actingAs($admin)->postJson('/api/v1/bookings', $this->payload(
            $customer,
            $eventType,
            $service,
            $package,
            startTime: '12:00',
            duration: 120,
            staffIds: [$staff->id],
        ))->assertCreated();
    }

    public function test_cancelled_and_completed_bookings_do_not_block_staff(): void
    {
        [$admin, $organization] = $this->admin();
        [$customer, $eventType, $service, $package] = $this->catalog($organization);

        foreach ([BookingStatus::Cancelled, BookingStatus::Completed] as $status) {
            $staff = Staff::factory()->for($organization)->create();
            $created = $this->actingAs($admin)->postJson('/api/v1/bookings', $this->payload(
                $customer,
                $eventType,
                $service,
                $package,
                staffIds: [$staff->id],
            ))->assertCreated();

            $audit = $status === BookingStatus::Cancelled
                ? ['cancelled_at' => now('UTC'), 'cancelled_by' => $admin->id]
                : ['completed_at' => now('UTC'), 'completed_by' => $admin->id];
            Booking::query()->whereKey($created->json('id'))->update([
                'status' => $status,
                ...$audit,
            ]);

            $this->actingAs($admin)->postJson('/api/v1/bookings', $this->payload(
                $customer,
                $eventType,
                $service,
                $package,
                staffIds: [$staff->id],
            ))->assertCreated();
        }
    }

    public function test_pending_quoted_and_confirmed_bookings_block_staff(): void
    {
        [$admin, $organization] = $this->admin();
        [$customer, $eventType, $service, $package] = $this->catalog($organization);

        foreach ([BookingStatus::Pending, BookingStatus::Quoted, BookingStatus::Confirmed] as $status) {
            $staff = Staff::factory()->for($organization)->create();
            $created = $this->actingAs($admin)->postJson('/api/v1/bookings', $this->payload(
                $customer,
                $eventType,
                $service,
                $package,
                staffIds: [$staff->id],
            ))->assertCreated();
            Booking::query()->whereKey($created->json('id'))->update(['status' => $status]);

            $this->actingAs($admin)->postJson('/api/v1/bookings', $this->payload(
                $customer,
                $eventType,
                $service,
                $package,
                startTime: '19:00',
                staffIds: [$staff->id],
            ))->assertUnprocessable()
                ->assertJsonValidationErrors('booking_services.0.staff_ids');
        }
    }

    public function test_update_self_excludes_retained_assignment_and_checks_staff_changes(): void
    {
        [$admin, $organization] = $this->admin();
        [$customer, $eventType, $service, $package] = $this->catalog($organization);
        $retainedStaff = Staff::factory()->for($organization)->create();
        $busyStaff = Staff::factory()->for($organization)->create();
        $created = $this->actingAs($admin)->postJson('/api/v1/bookings', $this->payload(
            $customer,
            $eventType,
            $service,
            $package,
            staffIds: [$retainedStaff->id],
        ))->assertCreated();
        $payload = $this->payload($customer, $eventType, $service, $package, staffIds: [$retainedStaff->id]);
        $payload['booking_services'][0]['id'] = $created->json('booking_services.0.id');

        $this->actingAs($admin)->putJson("/api/v1/bookings/{$created->json('id')}", $payload)
            ->assertOk()
            ->assertJsonPath('booking_services.0.staff.0.id', $retainedStaff->id);

        $this->actingAs($admin)->postJson('/api/v1/bookings', $this->payload(
            $customer,
            $eventType,
            $service,
            $package,
            startTime: '19:00',
            staffIds: [$busyStaff->id],
        ))->assertCreated();
        $payload['event_name'] = 'Must Roll Back';
        $payload['start_time'] = '19:30';
        $payload['booking_services'][0]['staff_ids'] = [$busyStaff->id];

        $this->actingAs($admin)->putJson("/api/v1/bookings/{$created->json('id')}", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('booking_services.0.staff_ids');
        $this->assertDatabaseHas('bookings', [
            'id' => $created->json('id'),
            'event_name' => 'Operations Test',
        ]);
        $this->assertDatabaseHas('booking_service_staff_assignments', [
            'booking_service_id' => $created->json('booking_services.0.id'),
            'staff_id' => $retainedStaff->id,
        ]);
    }

    public function test_overnight_assignment_conflicts_on_the_following_event_date(): void
    {
        [$admin, $organization] = $this->admin();
        [$customer, $eventType, $service, $package] = $this->catalog($organization);
        $staff = Staff::factory()->for($organization)->create();

        $this->actingAs($admin)->postJson('/api/v1/bookings', $this->payload(
            $customer,
            $eventType,
            $service,
            $package,
            eventDate: '2027-06-15',
            startTime: '23:00',
            duration: 180,
            staffIds: [$staff->id],
        ))->assertCreated();

        $this->actingAs($admin)->postJson('/api/v1/bookings', $this->payload(
            $customer,
            $eventType,
            $service,
            $package,
            eventDate: '2027-06-16',
            startTime: '01:00',
            duration: 120,
            staffIds: [$staff->id],
        ))->assertUnprocessable()
            ->assertJsonValidationErrors('booking_services.0.staff_ids');
    }

    public function test_staff_availability_returns_only_active_tenant_staff_and_self_excludes_a_line(): void
    {
        [$admin, $organization] = $this->admin();
        [$otherAdmin, $otherOrganization] = $this->admin();
        [$customer, $eventType, $service, $package] = $this->catalog($organization);
        $busy = Staff::factory()->for($organization)->create(['name' => 'Busy Staff']);
        $free = Staff::factory()->for($organization)->create(['name' => 'Free Staff']);
        Staff::factory()->inactive()->for($organization)->create(['name' => 'Inactive Staff']);
        Staff::factory()->for($otherOrganization)->create(['name' => 'Foreign Staff']);
        $created = $this->actingAs($admin)->postJson('/api/v1/bookings', $this->payload(
            $customer,
            $eventType,
            $service,
            $package,
            staffIds: [$busy->id],
        ))->assertCreated();
        $input = [
            'event_date' => '2027-06-15',
            'start_time' => '19:00',
            'duration_minutes' => 60,
        ];

        $this->actingAs($admin)->postJson('/api/v1/bookings/staff-availability', $input)
            ->assertOk()
            ->assertJsonCount(2, 'staff')
            ->assertJsonFragment(['id' => $busy->id, 'name' => 'Busy Staff', 'available' => false])
            ->assertJsonFragment(['id' => $free->id, 'name' => 'Free Staff', 'available' => true])
            ->assertJsonMissing(['name' => 'Inactive Staff'])
            ->assertJsonMissing(['name' => 'Foreign Staff']);

        $this->actingAs($admin)->postJson('/api/v1/bookings/staff-availability', [
            ...$input,
            'booking_service_id' => $created->json('booking_services.0.id'),
        ])->assertOk()
            ->assertJsonFragment(['id' => $busy->id, 'available' => true]);

        [$foreignCustomer, $foreignEventType, $foreignService, $foreignPackage] = $this->catalog($otherOrganization);
        $foreignBooking = Booking::factory()->create([
            'organization_id' => $otherOrganization->id,
            'customer_id' => $foreignCustomer->id,
            'event_type_id' => $foreignEventType->id,
            'created_by' => $otherAdmin->id,
        ]);
        $foreignLine = BookingService::factory()
            ->forBooking($foreignBooking)
            ->forPackage($foreignPackage, $foreignService)
            ->create()
            ->id;
        $this->actingAs($admin)->postJson('/api/v1/bookings/staff-availability', [
            ...$input,
            'booking_service_id' => $foreignLine,
        ])->assertNotFound();
    }

    /** @return array{User, Organization} */
    private function admin(): array
    {
        $organization = Organization::factory()->withBusinessSettings()->create();

        return [User::factory()->for($organization)->create(), $organization];
    }

    /** @return array{Customer, EventType, Service, Package} */
    private function catalog(Organization $organization): array
    {
        $customer = Customer::factory()->for($organization)->create();
        $eventType = EventType::factory()->for($organization)->create();
        $service = Service::factory()->for($organization)->create(['total_units' => 20]);
        $package = Package::factory()->forService($service)->create();
        foreach ([120, 180] as $duration) {
            ServiceRate::factory()->forCombination($eventType, $service, $package)->create([
                'duration_minutes' => $duration,
            ]);
        }

        return [$customer, $eventType, $service, $package];
    }

    /** @return array<string, mixed> */
    private function payload(
        Customer $customer,
        EventType $eventType,
        Service $service,
        Package $package,
        string $eventDate = '2027-06-15',
        string $startTime = '18:00',
        int $duration = 180,
        array $staffIds = [],
    ): array {
        return [
            'customer_id' => $customer->id,
            'event_type_id' => $eventType->id,
            'event_name' => 'Operations Test',
            'event_date' => $eventDate,
            'start_time' => $startTime,
            'venue_name' => 'Grand Hall',
            'contact_person' => 'Alex Cruz',
            'contact_number' => '09170000000',
            'booking_services' => [[
                'service_id' => $service->id,
                'package_id' => $package->id,
                'duration_minutes' => $duration,
                'quantity' => 1,
                'staff_ids' => $staffIds,
            ]],
        ];
    }
}
