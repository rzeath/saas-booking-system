<?php

namespace Tests\Feature\ServiceRates;

use App\Models\EventType;
use App\Models\Organization;
use App\Models\Package;
use App\Models\Service;
use App\Models\ServiceRate;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceRateTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_rate_api_requires_authentication(): void
    {
        $rate = ServiceRate::factory()->create();

        $this->getJson('/api/service-rates')->assertUnauthorized();
        $this->postJson('/api/service-rates', $this->payload($rate->event_type_id, $rate->package_id))->assertUnauthorized();
        $this->getJson("/api/service-rates/{$rate->id}")->assertUnauthorized();
        $this->putJson("/api/service-rates/{$rate->id}", $this->payload($rate->event_type_id, $rate->package_id))->assertUnauthorized();
    }

    public function test_tenant_creates_exact_rate_and_cannot_override_organization(): void
    {
        [$admin, $organization] = $this->admin();
        [, $otherOrganization] = $this->admin();
        [$eventType, $service, $package] = $this->catalog($organization);

        $response = $this->actingAs($admin)->postJson('/api/service-rates', [
            ...$this->payload($eventType->id, $package->id),
            'unit_rate' => '12345678901.25',
            'organization_id' => $otherOrganization->id,
        ])->assertCreated()
            ->assertJsonPath('unit_rate', '12345678901.25')
            ->assertJsonPath('event_type.id', $eventType->id)
            ->assertJsonPath('service.id', $service->id)
            ->assertJsonPath('package.id', $package->id)
            ->assertJsonPath('is_available', true)
            ->assertJsonMissingPath('organization_id');

        $this->assertDatabaseHas('service_rates', [
            'id' => $response->json('id'), 'organization_id' => $organization->id,
            'unit_rate' => '12345678901.25',
        ]);
    }

    public function test_foreign_event_type_and_package_are_rejected(): void
    {
        [$admin, $organization] = $this->admin();
        [, $otherOrganization] = $this->admin();
        [$eventType, , $package] = $this->catalog($organization);
        [$foreignEventType, , $foreignPackage] = $this->catalog($otherOrganization);

        $this->actingAs($admin)->postJson('/api/service-rates', $this->payload($foreignEventType->id, $package->id))
            ->assertUnprocessable()->assertJsonValidationErrors('event_type_id');
        $this->actingAs($admin)->postJson('/api/service-rates', $this->payload($eventType->id, $foreignPackage->id))
            ->assertUnprocessable()->assertJsonValidationErrors('package_id');
    }

    public function test_duplicate_combination_is_rejected_but_is_independent_per_tenant(): void
    {
        [$admin, $organization] = $this->admin();
        [, $otherOrganization] = $this->admin();
        [$eventType, , $package] = $this->catalog($organization);
        [$otherEventType, , $otherPackage] = $this->catalog($otherOrganization);
        ServiceRate::factory()->forCombination($eventType, $package)->create(['duration_minutes' => 180]);

        $this->actingAs($admin)->postJson('/api/service-rates', $this->payload($eventType->id, $package->id))
            ->assertUnprocessable()->assertJsonValidationErrors('duration_minutes');
        ServiceRate::factory()->forCombination($otherEventType, $otherPackage)->create(['duration_minutes' => 180]);
        $this->assertDatabaseHas('service_rates', [
            'organization_id' => $otherOrganization->id,
            'event_type_id' => $otherEventType->id,
            'package_id' => $otherPackage->id,
            'duration_minutes' => 180,
        ]);
    }

    public function test_validation_update_and_inactive_rate_preservation(): void
    {
        [$admin, $organization] = $this->admin();
        [$eventType, , $package] = $this->catalog($organization);
        $rate = ServiceRate::factory()->forCombination($eventType, $package)->create();

        $this->actingAs($admin)->postJson('/api/service-rates', [
            ...$this->payload($eventType->id, $package->id), 'duration_minutes' => 0, 'unit_rate' => '-0.01',
        ])->assertUnprocessable()->assertJsonValidationErrors(['duration_minutes', 'unit_rate']);

        $this->actingAs($admin)->putJson("/api/service-rates/{$rate->id}", [
            ...$this->payload($eventType->id, $package->id),
            'duration_minutes' => 240, 'unit_rate' => '8500.50', 'is_active' => false,
        ])->assertOk()->assertJsonPath('duration_minutes', 240)
            ->assertJsonPath('unit_rate', '8500.50')->assertJsonPath('is_active', false);
        $this->assertDatabaseHas('service_rates', ['id' => $rate->id, 'is_active' => false]);

        $this->actingAs($admin)->putJson("/api/service-rates/{$rate->id}", [
            ...$this->payload($eventType->id, $package->id), 'duration_minutes' => 240,
        ])->assertOk()->assertJsonPath('is_active', true);
    }

    public function test_list_is_tenant_scoped_and_filters_relationships_status_duration_and_search(): void
    {
        [$admin, $organization] = $this->admin();
        [, $otherOrganization] = $this->admin();
        [$eventType, $service, $package] = $this->catalog($organization, 'Wedding', 'Mirror Booth', 'Premium');
        $rate = ServiceRate::factory()->inactive()->forCombination($eventType, $package)->create(['duration_minutes' => 240]);
        [$foreignEventType, , $foreignPackage] = $this->catalog($otherOrganization);
        ServiceRate::factory()->forCombination($foreignEventType, $foreignPackage)->create();

        $url = "/api/service-rates?status=inactive&event_type_id={$eventType->id}&service_id={$service->id}"
            ."&package_id={$package->id}&duration_minutes=240&search=Mirror";
        $this->actingAs($admin)->getJson($url)->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $rate->id)
            ->assertJsonPath('data.0.service.id', $service->id);

        $this->actingAs($admin)->getJson('/api/service-rates?status=all')
            ->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_foreign_rate_is_hidden_and_database_rejects_cross_tenant_relationships(): void
    {
        [$admin, $organization] = $this->admin();
        [, $otherOrganization] = $this->admin();
        [$eventType] = $this->catalog($organization);
        [$foreignEventType, , $foreignPackage] = $this->catalog($otherOrganization);
        $foreignRate = ServiceRate::factory()->forCombination($foreignEventType, $foreignPackage)->create();

        $this->actingAs($admin)->getJson("/api/service-rates/{$foreignRate->id}")->assertNotFound();
        $this->actingAs($admin)->putJson("/api/service-rates/{$foreignRate->id}", $this->payload($foreignEventType->id, $foreignPackage->id))
            ->assertNotFound();

        $this->expectException(QueryException::class);
        ServiceRate::factory()->create([
            'organization_id' => $organization->id,
            'event_type_id' => $eventType->id,
            'package_id' => $foreignPackage->id,
        ]);
    }

    /** @return array{User, Organization} */
    private function admin(): array
    {
        $organization = Organization::factory()->create();

        return [User::factory()->for($organization)->create(), $organization];
    }

    /** @return array{EventType, Service, Package} */
    private function catalog(
        Organization $organization,
        string $eventTypeName = 'Wedding',
        string $serviceName = '360 Booth',
        string $packageName = 'Premium',
    ): array {
        $eventType = EventType::factory()->for($organization)->create(['name' => $eventTypeName]);
        $service = Service::factory()->for($organization)->create(['name' => $serviceName]);
        $package = Package::factory()->forService($service)->create(['name' => $packageName]);

        return [$eventType, $service, $package];
    }

    /** @return array<string, mixed> */
    private function payload(int $eventTypeId, int $packageId): array
    {
        return [
            'event_type_id' => $eventTypeId,
            'package_id' => $packageId,
            'duration_minutes' => 180,
            'unit_rate' => '7500.00',
            'is_active' => true,
        ];
    }
}
