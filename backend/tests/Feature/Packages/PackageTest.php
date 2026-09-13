<?php

namespace Tests\Feature\Packages;

use App\Models\Organization;
use App\Models\Package;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackageTest extends TestCase
{
    use RefreshDatabase;

    public function test_package_api_requires_authentication(): void
    {
        $package = Package::factory()->create();

        $this->getJson("/api/services/{$package->service_id}/packages")->assertUnauthorized();
        $this->postJson("/api/services/{$package->service_id}/packages", $this->payload())->assertUnauthorized();
        $this->getJson("/api/packages/{$package->id}")->assertUnauthorized();
        $this->putJson("/api/packages/{$package->id}", $this->payload())->assertUnauthorized();
    }

    public function test_tenant_creates_lists_and_updates_package_under_own_service(): void
    {
        [$admin, $organization] = $this->admin();
        $service = Service::factory()->for($organization)->create();

        $response = $this->actingAs($admin)->postJson("/api/services/{$service->id}/packages", [
            'name' => '  Premium  ', 'is_active' => true, 'organization_id' => 999, 'service_id' => 999,
        ])->assertCreated()->assertJsonPath('name', 'Premium')->assertJsonPath('service.id', $service->id);

        $packageId = $response->json('id');
        $this->assertDatabaseHas('packages', [
            'id' => $packageId, 'organization_id' => $organization->id, 'service_id' => $service->id,
        ]);
        $this->actingAs($admin)->getJson("/api/services/{$service->id}/packages?status=all")
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $packageId);
        $this->actingAs($admin)->putJson("/api/packages/{$packageId}", [
            'name' => 'Premium Plus', 'is_active' => false, 'service_id' => 999,
        ])->assertOk()->assertJsonPath('is_active', false)->assertJsonPath('service.id', $service->id);
    }

    public function test_foreign_service_and_package_ids_are_hidden(): void
    {
        [$admin] = $this->admin();
        [, $otherOrganization] = $this->admin();
        $service = Service::factory()->for($otherOrganization)->create();
        $package = Package::factory()->forService($service)->create();

        $this->actingAs($admin)->getJson("/api/services/{$service->id}/packages")->assertNotFound();
        $this->actingAs($admin)->postJson("/api/services/{$service->id}/packages", $this->payload())->assertNotFound();
        $this->actingAs($admin)->getJson("/api/packages/{$package->id}")->assertNotFound();
        $this->actingAs($admin)->putJson("/api/packages/{$package->id}", $this->payload())->assertNotFound();
    }

    public function test_package_name_is_case_insensitive_within_service_and_reusable_across_services(): void
    {
        [$admin, $organization] = $this->admin();
        $first = Service::factory()->for($organization)->create();
        $second = Service::factory()->for($organization)->create();
        Package::factory()->forService($first)->create(['name' => 'Premium']);

        $this->actingAs($admin)->postJson("/api/services/{$first->id}/packages", ['name' => 'premium'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->actingAs($admin)->postJson("/api/services/{$second->id}/packages", ['name' => 'Premium'])
            ->assertCreated();
    }

    public function test_package_status_filter_and_immutable_service_relationship(): void
    {
        [$admin, $organization] = $this->admin();
        $service = Service::factory()->for($organization)->create();
        $otherService = Service::factory()->for($organization)->create();
        $package = Package::factory()->inactive()->forService($service)->create(['name' => 'Basic']);

        $this->actingAs($admin)->getJson("/api/services/{$service->id}/packages?status=inactive")
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Basic');
        $this->actingAs($admin)->putJson("/api/packages/{$package->id}", [
            'name' => 'Basic', 'is_active' => true, 'service_id' => $otherService->id,
        ])->assertOk()->assertJsonPath('service.id', $service->id);
        $this->assertSame($service->id, $package->refresh()->service_id);
    }

    public function test_database_rejects_cross_tenant_service_and_restricts_service_deletion(): void
    {
        [, $organization] = $this->admin();
        [, $otherOrganization] = $this->admin();
        $service = Service::factory()->for($organization)->create();
        $otherService = Service::factory()->for($otherOrganization)->create();
        Package::factory()->forService($service)->create();

        try {
            Package::factory()->create([
                'organization_id' => $organization->id, 'service_id' => $otherService->id,
            ]);
            $this->fail('The database accepted a cross-tenant service relationship.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $this->expectException(QueryException::class);
        $service->delete();
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
