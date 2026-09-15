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
        $payload = $this->payload($rate->event_type_id, $rate->service_id, $rate->package_id);

        $path = "/api/v1/services/{$rate->service_id}/packages/{$rate->package_id}/rates";
        $this->getJson($path)->assertUnauthorized();
        $this->postJson($path, $payload)->assertUnauthorized();
        $this->getJson("{$path}/{$rate->id}")->assertUnauthorized();
        $this->putJson("{$path}/{$rate->id}", $payload)->assertUnauthorized();
    }

    public function test_tenant_creates_an_explicit_event_service_package_duration_rate(): void
    {
        [$admin, $organization] = $this->admin();
        [, $otherOrganization] = $this->admin();
        [$eventType, $service, $package] = $this->catalog($organization);

        $response = $this->actingAs($admin)->postJson("/api/v1/services/{$service->id}/packages/{$package->id}/rates", [
            ...$this->payload($eventType->id, $service->id, $package->id),
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
            'event_type_id' => $eventType->id, 'service_id' => $service->id,
            'package_id' => $package->id, 'duration_minutes' => 180,
        ]);
    }

    public function test_unmapped_and_cross_tenant_combinations_are_rejected(): void
    {
        [$admin, $organization] = $this->admin();
        [, $otherOrganization] = $this->admin();
        [$eventType, $service, $package] = $this->catalog($organization);
        [$foreignEventType, $foreignService, $foreignPackage] = $this->catalog($otherOrganization);
        $unmappedPackage = Package::factory()->for($organization)->create();

        $this->actingAs($admin)->postJson("/api/v1/services/{$service->id}/packages/{$package->id}/rates", $this->payload($foreignEventType->id, $service->id, $package->id))
            ->assertUnprocessable()->assertJsonValidationErrors('event_type_id');
        $this->actingAs($admin)->postJson("/api/v1/services/{$foreignService->id}/packages/{$package->id}/rates", $this->payload($eventType->id, $foreignService->id, $package->id))
            ->assertUnprocessable()->assertJsonValidationErrors('service_id');
        $this->actingAs($admin)->postJson("/api/v1/services/{$service->id}/packages/{$foreignPackage->id}/rates", $this->payload($eventType->id, $service->id, $foreignPackage->id))
            ->assertUnprocessable()->assertJsonValidationErrors('package_id');
        $this->actingAs($admin)->postJson("/api/v1/services/{$service->id}/packages/{$unmappedPackage->id}/rates", $this->payload($eventType->id, $service->id, $unmappedPackage->id))
            ->assertUnprocessable()->assertJsonValidationErrors('package_id');

        $this->expectException(QueryException::class);
        ServiceRate::factory()->create([
            'organization_id' => $organization->id,
            'event_type_id' => $eventType->id,
            'service_id' => $service->id,
            'package_id' => $foreignPackage->id,
        ]);
    }

    public function test_exact_duplicates_are_rejected_while_each_dimension_remains_independent(): void
    {
        [$admin, $organization] = $this->admin();
        [$eventType, $service, $package] = $this->catalog($organization);
        $otherService = Service::factory()->for($organization)->create(['name' => 'Mirror Booth']);
        $package->services()->attach($otherService->id, ['organization_id' => $organization->id]);
        $otherEventType = EventType::factory()->for($organization)->create(['name' => 'Birthday']);
        ServiceRate::factory()->forCombination($eventType, $service, $package)->create([
            'duration_minutes' => 180, 'unit_rate' => '5000.00',
        ]);

        $this->actingAs($admin)->postJson("/api/v1/services/{$service->id}/packages/{$package->id}/rates", $this->payload($eventType->id, $service->id, $package->id))
            ->assertUnprocessable()->assertJsonValidationErrors('duration_minutes');
        $this->actingAs($admin)->postJson("/api/v1/services/{$otherService->id}/packages/{$package->id}/rates", [
            ...$this->payload($eventType->id, $otherService->id, $package->id), 'unit_rate' => '9000.00',
        ])->assertCreated()->assertJsonPath('service.id', $otherService->id)->assertJsonPath('unit_rate', '9000.00');
        $this->actingAs($admin)->postJson("/api/v1/services/{$service->id}/packages/{$package->id}/rates", [
            ...$this->payload($eventType->id, $service->id, $package->id), 'duration_minutes' => 240,
        ])->assertCreated()->assertJsonPath('duration_minutes', 240);
        $this->actingAs($admin)->postJson("/api/v1/services/{$service->id}/packages/{$package->id}/rates", $this->payload($otherEventType->id, $service->id, $package->id))
            ->assertCreated()->assertJsonPath('event_type.id', $otherEventType->id);
    }

    public function test_rate_updates_filters_and_tenant_visibility_use_the_explicit_service(): void
    {
        [$admin, $organization] = $this->admin();
        [, $otherOrganization] = $this->admin();
        [$eventType, $service, $package] = $this->catalog($organization, 'Wedding', 'Mirror Booth', 'Premium');
        $rate = ServiceRate::factory()->inactive()->forCombination($eventType, $service, $package)->create(['duration_minutes' => 240]);
        [$foreignEventType, $foreignService, $foreignPackage] = $this->catalog($otherOrganization);
        $foreignRate = ServiceRate::factory()->forCombination($foreignEventType, $foreignService, $foreignPackage)->create();

        $url = "/api/v1/rates?status=inactive&event_type_id={$eventType->id}&service_id={$service->id}"
            ."&package_id={$package->id}&duration_minutes=240&search=Mirror";
        $this->actingAs($admin)->getJson($url)->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $rate->id)
            ->assertJsonPath('data.0.service.id', $service->id);

        $this->actingAs($admin)->putJson("/api/v1/services/{$service->id}/packages/{$package->id}/rates/{$rate->id}", [
            ...$this->payload($eventType->id, $service->id, $package->id),
            'duration_minutes' => 300, 'unit_rate' => '8500.50', 'is_active' => true,
        ])->assertOk()->assertJsonPath('duration_minutes', 300)->assertJsonPath('unit_rate', '8500.50');
        $this->actingAs($admin)->getJson("/api/v1/services/{$foreignService->id}/packages/{$foreignPackage->id}/rates/{$foreignRate->id}")->assertNotFound();
    }

    public function test_nested_rate_routes_reject_mismatched_service_package_and_rate_ids(): void
    {
        [$admin, $organization] = $this->admin();
        [$eventType, $service, $package] = $this->catalog($organization);
        $rate = ServiceRate::factory()->forCombination($eventType, $service, $package)->create();
        $otherService = Service::factory()->for($organization)->create();
        $otherPackage = Package::factory()->forService($service)->create();
        $package->services()->attach($otherService->id, ['organization_id' => $organization->id]);

        $this->actingAs($admin)->getJson("/api/v1/services/{$otherService->id}/packages/{$package->id}/rates/{$rate->id}")
            ->assertNotFound();
        $this->actingAs($admin)->getJson("/api/v1/services/{$service->id}/packages/{$otherPackage->id}/rates/{$rate->id}")
            ->assertNotFound();
        $this->actingAs($admin)->putJson(
            "/api/v1/services/{$otherService->id}/packages/{$package->id}/rates/{$rate->id}",
            $this->payload($eventType->id, $service->id, $package->id),
        )->assertNotFound();
        $this->actingAs($admin)->postJson("/api/v1/services/{$otherService->id}/packages/{$package->id}/rates", [
            ...$this->payload($eventType->id, $service->id, $package->id),
            'duration_minutes' => 181,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('service_id');

        $this->actingAs($admin)->getJson("/api/v1/services/{$service->id}/packages/{$package->id}/rates?status=all")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $rate->id);
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
    private function payload(int $eventTypeId, int $serviceId, int $packageId): array
    {
        return [
            'event_type_id' => $eventTypeId,
            'service_id' => $serviceId,
            'package_id' => $packageId,
            'duration_minutes' => 180,
            'unit_rate' => '7500.00',
            'is_active' => true,
        ];
    }
}
