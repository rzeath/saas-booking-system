<?php

namespace Tests\Feature\Auth;

use App\Actions\Auth\RegisterAdmin;
use App\Models\BusinessSetting;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_and_authenticates_one_admin_for_a_new_organization(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'business_name' => 'Rzeath Events',
            'admin_name' => 'Erica Admin',
            'email' => 'ADMIN@example.com',
            'password' => 'StrongPass1',
            'password_confirmation' => 'StrongPass1',
        ]);

        $response->assertCreated()
            ->assertJson([
                'user' => [
                    'name' => 'Erica Admin',
                    'email' => 'admin@example.com',
                ],
                'organization' => [
                    'name' => 'Rzeath Events',
                    'status' => Organization::STATUS_ACTIVE,
                ],
            ])
            ->assertJsonMissingPath('user.password');

        $organization = Organization::sole();
        $user = User::sole();
        $settings = BusinessSetting::sole();

        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->organization->is($organization));
        $this->assertTrue($organization->user->is($user));
        $this->assertSame(1, $organization->user()->count());
        $this->assertTrue($settings->organization->is($organization));
        $this->assertSame('Rzeath Events', $settings->display_name);
        $this->assertSame(BusinessSetting::DEFAULT_TIMEZONE, $settings->timezone);
        $this->assertSame(BusinessSetting::DEFAULT_CURRENCY, $settings->currency);
        $this->assertSame(BusinessSetting::DEFAULT_BOOKING_PREFIX, $settings->booking_prefix);
        $this->assertSame(BusinessSetting::DEFAULT_QUOTATION_PREFIX, $settings->quotation_prefix);
        $this->assertSame(BusinessSetting::DEFAULT_BILLING_PREFIX, $settings->billing_prefix);
        $this->assertTrue(Hash::check('StrongPass1', $user->password));
        $this->assertNotSame('StrongPass1', $user->password);
    }

    public function test_duplicate_email_is_rejected_without_creating_another_organization(): void
    {
        User::factory()->create(['email' => 'admin@example.com']);

        $this->postJson('/api/auth/register', $this->validPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('organizations', 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseEmpty('business_settings');
    }

    public function test_invalid_registration_is_rejected(): void
    {
        $this->postJson('/api/auth/register', [
            'business_name' => '',
            'admin_name' => '',
            'email' => 'invalid',
            'password' => 'weak',
            'password_confirmation' => 'different',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'business_name',
                'admin_name',
                'email',
                'password',
            ]);

        $this->assertDatabaseEmpty('organizations');
        $this->assertDatabaseEmpty('users');
        $this->assertDatabaseEmpty('business_settings');
    }

    public function test_user_creation_failure_rolls_back_the_new_organization(): void
    {
        User::factory()->create(['email' => 'admin@example.com']);

        try {
            app(RegisterAdmin::class)->handle([
                'business_name' => 'Should Roll Back',
                'admin_name' => 'Another Admin',
                'email' => 'admin@example.com',
                'password' => 'StrongPass1',
            ]);

        } catch (QueryException) {
            $this->assertDatabaseMissing('organizations', ['name' => 'Should Roll Back']);
            $this->assertDatabaseCount('organizations', 1);
            $this->assertDatabaseCount('users', 1);
            $this->assertDatabaseEmpty('business_settings');

            return;
        }

        $this->fail('The duplicate email should have prevented user creation.');
    }

    public function test_registration_ignores_caller_controlled_organization_id(): void
    {
        $existingOrganization = Organization::factory()->create(['name' => 'Existing Tenant']);

        $this->postJson('/api/auth/register', [
            ...$this->validPayload(),
            'organization_id' => $existingOrganization->id,
        ])->assertCreated();

        $user = User::where('email', 'admin@example.com')->firstOrFail();

        $this->assertNotSame($existingOrganization->id, $user->organization_id);
        $this->assertSame('Rzeath Events', $user->organization->name);
        $this->assertNull($existingOrganization->user);
    }

    /** @return array<string, string> */
    private function validPayload(): array
    {
        return [
            'business_name' => 'Rzeath Events',
            'admin_name' => 'Erica Admin',
            'email' => 'admin@example.com',
            'password' => 'StrongPass1',
            'password_confirmation' => 'StrongPass1',
        ];
    }
}
