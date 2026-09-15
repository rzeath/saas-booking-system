<?php

namespace Tests\Feature\BusinessSettings;

use App\Enums\ThemeAccent;
use App\Models\Booking;
use App\Models\BusinessSetting;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BusinessSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_users_cannot_read_or_update_business_settings(): void
    {
        $this->getJson('/api/v1/business-settings')->assertUnauthorized();
        $this->putJson('/api/v1/business-settings', $this->validPayload())->assertUnauthorized();
    }

    public function test_authenticated_admin_reads_only_their_organizations_branding(): void
    {
        $expected = $this->adminWithSettings('Expected Tenant', [
            'display_name' => 'Expected Display',
            'theme_accent' => ThemeAccent::Forest,
        ]);
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
                'logo_url' => null,
                'theme_accent' => ThemeAccent::Forest->value,
                'booking_prefix' => BusinessSetting::DEFAULT_BOOKING_PREFIX,
                'quotation_prefix' => BusinessSetting::DEFAULT_QUOTATION_PREFIX,
                'billing_prefix' => BusinessSetting::DEFAULT_BILLING_PREFIX,
            ])
            ->assertJsonMissingPath('currency')
            ->assertJsonMissingPath('timezone');

        $this->assertNotSame($expected->organization_id, $other->organization_id);
    }

    public function test_philippine_locale_and_non_configurable_business_fields_are_system_invariants(): void
    {
        $this->assertSame('en-PH', config('app.locale'));
        $this->assertFalse(Schema::hasColumn('business_settings', 'currency'));
        $this->assertFalse(Schema::hasColumn('business_settings', 'timezone'));
    }

    public function test_authenticated_admin_updates_business_identity_without_renaming_organization(): void
    {
        $admin = $this->adminWithSettings('Canonical Tenant');

        $this->actingAs($admin)
            ->putJson('/api/v1/business-settings', [
                ...$this->validPayload(),
                'display_name' => '  Public Brand  ',
                'theme_accent' => ThemeAccent::Teal->value,
                'booking_prefix' => 'book',
                'quotation_prefix' => 'quote2',
                'billing_prefix' => 'invoice',
            ])
            ->assertOk()
            ->assertJsonPath('display_name', 'Public Brand')
            ->assertJsonPath('theme_accent', ThemeAccent::Teal->value)
            ->assertJsonPath('booking_prefix', 'BOOK')
            ->assertJsonPath('quotation_prefix', 'QUOTE2')
            ->assertJsonPath('billing_prefix', 'INVOICE');

        $this->assertSame('Canonical Tenant', $admin->organization->fresh()->name);
        $this->assertDatabaseHas('business_settings', [
            'organization_id' => $admin->organization_id,
            'display_name' => 'Public Brand',
            'email' => 'bookings@example.com',
            'phone' => '+63 917 123 4567',
            'address' => 'Makati City',
            'theme_accent' => ThemeAccent::Teal->value,
            'booking_prefix' => 'BOOK',
            'quotation_prefix' => 'QUOTE2',
            'billing_prefix' => 'INVOICE',
        ]);
    }

    public function test_tenant_currency_timezone_and_logo_paths_cannot_be_submitted(): void
    {
        $admin = $this->adminWithSettings('Tenant');

        $this->actingAs($admin)
            ->putJson('/api/v1/business-settings', [
                ...$this->validPayload(),
                'currency' => 'USD',
                'timezone' => 'Asia/Singapore',
                'logo_path' => '../../untrusted.png',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['currency', 'timezone', 'logo_path']);

        $this->assertDatabaseMissing('business_settings', [
            'organization_id' => $admin->organization_id,
            'display_name' => 'Rzeath Events',
        ]);
    }

    #[DataProvider('themeAccents')]
    public function test_each_curated_theme_accent_is_accepted(string $accent): void
    {
        $admin = $this->adminWithSettings('Tenant');

        $this->actingAs($admin)
            ->putJson('/api/v1/business-settings', [
                ...$this->validPayload(),
                'theme_accent' => $accent,
            ])
            ->assertOk()
            ->assertJsonPath('theme_accent', $accent);
    }

    /** @return array<string, array{string}> */
    public static function themeAccents(): array
    {
        return array_combine(
            array_map(fn (ThemeAccent $accent): string => $accent->value, ThemeAccent::cases()),
            array_map(fn (ThemeAccent $accent): array => [$accent->value], ThemeAccent::cases()),
        );
    }

    public function test_arbitrary_theme_accent_and_invalid_prefixes_are_rejected(): void
    {
        $admin = $this->adminWithSettings('Tenant');

        $this->actingAs($admin)
            ->putJson('/api/v1/business-settings', [
                ...$this->validPayload(),
                'theme_accent' => '#ff00ff',
                'booking_prefix' => '   ',
                'quotation_prefix' => 'QUOTE-WITH-DASH',
                'billing_prefix' => 'PREFIX-TOO-LONG',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'theme_accent',
                'booking_prefix',
                'quotation_prefix',
                'billing_prefix',
            ]);
    }

    public function test_default_theme_accent_is_plum(): void
    {
        $organization = Organization::factory()->create();
        $settings = $organization->businessSetting()->create([
            'display_name' => 'Default Brand',
            'booking_prefix' => BusinessSetting::DEFAULT_BOOKING_PREFIX,
            'quotation_prefix' => BusinessSetting::DEFAULT_QUOTATION_PREFIX,
            'billing_prefix' => BusinessSetting::DEFAULT_BILLING_PREFIX,
        ]);

        $this->assertSame(ThemeAccent::Plum, $settings->theme_accent);
        $this->assertSame(ThemeAccent::Plum->value, $settings->getRawOriginal('theme_accent'));
    }

    public function test_valid_logo_upload_is_stored_under_the_authenticated_tenant(): void
    {
        Storage::fake('public');
        $admin = $this->adminWithSettings('Tenant');

        $response = $this->actingAs($admin)
            ->withHeader('Accept', 'application/json')
            ->post('/api/v1/business-settings', [
                ...$this->validPayload(),
                '_method' => 'PUT',
                'logo' => UploadedFile::fake()->image('brand.png', 500, 300),
            ])
            ->assertOk()
            ->assertJsonPath('theme_accent', ThemeAccent::Plum->value);

        $path = $response->json('logo_path');
        $this->assertIsString($path);
        $this->assertStringStartsWith("business-logos/{$admin->organization_id}/", $path);
        $this->assertStringContainsString('/storage/business-logos/', $response->json('logo_url'));
        Storage::disk('public')->assertExists($path);
    }

    public function test_invalid_logo_file_is_rejected(): void
    {
        Storage::fake('public');
        $admin = $this->adminWithSettings('Tenant');

        $this->actingAs($admin)
            ->withHeader('Accept', 'application/json')
            ->post('/api/v1/business-settings', [
                ...$this->validPayload(),
                '_method' => 'PUT',
                'logo' => UploadedFile::fake()->create('brand.pdf', 100, 'application/pdf'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('logo');

        Storage::disk('public')->assertDirectoryEmpty("business-logos/{$admin->organization_id}");
    }

    public function test_replacing_and_removing_an_unreferenced_logo_cleans_up_managed_files(): void
    {
        Storage::fake('public');
        $admin = $this->adminWithSettings('Tenant');
        $settings = $admin->organization->businessSetting;
        $oldPath = "business-logos/{$admin->organization_id}/old.png";
        Storage::disk('public')->put($oldPath, 'old');
        $settings->update(['logo_path' => $oldPath]);

        $replacement = $this->actingAs($admin)
            ->withHeader('Accept', 'application/json')
            ->post('/api/v1/business-settings', [
                ...$this->validPayload(),
                '_method' => 'PUT',
                'logo' => UploadedFile::fake()->image('replacement.webp'),
            ])
            ->assertOk();

        $newPath = $replacement->json('logo_path');
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($newPath);

        $this->actingAs($admin)
            ->withHeader('Accept', 'application/json')
            ->post('/api/v1/business-settings', [
                ...$this->validPayload(),
                '_method' => 'PUT',
                'remove_logo' => true,
            ])
            ->assertOk()
            ->assertJsonPath('logo_path', null)
            ->assertJsonPath('logo_url', null);

        Storage::disk('public')->assertMissing($newPath);
    }

    public function test_logo_referenced_by_a_historical_quotation_is_not_deleted(): void
    {
        Storage::fake('public');
        $admin = $this->adminWithSettings('Tenant');
        $oldPath = "business-logos/{$admin->organization_id}/historical.png";
        Storage::disk('public')->put($oldPath, 'historical');
        $admin->organization->businessSetting->update(['logo_path' => $oldPath]);
        $booking = Booking::factory()->create([
            'organization_id' => $admin->organization_id,
            'created_by' => $admin->id,
        ]);
        Quotation::factory()->forBooking($booking)->cancelled()->create([
            'business_logo_path' => $oldPath,
        ]);

        $this->actingAs($admin)
            ->withHeader('Accept', 'application/json')
            ->post('/api/v1/business-settings', [
                ...$this->validPayload(),
                '_method' => 'PUT',
                'remove_logo' => true,
            ])
            ->assertOk()
            ->assertJsonPath('logo_path', null);

        Storage::disk('public')->assertExists($oldPath);
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
            ->assertUnprocessable()
            ->assertJsonValidationErrors('organization_id');

        $this->assertDatabaseHas('business_settings', [
            'organization_id' => $admin->organization_id,
            'display_name' => 'Tenant A',
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
            'theme_accent' => ThemeAccent::Plum->value,
            'booking_prefix' => 'BK',
            'quotation_prefix' => 'QT',
            'billing_prefix' => 'INV',
        ];
    }
}
