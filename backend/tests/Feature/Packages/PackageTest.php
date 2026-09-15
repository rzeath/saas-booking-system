<?php

namespace Tests\Feature\Packages;

use App\Models\EventType;
use App\Models\Organization;
use App\Models\Package;
use App\Models\Service;
use App\Models\ServiceRate;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PackageTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_schema_keeps_packages_independent_and_uses_a_tenant_safe_pivot(): void
    {
        $this->assertFalse(Schema::hasColumn('packages', 'service_id'));
        $this->assertTrue(Schema::hasTable('service_package'));
        $this->assertTrue(Schema::hasColumns('service_package', ['organization_id', 'service_id', 'package_id']));
        $this->assertTrue(Schema::hasColumn('service_rates', 'service_id'));
    }

    public function test_package_and_mapping_apis_require_authentication(): void
    {
        $package = Package::factory()->create();
        $service = Service::factory()->create();

        $this->getJson('/api/v1/packages')->assertUnauthorized();
        $this->postJson('/api/v1/packages', $this->payload())->assertUnauthorized();
        $this->getJson("/api/v1/packages/{$package->id}")->assertUnauthorized();
        $this->putJson("/api/v1/packages/{$package->id}", $this->payload())->assertUnauthorized();
        $this->getJson("/api/v1/services/{$service->id}/package-mappings")->assertUnauthorized();
        $this->putJson("/api/v1/services/{$service->id}/package-mappings", ['package_ids' => []])->assertUnauthorized();
    }

    public function test_tenant_creates_lists_and_updates_an_independent_package(): void
    {
        [$admin, $organization] = $this->admin();

        $response = $this->actingAs($admin)->postJson('/api/v1/packages', [
            'name' => '  Premium  ', 'is_active' => true, 'organization_id' => 999, 'service_id' => 999,
        ])->assertCreated()->assertJsonPath('name', 'Premium')->assertJsonPath('services', []);

        $packageId = $response->json('id');
        $this->assertDatabaseHas('packages', [
            'id' => $packageId, 'organization_id' => $organization->id, 'name' => 'Premium',
        ]);
        $this->actingAs($admin)->getJson('/api/v1/packages?status=all')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $packageId);
        $this->actingAs($admin)->putJson("/api/v1/packages/{$packageId}", [
            'name' => 'Premium Plus', 'is_active' => false, 'service_id' => 999,
        ])->assertOk()->assertJsonPath('is_active', false)->assertJsonPath('services', []);
    }

    public function test_package_can_be_mapped_to_multiple_services_and_service_can_have_multiple_packages(): void
    {
        [$admin, $organization] = $this->admin();
        $firstService = Service::factory()->for($organization)->create(['name' => 'Mirror Booth']);
        $secondService = Service::factory()->for($organization)->create(['name' => '360 Booth']);
        $premium = Package::factory()->for($organization)->create(['name' => 'Premium']);
        $basic = Package::factory()->for($organization)->create(['name' => 'Basic']);

        $this->actingAs($admin)->putJson("/api/v1/services/{$firstService->id}/package-mappings", [
            'package_ids' => [$premium->id, $basic->id],
        ])->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($admin)->putJson("/api/v1/services/{$secondService->id}/package-mappings", [
            'package_ids' => [$premium->id],
        ])->assertOk()->assertJsonCount(1, 'data');

        $this->assertCount(2, $firstService->refresh()->packages);
        $this->assertCount(2, $premium->refresh()->services);
        $this->actingAs($admin)->getJson("/api/v1/services/{$firstService->id}/package-mappings?status=all")
            ->assertOk()->assertJsonPath('meta.total', 2);
        $this->actingAs($admin)->getJson("/api/v1/packages/{$premium->id}")
            ->assertOk()->assertJsonCount(2, 'services');
    }

    public function test_duplicate_and_cross_tenant_mappings_are_rejected_by_validation_and_database(): void
    {
        [$admin, $organization] = $this->admin();
        [, $otherOrganization] = $this->admin();
        $service = Service::factory()->for($organization)->create();
        $package = Package::factory()->for($organization)->create();
        $foreignPackage = Package::factory()->for($otherOrganization)->create();

        $this->actingAs($admin)->putJson("/api/v1/services/{$service->id}/package-mappings", [
            'package_ids' => [$package->id, $package->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('package_ids.1');
        $this->actingAs($admin)->putJson("/api/v1/services/{$service->id}/package-mappings", [
            'package_ids' => [$foreignPackage->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('package_ids.0');

        DB::table('service_package')->insert([
            'organization_id' => $organization->id, 'service_id' => $service->id, 'package_id' => $package->id,
        ]);
        try {
            DB::table('service_package')->insert([
                'organization_id' => $organization->id, 'service_id' => $service->id, 'package_id' => $package->id,
            ]);
            $this->fail('The database accepted a duplicate service-package mapping.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $this->expectException(QueryException::class);
        DB::table('service_package')->insert([
            'organization_id' => $organization->id, 'service_id' => $service->id, 'package_id' => $foreignPackage->id,
        ]);
    }

    public function test_package_names_are_case_insensitive_per_tenant_and_tenant_data_is_hidden(): void
    {
        [$admin, $organization] = $this->admin();
        [, $otherOrganization] = $this->admin();
        Package::factory()->for($organization)->create(['name' => 'Premium']);
        $foreign = Package::factory()->for($otherOrganization)->create(['name' => 'Private']);

        $this->actingAs($admin)->postJson('/api/v1/packages', ['name' => 'premium'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        Package::factory()->for($otherOrganization)->create(['name' => 'Premium']);
        $this->actingAs($admin)->getJson('/api/v1/packages?status=all')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonMissing(['name' => 'Private']);
        $this->actingAs($admin)->getJson("/api/v1/packages/{$foreign->id}")->assertNotFound();
        $this->actingAs($admin)->putJson("/api/v1/packages/{$foreign->id}", $this->payload())->assertNotFound();
    }

    public function test_mapping_used_by_a_rate_cannot_be_unassigned(): void
    {
        [$admin, $organization] = $this->admin();
        $eventType = EventType::factory()->for($organization)->create();
        $service = Service::factory()->for($organization)->create();
        $package = Package::factory()->forService($service)->create();
        ServiceRate::factory()->forCombination($eventType, $service, $package)->create();

        $this->actingAs($admin)->putJson("/api/v1/services/{$service->id}/package-mappings", [
            'package_ids' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('package_ids');
        $this->assertDatabaseHas('service_package', [
            'organization_id' => $organization->id,
            'service_id' => $service->id,
            'package_id' => $package->id,
        ]);
    }

    /** @return array{User, Organization} */
    private function admin(): array
    {
        $organization = Organization::factory()->create();

        return [User::factory()->for($organization)->create(), $organization];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['name' => 'Premium', 'is_active' => true];
    }
}
