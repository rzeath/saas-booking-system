<?php

namespace Tests\Feature\Quotations;

use App\Actions\BusinessSettings\UpdateBusinessSettings;
use App\Actions\Quotations\CreateQuotation;
use App\Models\Booking;
use App\Models\BookingService;
use App\Models\EventType;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Support\Documents\QuotationPdfPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QuotationPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_owner_can_download_pdf_with_safe_filename(): void
    {
        [$user, $organization] = $this->tenant();
        $quotation = $this->quotation($organization, $user, [
            'quotation_number' => 'QT 2027/000001',
        ]);
        QuotationItem::factory()->forQuotation($quotation)->create();

        $this->get("/api/v1/quotations/{$quotation->id}/pdf")
            ->assertUnauthorized();

        $response = $this->actingAs($user)
            ->get("/api/v1/quotations/{$quotation->id}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertDownload('QT-2027-000001.pdf');

        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_foreign_tenant_receives_not_found(): void
    {
        [$owner, $organization] = $this->tenant();
        [$foreignUser] = $this->tenant();
        $quotation = $this->quotation($organization, $owner);

        $this->actingAs($foreignUser)
            ->get("/api/v1/quotations/{$quotation->id}/pdf")
            ->assertNotFound();
    }

    public function test_document_uses_immutable_snapshots_exact_peso_money_and_manila_dates(): void
    {
        [$user, $organization] = $this->tenant();
        $booking = $this->booking($organization, $user);
        $source = BookingService::factory()->forBooking($booking)->create([
            'service_name' => 'Snapshot Mirror Booth',
            'package_name' => 'Snapshot Celebration Package',
            'start_at' => '2027-06-15 22:00:00',
            'end_at' => '2027-06-16 01:00:00',
            'duration_minutes' => 180,
            'quantity' => 2,
            'unit_rate' => '4000.00',
            'line_total' => '8000.00',
        ]);
        $quotation = Quotation::factory()->forBooking($booking)->create([
            'quotation_number' => 'QT-2027-000321',
            'business_display_name' => 'Snapshot Events Studio',
            'business_email' => 'hello@snapshot.test',
            'customer_name' => 'Snapshot Customer',
            'event_name' => 'Snapshot Wedding Reception',
            'event_type_name' => 'Wedding',
            'event_date' => '2027-06-15',
            'venue_name' => 'Snapshot Ballroom',
            'subtotal' => '8000.00',
            'transportation_fee' => '100.10',
            'crew_meal_fee' => '50.20',
            'discount_amount' => '25.05',
            'total' => '8125.25',
            'created_at' => '2027-05-01 09:00:00',
            'valid_until' => '2027-05-31',
        ]);
        QuotationItem::factory()->fromBookingService($quotation, $source)->create();

        $booking->customer->update(['name' => 'Changed Current Customer']);
        $source->service->update(['name' => 'Changed Current Service']);
        $source->package->update(['name' => 'Changed Current Package']);

        $html = $this->renderedDocument($quotation);

        $this->assertStringContainsString('QT-2027-000321', $html);
        $this->assertStringContainsString('Snapshot Events Studio', $html);
        $this->assertStringContainsString('Snapshot Customer', $html);
        $this->assertStringContainsString('Snapshot Wedding Reception', $html);
        $this->assertStringContainsString('Snapshot Mirror Booth', $html);
        $this->assertStringContainsString('Snapshot Celebration Package', $html);
        $this->assertStringContainsString('Jun 15, 2027 · 10:00 PM - Jun 16, 2027 · 1:00 AM', $html);
        $this->assertStringContainsString('₱8,125.25', $html);
        $this->assertStringContainsString('May 31, 2027', $html);
        $this->assertStringNotContainsString('Changed Current Customer', $html);
        $this->assertStringNotContainsString('Changed Current Service', $html);
        $this->assertStringNotContainsString('Changed Current Package', $html);
        $this->assertStringNotContainsString('PHP', $html);
        $this->assertArrayNotHasKey('currency', app(QuotationPdfPresenter::class)->present($quotation->fresh('items')));
    }

    public function test_historical_logo_is_embedded_and_missing_logo_is_ignored(): void
    {
        Storage::fake('public');
        [$user, $organization] = $this->tenant();
        $path = "business-logos/{$organization->id}/quotation-logo.png";
        Storage::disk('public')->put($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        ));
        $quotation = $this->quotation($organization, $user, ['business_logo_path' => $path]);
        QuotationItem::factory()->forQuotation($quotation)->create();

        $presented = app(QuotationPdfPresenter::class)->present($quotation->fresh('items'));
        $this->assertStringStartsWith('data:image/png;base64,', $presented['logoDataUri']);

        $quotation->update(['business_logo_path' => "business-logos/{$organization->id}/missing.png"]);
        $this->actingAs($user)
            ->get("/api/v1/quotations/{$quotation->id}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    #[DataProvider('managedLogoFormats')]
    public function test_new_quotation_snapshots_and_embeds_configured_managed_logo(
        string $extension,
        string $mimeType,
    ): void {
        Storage::fake('public');
        [$user, $organization] = $this->tenant();
        $settings = $organization->businessSetting;
        $settings = app(UpdateBusinessSettings::class)->handle(
            $settings,
            [],
            UploadedFile::fake()->image("configured-logo.{$extension}", 320, 160),
            false,
        );
        $snapshotPath = $settings->logo_path;
        $booking = $this->booking($organization, $user);
        BookingService::factory()->forBooking($booking)->create();

        $quotation = app(CreateQuotation::class)->handle($user, $booking->id);
        $beforeSettingsChange = app(QuotationPdfPresenter::class)->present($quotation);

        $this->assertSame($snapshotPath, $quotation->business_logo_path);
        $this->assertStringStartsWith("data:{$mimeType};base64,", $beforeSettingsChange['logoDataUri']);

        $settings = app(UpdateBusinessSettings::class)->handle(
            $settings,
            [],
            UploadedFile::fake()->image('replacement-logo.png', 160, 160),
            false,
        );

        $this->assertNotSame($snapshotPath, $settings->logo_path);
        $this->assertSame($snapshotPath, $quotation->fresh()->business_logo_path);
        Storage::disk('public')->assertExists($snapshotPath);
        $this->assertSame(
            $beforeSettingsChange['logoDataUri'],
            app(QuotationPdfPresenter::class)->present($quotation->fresh('items'))['logoDataUri'],
        );
    }

    public function test_quotation_created_without_logo_uses_business_name_fallback(): void
    {
        Storage::fake('public');
        [$user, $organization] = $this->tenant();
        $organization->businessSetting->update([
            'display_name' => 'No Logo Events',
            'logo_path' => null,
        ]);
        $booking = $this->booking($organization, $user);
        BookingService::factory()->forBooking($booking)->create();

        $quotation = app(CreateQuotation::class)->handle($user, $booking->id);
        $presented = app(QuotationPdfPresenter::class)->present($quotation);
        $html = view('pdf.quotation', $presented)->render();

        $this->assertNull($quotation->business_logo_path);
        $this->assertNull($presented['logoDataUri']);
        $this->assertStringContainsString('No Logo Events', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_pdf_presenter_rejects_another_tenants_managed_logo_path(): void
    {
        Storage::fake('public');
        [$user, $organization] = $this->tenant();
        [, $foreignOrganization] = $this->tenant();
        $foreignPath = "business-logos/{$foreignOrganization->id}/foreign-logo.png";
        Storage::disk('public')->put(
            $foreignPath,
            UploadedFile::fake()->image('foreign-logo.png')->getContent(),
        );
        $quotation = $this->quotation($organization, $user, [
            'business_logo_path' => $foreignPath,
        ]);

        $this->assertNull(
            app(QuotationPdfPresenter::class)->present($quotation->fresh('items'))['logoDataUri'],
        );
    }

    public function test_outdated_and_accepted_quotations_remain_renderable(): void
    {
        [$user, $organization] = $this->tenant();
        $outdated = $this->quotation($organization, $user, [], 'outdated');
        $accepted = $this->quotation($organization, $user, [], 'accepted');
        QuotationItem::factory()->forQuotation($outdated)->create();
        QuotationItem::factory()->forQuotation($accepted)->create();

        $this->assertStringContainsString(
            'This quotation has been superseded by changes to the booking.',
            $this->renderedDocument($outdated),
        );
        $this->assertStringContainsString('<div class="status">Accepted</div>', $this->renderedDocument($accepted));

        $this->actingAs($user)->get("/api/v1/quotations/{$outdated->id}/pdf")->assertOk();
        $this->actingAs($user)->get("/api/v1/quotations/{$accepted->id}/pdf")->assertOk();
    }

    public function test_many_items_render_into_a_multi_page_pdf(): void
    {
        [$user, $organization] = $this->tenant();
        $quotation = $this->quotation($organization, $user);

        foreach (range(1, 45) as $position) {
            QuotationItem::factory()->forQuotation($quotation)->create([
                'service_name' => "Snapshot Service {$position}",
                'sort_order' => $position,
            ]);
        }

        $html = $this->renderedDocument($quotation);
        $this->assertStringContainsString('Snapshot Service 1', $html);
        $this->assertStringContainsString('Snapshot Service 45', $html);

        $content = $this->actingAs($user)
            ->get("/api/v1/quotations/{$quotation->id}/pdf")
            ->assertOk()
            ->getContent();

        preg_match_all('/\/Type\s*\/Page\b/', $content, $pages);
        $this->assertGreaterThan(1, count($pages[0]));
    }

    /** @return array{User, Organization} */
    private function tenant(): array
    {
        $organization = Organization::factory()->withBusinessSettings()->create();

        return [User::factory()->for($organization)->create(), $organization];
    }

    /** @return array<string, array{string, string}> */
    public static function managedLogoFormats(): array
    {
        return [
            'JPG' => ['jpg', 'image/jpeg'],
            'PNG' => ['png', 'image/png'],
            'WebP' => ['webp', 'image/webp'],
        ];
    }

    private function booking(Organization $organization, User $user): Booking
    {
        return Booking::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $user->id,
            'event_type_id' => EventType::factory()->create([
                'organization_id' => $organization->id,
                'name' => fake()->unique()->numerify('PDF Event Type ######'),
            ])->id,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function quotation(
        Organization $organization,
        User $user,
        array $attributes = [],
        ?string $state = null,
    ): Quotation {
        $factory = Quotation::factory()->forBooking($this->booking($organization, $user));

        if ($state !== null) {
            $factory = $factory->{$state}();
        }

        return $factory->create($attributes);
    }

    private function renderedDocument(Quotation $quotation): string
    {
        $data = app(QuotationPdfPresenter::class)->present($quotation->fresh('items'));

        return view('pdf.quotation', $data)->render();
    }
}
