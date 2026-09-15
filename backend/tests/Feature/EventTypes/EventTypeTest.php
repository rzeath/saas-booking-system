<?php

namespace Tests\Feature\EventTypes;

use App\Models\EventType;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_type_api_requires_authentication(): void
    {
        $eventType = EventType::factory()->create();

        $this->getJson('/api/v1/event-types')->assertUnauthorized();
        $this->postJson('/api/v1/event-types', $this->payload())->assertUnauthorized();
        $this->getJson("/api/v1/event-types/{$eventType->id}")->assertUnauthorized();
        $this->putJson("/api/v1/event-types/{$eventType->id}", $this->payload())->assertUnauthorized();
    }

    public function test_tenant_can_list_and_show_only_its_event_types(): void
    {
        [$admin, $organization] = $this->admin();
        [, $otherOrganization] = $this->admin();
        $expected = EventType::factory()->for($organization)->create(['name' => 'Wedding']);
        $other = EventType::factory()->for($otherOrganization)->create(['name' => 'Private Event']);

        $this->actingAs($admin)
            ->getJson('/api/v1/event-types?status=all')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $expected->id)
            ->assertJsonMissing(['name' => 'Private Event']);

        $this->actingAs($admin)
            ->getJson("/api/v1/event-types/{$expected->id}")
            ->assertOk()
            ->assertJsonPath('name', 'Wedding')
            ->assertJsonMissingPath('organization_id');

        $this->actingAs($admin)
            ->getJson("/api/v1/event-types/{$other->id}")
            ->assertNotFound();
    }

    public function test_tenant_can_create_event_type_and_cannot_override_organization(): void
    {
        [$admin, $organization] = $this->admin();
        [, $otherOrganization] = $this->admin();

        $response = $this->actingAs($admin)->postJson('/api/v1/event-types', [
            'name' => '  Corporate Event  ',
            'is_active' => true,
            'organization_id' => $otherOrganization->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'Corporate Event')
            ->assertJsonMissingPath('organization_id');

        $this->assertDatabaseHas('event_types', [
            'id' => $response->json('id'),
            'organization_id' => $organization->id,
        ]);
    }

    public function test_duplicate_and_case_equivalent_names_are_rejected_within_tenant(): void
    {
        [$admin, $organization] = $this->admin();
        EventType::factory()->for($organization)->create(['name' => 'Wedding']);

        foreach (['Wedding', 'wedding', ' WEDDING '] as $name) {
            $this->actingAs($admin)
                ->postJson('/api/v1/event-types', ['name' => $name, 'is_active' => true])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('name');
        }
    }

    public function test_same_event_type_name_is_allowed_across_tenants(): void
    {
        [$admin, $organization] = $this->admin();
        [, $otherOrganization] = $this->admin();
        EventType::factory()->for($otherOrganization)->create(['name' => 'Wedding']);

        $this->actingAs($admin)
            ->postJson('/api/v1/event-types', ['name' => 'Wedding', 'is_active' => true])
            ->assertCreated();

        $this->assertDatabaseHas('event_types', [
            'organization_id' => $organization->id,
            'name' => 'Wedding',
        ]);
    }

    public function test_tenant_can_update_and_deactivate_own_event_type_but_not_another_tenants(): void
    {
        [$admin, $organization] = $this->admin();
        [, $otherOrganization] = $this->admin();
        $eventType = EventType::factory()->for($organization)->create(['name' => 'Birthday']);
        $other = EventType::factory()->for($otherOrganization)->create(['name' => 'Do Not Change']);

        $this->actingAs($admin)
            ->putJson("/api/v1/event-types/{$eventType->id}", [
                'name' => 'Birthday Party',
                'is_active' => false,
                'organization_id' => $otherOrganization->id,
            ])
            ->assertOk()
            ->assertJsonPath('name', 'Birthday Party')
            ->assertJsonPath('is_active', false);

        $this->actingAs($admin)
            ->putJson("/api/v1/event-types/{$other->id}", $this->payload())
            ->assertNotFound();

        $this->assertDatabaseHas('event_types', ['id' => $other->id, 'name' => 'Do Not Change']);
    }

    public function test_update_uniqueness_ignores_current_row_and_foreign_ids_are_not_validated(): void
    {
        [$admin, $organization] = $this->admin();
        [, $otherOrganization] = $this->admin();
        $wedding = EventType::factory()->for($organization)->create(['name' => 'Wedding']);
        EventType::factory()->for($organization)->create(['name' => 'Birthday']);
        $other = EventType::factory()->for($otherOrganization)->create(['name' => 'Corporate']);

        $this->actingAs($admin)
            ->putJson("/api/v1/event-types/{$wedding->id}", ['name' => 'wedding', 'is_active' => true])
            ->assertOk();

        $this->actingAs($admin)
            ->putJson("/api/v1/event-types/{$other->id}", ['name' => 'Birthday', 'is_active' => true])
            ->assertNotFound();
    }

    public function test_event_type_search_status_filter_and_pagination_are_deterministic(): void
    {
        [$admin, $organization] = $this->admin();
        EventType::factory()->for($organization)->create(['name' => 'Birthday']);
        EventType::factory()->for($organization)->create(['name' => 'Corporate Event']);
        EventType::factory()->inactive()->for($organization)->create(['name' => 'Wedding']);

        $this->actingAs($admin)
            ->getJson('/api/v1/event-types?status=active&search=event')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Corporate Event');

        $this->actingAs($admin)
            ->getJson('/api/v1/event-types?status=inactive&per_page=1&page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Wedding');
    }

    public function test_database_enforces_case_insensitive_per_tenant_unique_name(): void
    {
        [, $organization] = $this->admin();
        EventType::factory()->for($organization)->create(['name' => 'Wedding']);

        $this->expectException(QueryException::class);
        EventType::factory()->for($organization)->create(['name' => 'wedding']);
    }

    public function test_event_type_foreign_key_restricts_organization_deletion(): void
    {
        [, $organization] = $this->admin();
        EventType::factory()->for($organization)->create();

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
        return [
            'name' => 'Wedding',
            'is_active' => true,
        ];
    }
}
