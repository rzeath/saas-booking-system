<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_apis_are_versioned_while_session_and_health_routes_are_not(): void
    {
        $organization = Organization::factory()->withBusinessSettings()->create();
        $admin = User::factory()->for($organization)->create();
        $service = Service::factory()->for($organization)->create();

        $this->getJson('/api/health')->assertOk();
        $this->actingAs($admin)->getJson('/api/me')->assertOk();
        $this->actingAs($admin)->getJson('/api/v1/customers')->assertOk();

        $this->actingAs($admin)->getJson('/api/customers')->assertNotFound();
        $this->actingAs($admin)->getJson("/api/services/{$service->id}/packages")->assertNotFound();
        $this->actingAs($admin)->getJson('/api/service-rates')->assertNotFound();
        $this->postJson('/api/v1/auth/login')->assertNotFound();
    }
}
