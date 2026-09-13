<?php

namespace Tests\Feature\Services;

use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_api_requires_authentication(): void
    {
        $service = Service::factory()->create();

        $this->getJson('/api/services')->assertUnauthorized();
        $this->postJson('/api/services', $this->payload())->assertUnauthorized();
        $this->getJson("/api/services/{$service->id}")->assertUnauthorized();
        $this->putJson("/api/services/{$service->id}", $this->payload())->assertUnauthorized();
    }

    public function test_tenant_lists_and_resolves_only_its_services(): void
    {
        [$admin, $organization] = $this->admin();
        [, $otherOrganization] = $this->admin();
        $own = Service::factory()->for($organization)->create(['name' => 'Mirror Booth']);
        $foreign = Service::factory()->for($otherOrganization)->create(['name' => 'Private Booth']);

        $this->actingAs($admin)->getJson('/api/services?status=all')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $own->id)
            ->assertJsonMissing(['name' => 'Private Booth']);
        $this->actingAs($admin)->getJson("/api/services/{$own->id}")
            ->assertOk()->assertJsonPath('name', 'Mirror Booth')->assertJsonMissingPath('organization_id');
        $this->actingAs($admin)->getJson("/api/services/{$foreign->id}")->assertNotFound();
    }

    public function test_tenant_creates_updates_and_cannot_override_organization(): void
    {
        [$admin, $organization] = $this->admin();
        [, $otherOrganization] = $this->admin();

        $response = $this->actingAs($admin)->postJson('/api/services', [
            ...$this->payload(), 'name' => '  360 Video Booth  ', 'organization_id' => $otherOrganization->id,
        ])->assertCreated()->assertJsonPath('name', '360 Video Booth')->assertJsonPath('total_units', 3);

        $this->assertDatabaseHas('services', [
            'id' => $response->json('id'), 'organization_id' => $organization->id,
        ]);

        $this->actingAs($admin)->putJson('/api/services/'.$response->json('id'), [
            'name' => '360 Booth', 'total_units' => 4, 'is_active' => false,
            'organization_id' => $otherOrganization->id,
        ])->assertOk()->assertJsonPath('is_active', false)->assertJsonPath('total_units', 4);
    }

    public function test_duplicate_names_are_case_insensitive_per_tenant_but_allowed_across_tenants(): void
    {
        [$admin, $organization] = $this->admin();
        [, $otherOrganization] = $this->admin();
        Service::factory()->for($organization)->create(['name' => 'Mirror Booth']);

        foreach (['Mirror Booth', 'mirror booth', ' MIRROR BOOTH '] as $name) {
            $this->actingAs($admin)->postJson('/api/services', [
                ...$this->payload(), 'name' => $name,
            ])->assertUnprocessable()->assertJsonValidationErrors('name');
        }

        Service::factory()->for($otherOrganization)->create(['name' => 'Mirror Booth']);
        $this->assertDatabaseHas('services', ['organization_id' => $otherOrganization->id, 'name' => 'Mirror Booth']);
    }

    public function test_total_units_search_status_pagination_and_foreign_update_are_enforced(): void
    {
        [$admin, $organization] = $this->admin();
        [, $otherOrganization] = $this->admin();
        Service::factory()->for($organization)->create(['name' => 'Alpha Booth']);
        Service::factory()->inactive()->for($organization)->create(['name' => 'Beta Booth']);
        $foreign = Service::factory()->for($otherOrganization)->create();

        $this->actingAs($admin)->postJson('/api/services', [...$this->payload(), 'total_units' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('total_units');
        $this->actingAs($admin)->getJson('/api/services?status=inactive&search=Beta&per_page=1')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Beta Booth');
        $this->actingAs($admin)->putJson("/api/services/{$foreign->id}", $this->payload())->assertNotFound();
    }

    public function test_database_enforces_unique_name_and_restrictive_organization_foreign_key(): void
    {
        [, $organization] = $this->admin();
        Service::factory()->for($organization)->create(['name' => 'Mirror Booth']);

        try {
            Service::factory()->for($organization)->create(['name' => 'mirror booth']);
            $this->fail('The database accepted a case-equivalent service name.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $this->expectException(QueryException::class);
        $organization->delete();
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
        return ['name' => '360 Video Booth', 'total_units' => 3, 'is_active' => true];
    }
}
