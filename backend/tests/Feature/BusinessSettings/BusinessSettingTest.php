<?php

namespace Tests\Feature\BusinessSettings;

use App\Models\BusinessSetting;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_users_cannot_read_or_update_business_settings(): void
    {
        $this->getJson('/api/v1/business-settings')->assertUnauthorized();
        $this->putJson('/api/v1/business-settings', $this->validPayload())->assertUnauthorized();
    }

    public function test_authenticated_admin_reads_only_their_organizations_settings(): void
    {
        $expected = $this->adminWithSettings('Expected Tenant', ['display_name' => 'Expected Display']);
        $other = $this->adminWithSettings('Other Tenant', ['display_name' => 'Other Display']);

        $this->actingAs($expected)
            ->getJson('/api/v1/business-settings')
            ->assertOk()
            ->assertExactJson([
                'display_name' => 'Expected Display',
                'email' => null,
                'phone' => null,
                'address' => null,
                'logo_path' => null,
                'currency' => BusinessSetting::DEFAULT_CURRENCY,
                'booking_prefix' => BusinessSetting::DEFAULT_BOOKING_PREFIX,
                'quotation_prefix' => BusinessSetting::DEFAULT_QUOTATION_PREFIX,
                'billing_prefix' => BusinessSetting::DEFAULT_BILLING_PREFIX,
            ]);

        $this->assertNotSame($expected->organization_id, $other->organization_id);
    }

    public function test_authenticated_admin_updates_allowed_settings_without_renaming_organization(): void
    {
        $admin = $this->adminWithSettings('Canonical Tenant');

        $this->actingAs($admin)
            ->putJson('/api/v1/business-settings', [
                ...$this->validPayload(),
                'display_name' => '  Public Brand  ',
                'timezone' => 'Asia/Singapore',
                'currency' => 'usd',
                'booking_prefix' => 'book',
                'quotation_prefix' => 'quote2',
                'billing_prefix' => 'invoice',
            ])
            ->assertOk()
            ->assertJsonPath('display_name', 'Public Brand')
            ->assertJsonPath('currency', 'USD')
            ->assertJsonPath('booking_prefix', 'BOOK')
            ->assertJsonPath('quotation_prefix', 'QUOTE2')
            ->assertJsonPath('billing_prefix', 'INVOICE');

        $this->actingAs($admin)->getJson('/api/v1/business-settings')
            ->assertOk()
            ->assertJsonMissingPath('timezone');

        $this->assertSame('Canonical Tenant', $admin->organization->fresh()->name);
        $this->assertDatabaseHas('business_settings', [
            'organization_id' => $admin->organization_id,
            'display_name' => 'Public Brand',
            'email' => 'bookings@example.com',
            'phone' => '+63 917 123 4567',
            'address' => 'Makati City',
            'currency' => 'USD',
            'booking_prefix' => 'BOOK',
            'quotation_prefix' => 'QUOTE2',
            'billing_prefix' => 'INVOICE',
        ]);
    }

    public function test_invalid_currency_and_prefixes_are_rejected(): void
    {
        $admin = $this->adminWithSettings('Tenant');

        $this->actingAs($admin)
            ->putJson('/api/v1/business-settings', [
                ...$this->validPayload(),
                'currency' => 'US1',
                'booking_prefix' => '   ',
                'quotation_prefix' => 'QUOTE-WITH-DASH',
                'billing_prefix' => 'PREFIX-TOO-LONG',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'currency',
                'booking_prefix',
                'quotation_prefix',
                'billing_prefix',
            ]);
    }

    public function test_payload_cannot_reassign_settings_to_another_organization(): void
    {
        $admin = $this->adminWithSettings('Tenant A', ['display_name' => 'Tenant A']);
        $other = $this->adminWithSettings('Tenant B', ['display_name' => 'Tenant B']);

        $this->actingAs($admin)
            ->putJson('/api/v1/business-settings', [
                ...$this->validPayload(),
                'display_name' => 'Tenant A Updated',
                'organization_id' => $other->organization_id,
            ])
            ->assertOk()
            ->assertJsonPath('display_name', 'Tenant A Updated');

        $this->assertDatabaseHas('business_settings', [
            'organization_id' => $admin->organization_id,
            'display_name' => 'Tenant A Updated',
        ]);
        $this->assertDatabaseHas('business_settings', [
            'organization_id' => $other->organization_id,
            'display_name' => 'Tenant B',
        ]);
    }

    public function test_database_allows_only_one_settings_row_per_organization(): void
    {
        $organization = Organization::factory()->create();
        BusinessSetting::factory()->for($organization)->create();

        $this->expectException(QueryException::class);

        BusinessSetting::factory()->for($organization)->create();
    }

    /** @param array<string, mixed> $settings */
    private function adminWithSettings(string $organizationName, array $settings = []): User
    {
        $organization = Organization::factory()->create(['name' => $organizationName]);
        BusinessSetting::factory()->for($organization)->create([
            'display_name' => $organizationName,
            ...$settings,
        ]);

        return User::factory()->for($organization)->create();
    }

    /** @return array<string, string> */
    private function validPayload(): array
    {
        return [
            'display_name' => 'Rzeath Events',
            'email' => 'bookings@example.com',
            'phone' => '+63 917 123 4567',
            'address' => 'Makati City',
            'currency' => 'PHP',
            'booking_prefix' => 'BK',
            'quotation_prefix' => 'QT',
            'billing_prefix' => 'INV',
        ];
    }
}
