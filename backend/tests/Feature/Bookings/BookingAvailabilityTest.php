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
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_is_advisory_and_does_not_persist_or_allocate_a_number(): void
    {
        [$admin, $organization] = $this->admin();
        [$service, $package] = $this->catalog($organization, 2);

        $this->actingAs($admin)->postJson('/api/v1/bookings/availability', $this->previewPayload($service, $package, '18:00', 180, 2))
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('services.0.service_id', $service->id)
            ->assertJsonPath('services.0.total_units', 2)
            ->assertJsonPath('services.0.requested_quantity', 2)
            ->assertJsonPath('services.0.required_quantity', 2);

        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('booking_services', 0);
        $this->assertDatabaseCount('document_sequences', 0);
    }

    public function test_preview_rejects_foreign_and_mismatched_package_relationships(): void
    {
        [$admin, $organization] = $this->admin();
        [, $otherOrganization] = $this->admin();
        [$service, $package] = $this->catalog($organization);
        [$foreignService, $foreignPackage] = $this->catalog($otherOrganization);
        $otherService = Service::factory()->for($organization)->create();
        $otherPackage = Package::factory()->forService($otherService)->create();

        foreach ([
            [$foreignService, $foreignPackage, 'service_id'],
            [$service, $foreignPackage, 'package_id'],
            [$service, $otherPackage, 'package_id'],
        ] as [$selectedService, $selectedPackage, $field]) {
            $this->actingAs($admin)->postJson(
                '/api/v1/bookings/availability',
                $this->previewPayload($selectedService, $selectedPackage),
            )->assertUnprocessable()
                ->assertJsonValidationErrors("booking_services.0.{$field}");
        }
    }

    public function test_overlap_within_capacity_is_available_but_exceeding_capacity_is_not(): void
    {
        [$admin, $organization] = $this->admin();
        [$service, $package] = $this->catalog($organization, 3);
        $this->reservation($admin, $organization, $service, $package, '2027-06-15 18:00:00', '2027-06-15 21:00:00', 2);

        $this->actingAs($admin)->postJson('/api/v1/bookings/availability', $this->previewPayload($service, $package, '19:00', 180, 1))
            ->assertOk()->assertJsonPath('available', true)->assertJsonPath('services.0.required_quantity', 3);
        $this->actingAs($admin)->postJson('/api/v1/bookings/availability', $this->previewPayload($service, $package, '19:00', 180, 2))
            ->assertOk()->assertJsonPath('available', false)
            ->assertJsonPath('services.0.required_quantity', 4)
            ->assertJsonPath('services.0.over_capacity_by', 1);
    }

    public function test_non_overlapping_and_back_to_back_intervals_are_available(): void
    {
        [$admin, $organization] = $this->admin();
        [$service, $package] = $this->catalog($organization, 2);
        $this->reservation($admin, $organization, $service, $package, '2027-06-15 18:00:00', '2027-06-15 21:00:00', 2);

        $this->actingAs($admin)->postJson('/api/v1/bookings/availability', $this->previewPayload($service, $package, '21:00', 180, 2))
            ->assertOk()->assertJsonPath('available', true);
        $this->actingAs($admin)->postJson('/api/v1/bookings/availability', $this->previewPayload($service, $package, '08:00', 60, 2))
            ->assertOk()->assertJsonPath('available', true);
    }

    public function test_quantity_equal_to_capacity_is_valid_but_greater_is_rejected(): void
    {
        [$admin, $organization] = $this->admin();
        [$service, $package] = $this->catalog($organization, 2);

        $this->actingAs($admin)->postJson('/api/v1/bookings/availability', $this->previewPayload($service, $package, quantity: 2))
            ->assertOk()->assertJsonPath('available', true);
        $this->actingAs($admin)->postJson('/api/v1/bookings/availability', $this->previewPayload($service, $package, quantity: 3))
            ->assertOk()->assertJsonPath('available', false);
    }

    public function test_overlapping_candidate_lines_for_same_service_are_combined(): void
    {
        [$admin, $organization] = $this->admin();
        [$service, $package] = $this->catalog($organization, 2);
        $payload = $this->previewPayload($service, $package, '18:00', 180, 1);
        $payload['booking_services'][] = [
            'service_id' => $service->id,
            'package_id' => $package->id,
            'start_time' => '19:00',
            'duration_minutes' => 180,
            'quantity' => 2,
        ];

        $this->actingAs($admin)->postJson('/api/v1/bookings/availability', $payload)
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('services.0.requested_quantity', 3)
            ->assertJsonPath('services.0.required_quantity', 3);
    }

    public function test_non_overlapping_candidate_lines_for_same_service_are_valid(): void
    {
        [$admin, $organization] = $this->admin();
        [$service, $package] = $this->catalog($organization, 2);
        $payload = $this->previewPayload($service, $package, '18:00', 180, 2);
        $payload['booking_services'][] = [
            'service_id' => $service->id,
            'package_id' => $package->id,
            'start_time' => '21:00',
            'duration_minutes' => 180,
            'quantity' => 2,
        ];

        $this->actingAs($admin)->postJson('/api/v1/bookings/availability', $payload)
            ->assertOk()->assertJsonPath('available', true)
            ->assertJsonPath('services.0.requested_quantity', 2);
    }

    public function test_existing_and_multiple_candidate_lines_are_combined(): void
    {
        [$admin, $organization] = $this->admin();
        [$service, $package] = $this->catalog($organization, 3);
        $this->reservation($admin, $organization, $service, $package, '2027-06-15 18:00:00', '2027-06-15 21:00:00', 1);
        $payload = $this->previewPayload($service, $package, '18:00', 180, 1);
        $payload['booking_services'][] = [
            'service_id' => $service->id, 'package_id' => $package->id,
            'start_time' => '19:00', 'duration_minutes' => 180, 'quantity' => 2,
        ];

        $this->actingAs($admin)->postJson('/api/v1/bookings/availability', $payload)
            ->assertOk()->assertJsonPath('available', false)
            ->assertJsonPath('services.0.required_quantity', 4);
    }

    public function test_only_capacity_reserving_booking_statuses_are_counted(): void
    {
        [$admin, $organization] = $this->admin();

        foreach ([
            BookingStatus::Pending->value => false,
            BookingStatus::Quoted->value => false,
            BookingStatus::Confirmed->value => false,
            BookingStatus::Completed->value => true,
            BookingStatus::Cancelled->value => true,
        ] as $status => $available) {
            [$service, $package] = $this->catalog($organization, 1, "{$status} Booth");
            $this->reservation(
                $admin,
                $organization,
                $service,
                $package,
                '2027-06-15 18:00:00',
                '2027-06-15 21:00:00',
                1,
                BookingStatus::from($status),
            );

            $this->actingAs($admin)->postJson('/api/v1/bookings/availability', $this->previewPayload($service, $package))
                ->assertOk()->assertJsonPath('available', $available);
        }
    }

    public function test_different_services_do_not_interfere_and_cross_midnight_overlap_is_detected(): void
    {
        [$admin, $organization] = $this->admin();
        [$service, $package] = $this->catalog($organization, 1);
        [$otherService, $otherPackage] = $this->catalog($organization, 1, 'Other Booth');
        $this->reservation($admin, $organization, $service, $package, '2027-06-15 23:00:00', '2027-06-16 03:00:00', 1);

        $this->actingAs($admin)->postJson('/api/v1/bookings/availability', [
            'event_date' => '2027-06-15',
            'booking_services' => [[
                'service_id' => $otherService->id, 'package_id' => $otherPackage->id,
                'start_time' => '23:30', 'duration_minutes' => 120, 'quantity' => 1,
            ]],
        ])->assertOk()->assertJsonPath('available', true);

        $this->actingAs($admin)->postJson('/api/v1/bookings/availability', [
            'event_date' => '2027-06-16',
            'booking_services' => [[
                'service_id' => $service->id, 'package_id' => $package->id,
                'start_time' => '02:00', 'duration_minutes' => 120, 'quantity' => 1,
            ]],
        ])->assertOk()->assertJsonPath('available', false);
    }

    public function test_create_rechecks_capacity_and_rejects_an_unavailable_preview_candidate(): void
    {
        [$admin, $organization] = $this->admin();
        [$service, $package] = $this->catalog($organization, 2);
        $customer = Customer::factory()->for($organization)->create();
        $eventType = EventType::factory()->for($organization)->create();
        ServiceRate::factory()->forCombination($eventType, $service, $package)->create(['duration_minutes' => 180]);

        $this->actingAs($admin)->postJson('/api/v1/bookings/availability', $this->previewPayload($service, $package, quantity: 2))
            ->assertOk()->assertJsonPath('available', true);
        $this->reservation($admin, $organization, $service, $package, '2027-06-15 18:00:00', '2027-06-15 21:00:00', 1);

        $this->actingAs($admin)->postJson('/api/v1/bookings', $this->bookingPayload($customer, $eventType, $service, $package, 2))
            ->assertUnprocessable()->assertJsonValidationErrors('booking_services');
    }

    public function test_update_excludes_own_capacity_but_detects_other_booking_and_rolls_back(): void
    {
        [$admin, $organization] = $this->admin();
        [$service, $package] = $this->catalog($organization, 2);
        $customer = Customer::factory()->for($organization)->create();
        $eventType = EventType::factory()->for($organization)->create();
        ServiceRate::factory()->forCombination($eventType, $service, $package)->create(['duration_minutes' => 180]);
        $created = $this->actingAs($admin)->postJson('/api/v1/bookings', $this->bookingPayload($customer, $eventType, $service, $package, 2))
            ->assertCreated();
        $bookingId = $created->json('id');
        $payload = $this->bookingPayload($customer, $eventType, $service, $package, 2);
        $payload['booking_services'][0]['id'] = $created->json('booking_services.0.id');

        $this->actingAs($admin)->putJson("/api/v1/bookings/{$bookingId}", $payload)->assertOk();

        $this->reservation($admin, $organization, $service, $package, '2027-06-15 21:00:00', '2027-06-16 00:00:00', 1);
        $payload['event_name'] = 'Should Not Persist';
        $payload['booking_services'][0]['start_time'] = '21:00';
        $this->actingAs($admin)->putJson("/api/v1/bookings/{$bookingId}", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('booking_services');
        $this->assertDatabaseHas('bookings', ['id' => $bookingId, 'event_name' => 'Availability Test']);
    }

    public function test_edit_preview_excludes_own_booking_and_rejects_a_foreign_booking_id(): void
    {
        [$admin, $organization] = $this->admin();
        [$otherAdmin, $otherOrganization] = $this->admin();
        [$service, $package] = $this->catalog($organization, 1);
        $booking = $this->reservation(
            $admin,
            $organization,
            $service,
            $package,
            '2027-06-15 18:00:00',
            '2027-06-15 21:00:00',
            1,
        );
        $payload = $this->previewPayload($service, $package);
        $payload['booking_id'] = $booking->id;

        $this->actingAs($admin)->postJson('/api/v1/bookings/availability', $payload)
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('services.0.required_quantity', 1);

        $foreignCustomer = Customer::factory()->for($otherOrganization)->create();
        $foreignEventType = EventType::factory()->for($otherOrganization)->create();
        $foreignBooking = Booking::factory()->create([
            'organization_id' => $otherOrganization->id,
            'created_by' => $otherAdmin->id,
            'customer_id' => $foreignCustomer->id,
            'event_type_id' => $foreignEventType->id,
            'customer_name' => $foreignCustomer->name,
            'event_type_name' => $foreignEventType->name,
        ]);
        $payload['booking_id'] = $foreignBooking->id;

        $this->actingAs($admin)->postJson('/api/v1/bookings/availability', $payload)
            ->assertNotFound();
    }

    public function test_cancellation_preserves_lines_frees_capacity_and_is_rejected_when_repeated_or_foreign(): void
    {
        [$admin, $organization] = $this->admin();
        [$otherAdmin, $otherOrganization] = $this->admin();
        [$service, $package] = $this->catalog($organization, 1);
        $customer = Customer::factory()->for($organization)->create();
        $eventType = EventType::factory()->for($organization)->create();
        ServiceRate::factory()->forCombination($eventType, $service, $package)->create(['duration_minutes' => 180]);
        $created = $this->actingAs($admin)->postJson('/api/v1/bookings', $this->bookingPayload($customer, $eventType, $service, $package))
            ->assertCreated();
        $bookingId = $created->json('id');
        $lineId = $created->json('booking_services.0.id');

        $this->actingAs($admin)->postJson("/api/v1/bookings/{$bookingId}/cancel", ['reason' => 'Client request'])
            ->assertOk()->assertJsonPath('status', 'CANCELLED')
            ->assertJsonPath('cancellation_reason', 'Client request');
        $this->assertDatabaseHas('booking_services', ['id' => $lineId, 'booking_id' => $bookingId]);
        $this->actingAs($admin)->postJson('/api/v1/bookings/availability', $this->previewPayload($service, $package))
            ->assertOk()->assertJsonPath('available', true);
        $this->actingAs($admin)->postJson("/api/v1/bookings/{$bookingId}/cancel")
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $foreignCustomer = Customer::factory()->for($otherOrganization)->create();
        $foreignEventType = EventType::factory()->for($otherOrganization)->create();
        $foreignBooking = Booking::factory()->create([
            'organization_id' => $otherOrganization->id,
            'created_by' => $otherAdmin->id,
            'customer_id' => $foreignCustomer->id,
            'event_type_id' => $foreignEventType->id,
            'customer_name' => $foreignCustomer->name,
            'event_type_name' => $foreignEventType->name,
        ]);
        $this->actingAs($admin)->postJson("/api/v1/bookings/{$foreignBooking->id}/cancel", ['reason' => 'Foreign'])
            ->assertNotFound();
    }

    /** @return array{User, Organization} */
    private function admin(): array
    {
        $organization = Organization::factory()->withBusinessSettings()->create();

        return [User::factory()->for($organization)->create(), $organization];
    }

    /** @return array{Service, Package} */
    private function catalog(Organization $organization, int $totalUnits = 3, string $name = 'Mirror Booth'): array
    {
        $service = Service::factory()->for($organization)->create([
            'name' => $name, 'total_units' => $totalUnits,
        ]);
        $package = Package::query()
            ->where('organization_id', $organization->id)
            ->where('name', 'Premium')
            ->first()
            ?? Package::factory()->for($organization)->create(['name' => 'Premium']);
        $package->services()->syncWithoutDetaching([
            $service->id => ['organization_id' => $organization->id],
        ]);

        return [$service, $package];
    }

    private function reservation(
        User $admin,
        Organization $organization,
        Service $service,
        Package $package,
        string $start,
        string $end,
        int $quantity,
        BookingStatus $status = BookingStatus::Pending,
    ): Booking {
        $customer = Customer::factory()->for($organization)->create();
        $eventType = EventType::factory()->for($organization)->create();
        $attributes = [
            'organization_id' => $organization->id,
            'created_by' => $admin->id,
            'customer_id' => $customer->id,
            'event_type_id' => $eventType->id,
            'customer_name' => $customer->name,
            'event_type_name' => $eventType->name,
            'status' => $status,
        ];

        if ($status === BookingStatus::Completed) {
            $attributes += ['completed_at' => '2027-01-01 00:00:00', 'completed_by' => $admin->id];
        }
        if ($status === BookingStatus::Cancelled) {
            $attributes += ['cancelled_at' => '2027-01-01 00:00:00', 'cancelled_by' => $admin->id];
        }

        $booking = Booking::factory()->create($attributes);
        BookingService::factory()->forBooking($booking)->forPackage($package, $service)->create([
            'start_at' => $start,
            'end_at' => $end,
            'duration_minutes' => (strtotime($end) - strtotime($start)) / 60,
            'quantity' => $quantity,
            'line_total' => number_format(7500 * $quantity, 2, '.', ''),
        ]);

        return $booking;
    }

    /** @return array<string, mixed> */
    private function previewPayload(
        Service $service,
        Package $package,
        string $start = '18:00',
        int $duration = 180,
        int $quantity = 1,
    ): array {
        return [
            'event_date' => '2027-06-15',
            'booking_services' => [[
                'service_id' => $service->id,
                'package_id' => $package->id,
                'start_time' => $start,
                'duration_minutes' => $duration,
                'quantity' => $quantity,
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function bookingPayload(
        Customer $customer,
        EventType $eventType,
        Service $service,
        Package $package,
        int $quantity = 1,
    ): array {
        return [
            'customer_id' => $customer->id,
            'event_type_id' => $eventType->id,
            'event_name' => 'Availability Test',
            'event_date' => '2027-06-15',
            'venue_name' => 'Grand Hall',
            'contact_person' => 'Alex Cruz',
            'contact_number' => '09170000000',
            'booking_services' => [[
                'service_id' => $service->id,
                'package_id' => $package->id,
                'start_time' => '18:00',
                'duration_minutes' => 180,
                'quantity' => $quantity,
            ]],
        ];
    }
}
