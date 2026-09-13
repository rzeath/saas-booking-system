<?php

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_credentials_authenticate_and_regenerate_the_session(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'StrongPass1',
        ]);

        $this->withSession(['fixed-session-marker' => true]);
        $originalSessionId = session()->getId();

        $this->postJson('/api/auth/login', [
            'email' => 'ADMIN@example.com',
            'password' => 'StrongPass1',
        ])->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('organization.id', $user->organization_id);

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($originalSessionId, session()->getId());
    }

    public function test_invalid_password_and_unknown_email_return_the_same_generic_failure(): void
    {
        User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'StrongPass1',
        ]);

        $invalidPassword = $this->postJson('/api/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'WrongPass1',
        ]);
        $unknownEmail = $this->postJson('/api/auth/login', [
            'email' => 'unknown@example.com',
            'password' => 'WrongPass1',
        ]);

        $invalidPassword->assertUnprocessable()->assertJsonValidationErrors('email');
        $unknownEmail->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame($invalidPassword->json('errors.email'), $unknownEmail->json('errors.email'));
        $this->assertGuest();
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_me_returns_only_the_authenticated_users_tenant_context(): void
    {
        $expected = User::factory()
            ->for(Organization::factory()->state(['name' => 'Expected Tenant']))
            ->create(['name' => 'Expected Admin', 'email' => 'expected@example.com']);
        User::factory()
            ->for(Organization::factory()->state(['name' => 'Other Tenant']))
            ->create(['email' => 'other@example.com']);

        $this->actingAs($expected)
            ->getJson('/api/me')
            ->assertExactJson([
                'user' => [
                    'id' => $expected->id,
                    'name' => 'Expected Admin',
                    'email' => 'expected@example.com',
                ],
                'organization' => [
                    'id' => $expected->organization_id,
                    'name' => 'Expected Tenant',
                    'status' => Organization::STATUS_ACTIVE,
                ],
            ]);
    }

    public function test_logout_invalidates_the_authenticated_session(): void
    {
        User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'StrongPass1',
        ]);

        $this->withSession(['private-marker' => 'present'])
            ->postJson('/api/auth/login', [
                'email' => 'admin@example.com',
                'password' => 'StrongPass1',
            ])->assertOk();

        $this->postJson('/api/auth/logout')->assertNoContent();

        $this->getJson('/api/me')->assertUnauthorized();
        $this->assertFalse(session()->has('private-marker'));
    }
}
