<?php

namespace Tests\Feature\Bookings;

use App\Actions\Bookings\CreateBooking;
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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_schema_uses_manila_native_booking_schedules(): void
    {
        $this->assertSame('Asia/Manila', config('app.timezone'));
        $this->assertTrue(Schema::hasColumn('booking_services', 'start_at'));
        $this->assertTrue(Schema::hasColumn('booking_services', 'end_at'));
        $this->assertFalse(Schema::hasColumn('booking_services', 'start_at_utc'));
        $this->assertFalse(Schema::hasColumn('booking_services', 'end_at_utc'));
        $this->assertFalse(Schema::hasColumn('bookings', 'timezone'));
        $this->assertFalse(Schema::hasColumn('business_settings', 'timezone'));
    }

    public function test_booking_api_requires_authentication_and_has_no_delete_endpoint(): void
    {
        $this->getJson('/api/bookings')->assertUnauthorized();
        $this->postJson('/api/bookings', [])->assertUnauthorized();
        $this->postJson('/api/bookings/availability', [])->assertUnauthorized();
        $this->getJson('/api/bookings/1')->assertUnauthorized();
        $this->putJson('/api/bookings/1', [])->assertUnauthorized();
        $this->postJson('/api/bookings/1/cancel')->assertUnauthorized();
        $this->deleteJson('/api/bookings/1')->assertStatus(405);
    }

    public function test_tenant_creates_pending_booking_with_number_snapshots_exact_prices_and_manila_schedule(): void
    {
        [$admin, $organization] = $this->admin();
        [$customer, $eventType, $service, $package] = $this->catalog($organization);
        ServiceRate::factory()->forCombination($eventType, $package)->create([
            'duration_minutes' => 180,
            'unit_rate' => '12345.67',
        ]);
        [, $foreignOrganization] = $this->admin();

        $response = $this->actingAs($admin)->postJson('/api/bookings', [
            ...$this->payload($customer, $eventType, $service, $package),
            'organization_id' => $foreignOrganization->id,
            'status' => 'CONFIRMED',
            'unit_rate' => '0.01',
        ]);

        // Booking lifecycle status is controlled by explicit domain actions.
        $response->assertUnprocessable()->assertJsonValidationErrors('status');

        $response = $this->actingAs($admin)->postJson('/api/bookings', [
            ...$this->payload($customer, $eventType, $service, $package),
            'organization_id' => $foreignOrganization->id,
            'unit_rate' => '0.01',
        ])->assertCreated()
            ->assertJsonPath('booking_number', 'BK-2026-000001')
            ->assertJsonPath('status', 'PENDING')
            ->assertJsonPath('customer_snapshot.name', 'Snapshot Customer')
            ->assertJsonPath('event_type_snapshot.name', 'Wedding')
            ->assertJsonPath('booking_services.0.service.name', 'Mirror Booth')
            ->assertJsonPath('booking_services.0.package.name', 'Premium')
            ->assertJsonPath('booking_services.0.start_at', '2027-06-15 18:00')
            ->assertJsonPath('booking_services.0.end_at', '2027-06-15 21:00')
            ->assertJsonPath('booking_services.0.unit_rate', '12345.67')
            ->assertJsonPath('booking_services.0.line_total', '37037.01')
            ->assertJsonMissingPath('organization_id');

        $this->assertDatabaseHas('bookings', [
            'id' => $response->json('id'),
            'organization_id' => $organization->id,
            'created_by' => $admin->id,
            'customer_name' => 'Snapshot Customer',
            'event_type_name' => 'Wedding',
            'status' => 'PENDING',
        ]);
        $this->assertDatabaseHas('booking_services', [
            'booking_id' => $response->json('id'),
            'service_name' => 'Mirror Booth',
            'package_name' => 'Premium',
            'unit_rate' => '12345.67',
            'line_total' => '37037.01',
            'quantity' => 3,
        ]);
    }

    public function test_multiple_booking_services_are_required_supported_and_price_is_resolved_per_line(): void
    {
        [$admin, $organization] = $this->admin();
        [$customer, $eventType, $service, $package] = $this->catalog($organization);
        $otherService = Service::factory()->for($organization)->create(['name' => '360 Booth', 'total_units' => 5]);
        $otherPackage = Package::factory()->forService($otherService)->create(['name' => 'Deluxe']);
        ServiceRate::factory()->forCombination($eventType, $package)->create([
            'duration_minutes' => 180, 'unit_rate' => '7500.00',
        ]);
        ServiceRate::factory()->forCombination($eventType, $otherPackage)->create([
            'duration_minutes' => 120, 'unit_rate' => '4000.00',
        ]);

        $this->actingAs($admin)->postJson('/api/bookings', [
            ...$this->payload($customer, $eventType, $service, $package),
            'booking_services' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('booking_services');

        $payload = $this->payload($customer, $eventType, $service, $package);
        $payload['booking_services'][] = [
            'service_id' => $otherService->id,
            'package_id' => $otherPackage->id,
            'start_time' => '20:00',
            'duration_minutes' => 120,
            'quantity' => 2,
        ];

        $this->actingAs($admin)->postJson('/api/bookings', $payload)
            ->assertCreated()
            ->assertJsonCount(2, 'booking_services')
            ->assertJsonPath('booking_services.1.unit_rate', '4000.00')
            ->assertJsonPath('booking_services.1.line_total', '8000.00');
    }

    public function test_foreign_or_inactive_customer_and_event_type_are_rejected(): void
    {
        [$admin, $organization] = $this->admin();
        [$otherAdmin, $otherOrganization] = $this->admin();
        [$customer, $eventType, $service, $package] = $this->catalog($organization);
        [$foreignCustomer, $foreignEventType] = $this->catalog($otherOrganization);
        ServiceRate::factory()->forCombination($eventType, $package)->create(['duration_minutes' => 180]);

        foreach ([
            ['customer_id', $foreignCustomer->id],
            ['event_type_id', $foreignEventType->id],
        ] as [$field, $value]) {
            $this->actingAs($admin)->postJson('/api/bookings', [
                ...$this->payload($customer, $eventType, $service, $package),
                $field => $value,
            ])->assertUnprocessable()->assertJsonValidationErrors($field);
        }

        $customer->update(['is_active' => false]);
        $this->actingAs($admin)->postJson('/api/bookings', $this->payload($customer, $eventType, $service, $package))
            ->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        $customer->update(['is_active' => true]);
        $eventType->update(['is_active' => false]);
        $this->actingAs($admin)->postJson('/api/bookings', $this->payload($customer, $eventType, $service, $package))
            ->assertUnprocessable()->assertJsonValidationErrors('event_type_id');
    }

    public function test_invalid_foreign_inactive_or_mismatched_catalog_configuration_is_rejected(): void
    {
        [$admin, $organization] = $this->admin();
        [, $otherOrganization] = $this->admin();
        [$customer, $eventType, $service, $package] = $this->catalog($organization);
        [, , $foreignService, $foreignPackage] = $this->catalog($otherOrganization);
        $otherService = Service::factory()->for($organization)->create();
        $otherPackage = Package::factory()->forService($otherService)->create();
        ServiceRate::factory()->forCombination($eventType, $package)->create(['duration_minutes' => 180]);

        foreach ([
            [$foreignService->id, $package->id, 'service_id'],
            [$service->id, $foreignPackage->id, 'package_id'],
            [$service->id, $otherPackage->id, 'package_id'],
        ] as [$serviceId, $packageId, $errorField]) {
            $payload = $this->payload($customer, $eventType, $service, $package);
            $payload['booking_services'][0]['service_id'] = $serviceId;
            $payload['booking_services'][0]['package_id'] = $packageId;
            $response = $this->actingAs($admin)->postJson('/api/bookings', $payload)
                ->assertUnprocessable();
            $response->assertJsonValidationErrors(
                $errorField === 'service_id' ? 'booking_services' : "booking_services.0.{$errorField}",
            );
        }

        $service->update(['is_active' => false]);
        $this->actingAs($admin)->postJson('/api/bookings', $this->payload($customer, $eventType, $service, $package))
            ->assertUnprocessable()->assertJsonValidationErrors('booking_services.0.service_id');
        $service->update(['is_active' => true]);
        $package->update(['is_active' => false]);
        $this->actingAs($admin)->postJson('/api/bookings', $this->payload($customer, $eventType, $service, $package))
            ->assertUnprocessable()->assertJsonValidationErrors('booking_services.0.package_id');
    }

    public function test_missing_or_inactive_exact_rate_is_rejected_and_creation_is_atomic(): void
    {
        [$admin, $organization] = $this->admin();
        [$customer, $eventType, $service, $package] = $this->catalog($organization);

        $this->actingAs($admin)->postJson('/api/bookings', $this->payload($customer, $eventType, $service, $package))
            ->assertUnprocessable()->assertJsonValidationErrors('booking_services.0.duration_minutes');
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('document_sequences', 0);

        ServiceRate::factory()->inactive()->forCombination($eventType, $package)->create(['duration_minutes' => 180]);
        $this->actingAs($admin)->postJson('/api/bookings', $this->payload($customer, $eventType, $service, $package))
            ->assertUnprocessable()->assertJsonValidationErrors('booking_services.0.duration_minutes');
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('booking_services', 0);
        $this->assertDatabaseCount('document_sequences', 0);
    }

    public function test_duration_and_quantity_must_be_positive_integers(): void
    {
        [$admin, $organization] = $this->admin();
        [$customer, $eventType, $service, $package] = $this->catalog($organization);

        foreach ([['duration_minutes', 0], ['quantity', 0]] as [$field, $value]) {
            $payload = $this->payload($customer, $eventType, $service, $package);
            $payload['booking_services'][0][$field] = $value;
            $this->actingAs($admin)->postJson('/api/bookings', $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors("booking_services.0.{$field}");
        }
    }

    public function test_failure_after_number_allocation_rolls_back_sequence_and_entire_aggregate(): void
    {
        [$admin, $organization] = $this->admin();
        [$customer, $eventType, $service, $package] = $this->catalog($organization);
        ServiceRate::factory()->forCombination($eventType, $package)->create(['duration_minutes' => 180]);
        Event::listen('eloquent.creating: '.BookingService::class, function (): never {
            throw new RuntimeException('Simulated child persistence failure.');
        });

        try {
            app(CreateBooking::class)->handle(
                $organization,
                $admin,
                $this->payload($customer, $eventType, $service, $package),
            );
            $this->fail('The simulated child persistence failure should have aborted creation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated child persistence failure.', $exception->getMessage());
        }

        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('booking_services', 0);
        $this->assertDatabaseCount('document_sequences', 0);
    }

    public function test_manila_schedule_crossing_midnight_preserves_the_business_wall_clock(): void
    {
        [$admin, $organization] = $this->admin();
        [$customer, $eventType, $service, $package] = $this->catalog($organization);
        ServiceRate::factory()->forCombination($eventType, $package)->create(['duration_minutes' => 180]);
        $payload = $this->payload($customer, $eventType, $service, $package);
        $payload['event_date'] = '2027-12-20';
        $payload['booking_services'][0]['start_time'] = '23:00';

        $this->actingAs($admin)->postJson('/api/bookings', $payload)
            ->assertCreated()
            ->assertJsonPath('booking_services.0.start_at', '2027-12-20 23:00')
            ->assertJsonPath('booking_services.0.end_at', '2027-12-21 02:00');
    }

    public function test_all_snapshots_remain_stable_after_master_data_changes(): void
    {
        [$admin, $organization] = $this->admin();
        [$customer, $eventType, $service, $package] = $this->catalog($organization);
        ServiceRate::factory()->forCombination($eventType, $package)->create(['duration_minutes' => 180]);
        $id = $this->actingAs($admin)->postJson('/api/bookings', $this->payload($customer, $eventType, $service, $package))
            ->assertCreated()->json('id');

        $customer->update(['name' => 'Changed Customer']);
        $eventType->update(['name' => 'Changed Event']);
        $service->update(['name' => 'Changed Service']);
        $package->update(['name' => 'Changed Package']);

        $this->actingAs($admin)->getJson("/api/bookings/{$id}")->assertOk()
            ->assertJsonPath('customer.name', 'Changed Customer')
            ->assertJsonPath('customer_snapshot.name', 'Snapshot Customer')
            ->assertJsonPath('event_type_snapshot.name', 'Wedding')
            ->assertJsonPath('booking_services.0.service.name', 'Mirror Booth')
            ->assertJsonPath('booking_services.0.package.name', 'Premium');
    }

    public function test_pending_booking_update_preserves_line_identity_refreshes_snapshots_and_reprices(): void
    {
        [$admin, $organization] = $this->admin();
        [$customer, $eventType, $service, $package] = $this->catalog($organization);
        $rate = ServiceRate::factory()->forCombination($eventType, $package)->create([
            'duration_minutes' => 180, 'unit_rate' => '7500.00',
        ]);
        $created = $this->actingAs($admin)->postJson('/api/bookings', $this->payload($customer, $eventType, $service, $package))
            ->assertCreated();
        $bookingId = $created->json('id');
        $lineId = $created->json('booking_services.0.id');
        $customer->update(['name' => 'Updated Customer']);
        $rate->update(['unit_rate' => '8000.00']);
        $payload = $this->payload($customer, $eventType, $service, $package);
        $payload['event_name'] = 'Updated Occasion';
        $payload['booking_services'][0]['id'] = $lineId;
        $payload['booking_services'][0]['quantity'] = 2;

        $this->actingAs($admin)->putJson("/api/bookings/{$bookingId}", $payload)
            ->assertOk()
            ->assertJsonPath('event_name', 'Updated Occasion')
            ->assertJsonPath('customer_snapshot.name', 'Updated Customer')
            ->assertJsonPath('booking_services.0.id', $lineId)
            ->assertJsonPath('booking_services.0.unit_rate', '8000.00')
            ->assertJsonPath('booking_services.0.line_total', '16000.00');
        $this->assertDatabaseCount('booking_services', 1);
    }

    public function test_foreign_and_non_pending_bookings_cannot_be_updated_and_status_is_prohibited(): void
    {
        [$admin, $organization] = $this->admin();
        [$otherAdmin, $otherOrganization] = $this->admin();
        [$customer, $eventType, $service, $package] = $this->catalog($organization);
        [$otherCustomer, $otherEventType, $otherService, $otherPackage] = $this->catalog($otherOrganization);
        ServiceRate::factory()->forCombination($eventType, $package)->create(['duration_minutes' => 180]);
        ServiceRate::factory()->forCombination($otherEventType, $otherPackage)->create(['duration_minutes' => 180]);
        $id = $this->actingAs($admin)->postJson('/api/bookings', $this->payload($customer, $eventType, $service, $package))->json('id');

        $payload = $this->payload($customer, $eventType, $service, $package);
        $payload['status'] = 'CONFIRMED';
        $this->actingAs($admin)->putJson("/api/bookings/{$id}", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        Booking::query()->whereKey($id)->update(['status' => BookingStatus::Quoted]);
        $this->actingAs($admin)->putJson("/api/bookings/{$id}", $this->payload($customer, $eventType, $service, $package))
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $foreignBooking = Booking::factory()->create([
            'organization_id' => $otherOrganization->id,
            'created_by' => $otherAdmin->id,
            'customer_id' => $otherCustomer->id,
            'event_type_id' => $otherEventType->id,
            'customer_name' => $otherCustomer->name,
            'event_type_name' => $otherEventType->name,
        ]);
        $this->actingAs($admin)->putJson("/api/bookings/{$foreignBooking->id}", $this->payload($customer, $eventType, $service, $package))
            ->assertNotFound();
    }

    public function test_failed_update_rolls_back_and_preserves_original_aggregate(): void
    {
        [$admin, $organization] = $this->admin();
        [$customer, $eventType, $service, $package] = $this->catalog($organization);
        ServiceRate::factory()->forCombination($eventType, $package)->create(['duration_minutes' => 180]);
        $created = $this->actingAs($admin)->postJson('/api/bookings', $this->payload($customer, $eventType, $service, $package));
        $bookingId = $created->json('id');
        $lineId = $created->json('booking_services.0.id');
        $payload = $this->payload($customer, $eventType, $service, $package);
        $payload['event_name'] = 'Must Roll Back';
        $payload['booking_services'][0]['id'] = 999999;

        $this->actingAs($admin)->putJson("/api/bookings/{$bookingId}", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('booking_services.0.id');
        $this->assertDatabaseHas('bookings', ['id' => $bookingId, 'event_name' => 'Anniversary']);
        $this->assertDatabaseHas('booking_services', ['id' => $lineId, 'booking_id' => $bookingId]);
        $this->assertDatabaseCount('booking_services', 1);
    }

    public function test_list_filters_searches_orders_and_hides_foreign_bookings(): void
    {
        [$admin, $organization] = $this->admin();
        [$otherAdmin, $otherOrganization] = $this->admin();
        [$customer, $eventType] = $this->catalog($organization);
        [$foreignCustomer, $foreignEventType] = $this->catalog($otherOrganization);
        $first = Booking::factory()->create([
            'organization_id' => $organization->id, 'created_by' => $admin->id,
            'customer_id' => $customer->id, 'event_type_id' => $eventType->id,
            'customer_name' => 'Acme Search', 'event_type_name' => $eventType->name,
            'event_date' => '2027-06-01', 'booking_number' => 'BK-2027-000001',
        ]);
        $second = Booking::factory()->create([
            'organization_id' => $organization->id, 'created_by' => $admin->id,
            'customer_id' => $customer->id, 'event_type_id' => $eventType->id,
            'customer_name' => 'Acme Search', 'event_type_name' => $eventType->name,
            'event_date' => '2027-06-20', 'booking_number' => 'BK-2027-000002',
        ]);
        Booking::factory()->create([
            'organization_id' => $otherOrganization->id,
            'created_by' => $otherAdmin->id,
            'customer_id' => $foreignCustomer->id, 'event_type_id' => $foreignEventType->id,
            'customer_name' => 'Acme Search', 'event_type_name' => $foreignEventType->name,
        ]);

        $this->actingAs($admin)->getJson("/api/bookings?search=Acme&status=PENDING&event_date_from=2027-06-01&event_date_to=2027-06-30&customer_id={$customer->id}&event_type_id={$eventType->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.1.id', $first->id);
        $this->actingAs($admin)->getJson("/api/bookings/{$second->id}")->assertOk();
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
        $customer = Customer::factory()->for($organization)->create([
            'name' => 'Snapshot Customer', 'email' => 'customer@example.test',
            'phone' => '+63 900 000 0000', 'address' => 'Snapshot Address',
        ]);
        $eventType = EventType::factory()->for($organization)->create(['name' => 'Wedding']);
        $service = Service::factory()->for($organization)->create([
            'name' => 'Mirror Booth', 'total_units' => 5,
        ]);
        $package = Package::factory()->forService($service)->create(['name' => 'Premium']);

        return [$customer, $eventType, $service, $package];
    }

    /** @return array<string, mixed> */
    private function payload(Customer $customer, EventType $eventType, Service $service, Package $package): array
    {
        return [
            'customer_id' => $customer->id,
            'event_type_id' => $eventType->id,
            'event_name' => 'Anniversary',
            'event_date' => '2027-06-15',
            'venue_name' => 'Grand Hall',
            'venue_address' => 'Manila',
            'contact_person' => 'Alex Cruz',
            'contact_number' => '+63 900 111 2222',
            'internal_notes' => 'Indoor setup',
            'booking_services' => [[
                'service_id' => $service->id,
                'package_id' => $package->id,
                'start_time' => '18:00',
                'duration_minutes' => 180,
                'quantity' => 3,
                'end_at' => '1900-01-01 00:00',
                'unit_rate' => '0.01',
                'line_total' => '0.01',
            ]],
        ];
    }
}
