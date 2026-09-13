<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_api_requires_authentication(): void
    {
        $customer = Customer::factory()->create();

        $this->getJson('/api/customers')->assertUnauthorized();
        $this->postJson('/api/customers', $this->payload())->assertUnauthorized();
        $this->getJson("/api/customers/{$customer->id}")->assertUnauthorized();
        $this->putJson("/api/customers/{$customer->id}", $this->payload())->assertUnauthorized();
    }

    public function test_tenant_can_list_and_show_only_its_customers(): void
    {
        [$admin, $organization] = $this->admin();
        [, $otherOrganization] = $this->admin();
        $expected = Customer::factory()->for($organization)->create(['name' => 'Expected Customer']);
        $other = Customer::factory()->for($otherOrganization)->create(['name' => 'Private Customer']);

        $this->actingAs($admin)
            ->getJson('/api/customers?status=all')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $expected->id)
            ->assertJsonMissing(['name' => 'Private Customer']);

        $this->actingAs($admin)
            ->getJson("/api/customers/{$expected->id}")
            ->assertOk()
            ->assertJsonPath('name', 'Expected Customer')
            ->assertJsonMissingPath('organization_id');

        $this->actingAs($admin)
            ->getJson("/api/customers/{$other->id}")
            ->assertNotFound();
    }

    public function test_tenant_can_create_customers_and_cannot_override_organization(): void
    {
        [$admin, $organization] = $this->admin();
        [, $otherOrganization] = $this->admin();

        $response = $this->actingAs($admin)->postJson('/api/customers', [
            ...$this->payload(),
            'name' => '  Juan Dela Cruz  ',
            'email' => '  juan@example.com  ',
            'organization_id' => $otherOrganization->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'Juan Dela Cruz')
            ->assertJsonPath('email', 'juan@example.com')
            ->assertJsonMissingPath('organization_id');

        $this->assertDatabaseHas('customers', [
            'id' => $response->json('id'),
            'organization_id' => $organization->id,
        ]);
        $this->assertDatabaseMissing('customers', [
            'id' => $response->json('id'),
            'organization_id' => $otherOrganization->id,
        ]);
    }

    public function test_duplicate_customer_names_and_contact_values_are_allowed(): void
    {
        [$admin, $organization] = $this->admin();
        Customer::factory()->for($organization)->create([
            'name' => 'Same Person',
            'email' => 'same@example.com',
            'phone' => '09170000000',
        ]);

        $this->actingAs($admin)
            ->postJson('/api/customers', [
                ...$this->payload(),
                'name' => 'Same Person',
                'email' => 'same@example.com',
                'phone' => '09170000000',
            ])
            ->assertCreated();

        $this->assertSame(2, Customer::query()->where('organization_id', $organization->id)->count());
    }

    public function test_tenant_can_update_and_deactivate_its_customer_but_not_another_tenants(): void
    {
        [$admin, $organization] = $this->admin();
        [, $otherOrganization] = $this->admin();
        $customer = Customer::factory()->for($organization)->create();
        $other = Customer::factory()->for($otherOrganization)->create(['name' => 'Do Not Change']);

        $this->actingAs($admin)
            ->putJson("/api/customers/{$customer->id}", [
                ...$this->payload(),
                'name' => 'Updated Customer',
                'is_active' => false,
                'organization_id' => $otherOrganization->id,
            ])
            ->assertOk()
            ->assertJsonPath('name', 'Updated Customer')
            ->assertJsonPath('is_active', false);

        $this->actingAs($admin)
            ->putJson("/api/customers/{$other->id}", [
                ...$this->payload(),
                'name' => '   ',
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'organization_id' => $organization->id,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('customers', ['id' => $other->id, 'name' => 'Do Not Change']);
    }

    public function test_customer_validation_rejects_missing_name_and_invalid_optional_email(): void
    {
        [$admin] = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/customers', [
                ...$this->payload(),
                'name' => '   ',
                'email' => 'not-an-email',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email']);
    }

    public function test_customer_search_covers_name_email_and_phone(): void
    {
        [$admin, $organization] = $this->admin();
        Customer::factory()->for($organization)->create([
            'name' => 'Maria Santos',
            'email' => 'maria@example.com',
            'phone' => '09171234567',
        ]);
        Customer::factory()->for($organization)->create(['name' => 'Unrelated Customer']);

        foreach (['Maria', 'maria@example.com', '1234567'] as $search) {
            $this->actingAs($admin)
                ->getJson('/api/customers?status=all&search='.urlencode($search))
                ->assertOk()
                ->assertJsonPath('meta.total', 1)
                ->assertJsonPath('data.0.name', 'Maria Santos');
        }
    }

    public function test_customer_status_filter_and_pagination_are_deterministic(): void
    {
        [$admin, $organization] = $this->admin();
        Customer::factory()->for($organization)->create(['name' => 'Alpha Active']);
        Customer::factory()->for($organization)->create(['name' => 'Beta Active']);
        Customer::factory()->inactive()->for($organization)->create(['name' => 'Gamma Inactive']);

        $this->actingAs($admin)
            ->getJson('/api/customers?status=active&per_page=1&page=2')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('data.0.name', 'Beta Active');

        $this->actingAs($admin)
            ->getJson('/api/customers?status=inactive')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Gamma Inactive');
    }

    public function test_customer_foreign_key_restricts_organization_deletion(): void
    {
        [, $organization] = $this->admin();
        Customer::factory()->for($organization)->create();

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
            'email' => 'juan@example.com',
            'phone' => '+63 917 123 4567',
            'address' => 'Makati City',
            'notes' => 'Prefers afternoon calls.',
            'is_active' => true,
        ];
    }
}
