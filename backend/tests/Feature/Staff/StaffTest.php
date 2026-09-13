<?php

namespace Tests\Feature\Staff;

use App\Models\Organization;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StaffTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_api_requires_authentication(): void
    {
        $staff = Staff::factory()->create();

        $this->getJson('/api/staff')->assertUnauthorized();
        $this->postJson('/api/staff', $this->payload())->assertUnauthorized();
        $this->getJson("/api/staff/{$staff->id}")->assertUnauthorized();
        $this->putJson("/api/staff/{$staff->id}", $this->payload())->assertUnauthorized();
    }

    public function test_tenant_lists_and_shows_only_its_staff(): void
    {
        [$admin, $organization] = $this->admin();
        [, $otherOrganization] = $this->admin();
        $own = Staff::factory()->for($organization)->create(['name' => 'Maria Santos']);
        $foreign = Staff::factory()->for($otherOrganization)->create(['name' => 'Private Person']);

        $this->actingAs($admin)->getJson('/api/staff?status=all')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $own->id)
            ->assertJsonMissing(['name' => 'Private Person']);

        $this->actingAs($admin)->getJson("/api/staff/{$own->id}")
            ->assertOk()
            ->assertJsonPath('name', 'Maria Santos')
            ->assertJsonMissingPath('organization_id');

        $this->actingAs($admin)->getJson("/api/staff/{$foreign->id}")->assertNotFound();
    }

    public function test_tenant_creates_staff_with_derived_organization_and_default_active_status(): void
    {
        [$admin, $organization] = $this->admin();
        [, $otherOrganization] = $this->admin();

        $response = $this->actingAs($admin)->postJson('/api/staff', [
            'name' => '  Juan Dela Cruz  ',
            'phone' => '  0917 123 4567  ',
            'email' => '  juan@example.com  ',
            'notes' => '  Lead operator  ',
            'organization_id' => $otherOrganization->id,
        ])->assertCreated()
            ->assertJsonPath('name', 'Juan Dela Cruz')
            ->assertJsonPath('phone', '0917 123 4567')
            ->assertJsonPath('email', 'juan@example.com')
            ->assertJsonPath('notes', 'Lead operator')
            ->assertJsonPath('is_active', true)
            ->assertJsonMissingPath('organization_id');

        $this->assertDatabaseHas('staff', [
            'id' => $response->json('id'),
            'organization_id' => $organization->id,
            'is_active' => true,
        ]);
    }

    public function test_required_and_optional_field_validation(): void
    {
        [$admin] = $this->admin();

        $this->actingAs($admin)->postJson('/api/staff', [
            'name' => '   ',
            'phone' => '   ',
            'email' => 'not-an-email',
            'notes' => str_repeat('a', 5001),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'phone', 'email', 'notes']);
    }

    public function test_duplicate_names_phone_numbers_and_emails_are_allowed(): void
    {
        [$admin, $organization] = $this->admin();
        Staff::factory()->for($organization)->create([
            'name' => 'Juan Dela Cruz',
            'phone' => '0917 123 4567',
            'email' => 'shared@example.com',
        ]);

        $this->actingAs($admin)->postJson('/api/staff', [
            'name' => 'Juan Dela Cruz',
            'phone' => '0917 123 4567',
            'email' => 'shared@example.com',
        ])->assertCreated();

        $this->assertSame(2, Staff::query()->where('organization_id', $organization->id)->count());
    }

    public function test_tenant_updates_all_staff_fields_and_status_but_not_foreign_staff(): void
    {
        [$admin, $organization] = $this->admin();
        [, $otherOrganization] = $this->admin();
        $staff = Staff::factory()->for($organization)->create();
        $foreign = Staff::factory()->for($otherOrganization)->create(['name' => 'Do Not Change']);

        $this->actingAs($admin)->putJson("/api/staff/{$staff->id}", [
            'name' => 'Updated Operator',
            'phone' => '+63 917 765 4321',
            'email' => 'updated@example.com',
            'notes' => 'Updated notes',
            'is_active' => false,
            'organization_id' => $otherOrganization->id,
        ])->assertOk()
            ->assertJsonPath('name', 'Updated Operator')
            ->assertJsonPath('phone', '+63 917 765 4321')
            ->assertJsonPath('email', 'updated@example.com')
            ->assertJsonPath('notes', 'Updated notes')
            ->assertJsonPath('is_active', false);

        $this->actingAs($admin)->putJson("/api/staff/{$foreign->id}", $this->payload())
            ->assertNotFound();
        $this->assertDatabaseHas('staff', ['id' => $foreign->id, 'name' => 'Do Not Change']);
    }

    public function test_search_covers_name_phone_and_email(): void
    {
        [$admin, $organization] = $this->admin();
        Staff::factory()->for($organization)->create([
            'name' => 'Maria Santos',
            'phone' => '0917 555 1234',
            'email' => 'maria@example.com',
        ]);
        Staff::factory()->for($organization)->create(['name' => 'Unrelated Operator']);

        foreach (['Maria', '555 1234', 'maria@example.com'] as $search) {
            $this->actingAs($admin)->getJson('/api/staff?status=all&search='.urlencode($search))
                ->assertOk()
                ->assertJsonPath('meta.total', 1)
                ->assertJsonPath('data.0.name', 'Maria Santos');
        }
    }

    public function test_status_filters_pagination_and_ordering_are_deterministic(): void
    {
        [$admin, $organization] = $this->admin();
        Staff::factory()->for($organization)->create(['name' => 'Alex Operator']);
        Staff::factory()->for($organization)->create(['name' => 'Alex Operator']);
        Staff::factory()->inactive()->for($organization)->create(['name' => 'Zed Inactive']);

        $this->actingAs($admin)->getJson('/api/staff?status=active&per_page=1&page=2')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('data.0.name', 'Alex Operator');

        $this->actingAs($admin)->getJson('/api/staff?status=inactive')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Zed Inactive');

        $this->actingAs($admin)->getJson('/api/staff?status=all')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
    }

    public function test_staff_schema_has_no_authentication_coupling_and_organization_deletion_is_restricted(): void
    {
        [, $organization] = $this->admin();
        Staff::factory()->for($organization)->create();

        $this->assertFalse(Schema::hasColumn('staff', 'user_id'));
        $this->assertFalse(Schema::hasColumn('staff', 'password'));
        $this->assertFalse(Schema::hasColumn('staff', 'role'));

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
            'name' => 'Juan Dela Cruz',
            'phone' => '0917 123 4567',
            'email' => 'juan@example.com',
            'notes' => 'Lead operator',
            'is_active' => true,
        ];
    }
}
