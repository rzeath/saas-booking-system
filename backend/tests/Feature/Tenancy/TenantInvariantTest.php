<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantInvariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_prevents_a_second_admin_for_an_organization(): void
    {
        $organization = Organization::factory()->create();
        User::factory()->for($organization)->create();

        $this->expectException(QueryException::class);

        User::factory()->for($organization)->create();
    }

    public function test_tenant_context_is_derived_from_the_authenticated_user(): void
    {
        $authenticatedUser = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->actingAs($authenticatedUser);

        $tenant = app(TenantContext::class);

        $this->assertSame($authenticatedUser->id, $tenant->user()->id);
        $this->assertSame($authenticatedUser->organization_id, $tenant->organizationId());
        $this->assertTrue($authenticatedUser->organization->is($tenant->organization()));
        $this->assertNotSame($otherUser->organization_id, $tenant->organizationId());
    }
}
