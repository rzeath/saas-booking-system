<?php

namespace Tests\Feature\Billings;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Models\Billing;
use App\Models\BillingItem;
use App\Models\Booking;
use App\Models\EventType;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\User;
use App\Support\Documents\BillingPdfPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BillingPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_owner_can_download_pdf_with_safe_filename(): void
    {
        [$user, $organization] = $this->tenant();
        $billing = $this->billing($organization, $user, [
            'billing_number' => 'INV 2027/000001',
        ]);
        BillingItem::factory()->forBilling($billing)->create();
        Payment::factory()->forBilling($billing)->create();

        $this->get("/api/v1/billings/{$billing->id}/pdf")
            ->assertUnauthorized();

        $response = $this->actingAs($user)
            ->get("/api/v1/billings/{$billing->id}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertDownload('INV-2027-000001.pdf');

        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_foreign_tenant_receives_not_found(): void
    {
        [$owner, $organization] = $this->tenant();
        [$foreignUser] = $this->tenant();
        $billing = $this->billing($organization, $owner);

        $this->actingAs($foreignUser)
            ->get("/api/v1/billings/{$billing->id}/pdf")
            ->assertNotFound();
    }

    public function test_document_uses_billing_snapshots_exact_peso_totals_and_only_posted_payments(): void
    {
        [$user, $organization] = $this->tenant();
        $billing = $this->billing($organization, $user, [
            'billing_number' => 'INV-2027-000321',
            'quotation_number' => 'QT-2027-000123',
            'business_display_name' => 'Snapshot Events Studio',
            'business_email' => 'billing@snapshot.test',
            'business_phone' => '09170000000',
            'business_address' => 'Snapshot Business Address',
            'customer_name' => 'Snapshot Customer',
            'customer_email' => 'customer@snapshot.test',
            'customer_phone' => '09171111111',
            'customer_address' => 'Snapshot Customer Address',
            'event_type_name' => 'Wedding',
            'event_name' => 'Snapshot Wedding Reception',
            'event_date' => '2027-06-15',
            'venue_name' => 'Snapshot Ballroom',
            'venue_address' => 'Snapshot Venue Address',
            'contact_person' => 'Snapshot Contact',
            'contact_number' => '09172222222',
            'subtotal' => '8000.00',
            'transportation_fee' => '100.10',
            'crew_meal_fee' => '50.20',
            'discount_amount' => '25.05',
            'total' => '8125.25',
            'created_at' => '2027-05-01 09:00:00',
        ]);
        BillingItem::factory()->forBilling($billing)->create([
            'service_name' => 'Snapshot Mirror Booth',
            'package_name' => 'Snapshot Celebration Package',
            'start_at' => '2027-06-15 22:00:00',
            'end_at' => '2027-06-16 01:00:00',
            'duration_minutes' => 180,
            'quantity' => 2,
            'unit_rate' => '4000.00',
            'line_total' => '8000.00',
        ]);
        Payment::factory()->forBilling($billing)->create([
            'amount' => '2000.10',
            'paid_at' => '2027-05-03 14:30:00',
            'payment_method' => PaymentMethod::GCash,
            'reference_number' => 'POSTED-GCASH-001',
        ]);
        Payment::factory()->forBilling($billing)->voided($user)->create([
            'amount' => '5000.00',
            'payment_method' => PaymentMethod::BankTransfer,
            'reference_number' => 'VOIDED-REFERENCE-SECRET',
        ]);

        $billing->booking->update([
            'customer_name' => 'Changed Current Customer',
            'event_name' => 'Changed Current Event',
            'venue_name' => 'Changed Current Venue',
            'start_at' => '2027-07-20 09:00:00',
        ]);
        $billing->booking->customer->update(['name' => 'Changed Master Customer']);
        $billing->booking->eventType->update(['name' => 'Changed Master Event Type']);
        $billing->quotation->update([
            'business_display_name' => 'Changed Quotation Business',
            'customer_name' => 'Changed Quotation Customer',
        ]);
        $organization->businessSetting->update([
            'display_name' => 'Changed Current Business',
            'email' => 'changed-current@example.test',
        ]);

        $data = $this->presented($billing);
        $html = view('pdf.billing', $data)->render();

        $this->assertStringContainsString('BILLING', $html);
        $this->assertStringContainsString('INV-2027-000321', $html);
        $this->assertStringContainsString('QT-2027-000123', $html);
        $this->assertStringContainsString('Snapshot Events Studio', $html);
        $this->assertStringContainsString('Snapshot Customer', $html);
        $this->assertStringContainsString('Snapshot Wedding Reception', $html);
        $this->assertStringContainsString('Snapshot Ballroom', $html);
        $this->assertStringContainsString('Snapshot Mirror Booth', $html);
        $this->assertStringContainsString('Snapshot Celebration Package', $html);
        $this->assertStringContainsString('Jun 15, 2027 · 10:00 PM - Jun 16, 2027 · 1:00 AM', $html);
        $this->assertStringContainsString('POSTED-GCASH-001', $html);
        $this->assertStringContainsString('₱8,125.25', $html);
        $this->assertStringContainsString('₱2,000.10', $html);
        $this->assertStringContainsString('₱6,125.15', $html);
        $this->assertStringContainsString('Partially Paid', $html);
        $this->assertStringNotContainsString('VOIDED-REFERENCE-SECRET', $html);
        $this->assertStringNotContainsString('Changed Current Customer', $html);
        $this->assertStringNotContainsString('Changed Current Event', $html);
        $this->assertStringNotContainsString('Changed Current Venue', $html);
        $this->assertStringNotContainsString('Changed Master Customer', $html);
        $this->assertStringNotContainsString('Changed Master Event Type', $html);
        $this->assertStringNotContainsString('Changed Quotation Business', $html);
        $this->assertStringNotContainsString('Changed Quotation Customer', $html);
        $this->assertStringNotContainsString('Changed Current Business', $html);
        $this->assertStringNotContainsString('PHP', $html);
        $this->assertCount(1, $data['postedPayments']);
        $this->assertArrayNotHasKey('currency', $data);
    }

    public function test_paid_and_unpaid_derived_states_render_safely(): void
    {
        [$user, $organization] = $this->tenant();
        $paidBilling = $this->billing($organization, $user);
        BillingItem::factory()->forBilling($paidBilling)->create();
        Payment::factory()->forBilling($paidBilling)->create(['amount' => '8000.00']);

        $paidHtml = $this->renderedDocument($paidBilling);
        $this->assertStringContainsString('<div class="status">Paid</div>', $paidHtml);
        $this->assertStringContainsString('<th>Remaining Balance</th><td>₱0.00</td>', $paidHtml);

        $unpaidBilling = $this->billing($organization, $user, [
            'billing_number' => 'INV-2027-UNPAID',
        ]);
        BillingItem::factory()->forBilling($unpaidBilling)->create();

        $unpaidHtml = $this->renderedDocument($unpaidBilling);
        $this->assertStringContainsString('<div class="status">Unpaid</div>', $unpaidHtml);
        $this->assertStringContainsString('No posted payments are currently applied to this Billing.', $unpaidHtml);
        $this->assertStringContainsString('<th>Amount Paid</th><td>₱0.00</td>', $unpaidHtml);
    }

    #[DataProvider('managedLogoFormats')]
    public function test_historical_managed_logo_is_embedded_for_supported_formats(
        string $extension,
        string $mimeType,
    ): void {
        Storage::fake('public');
        [$user, $organization] = $this->tenant();
        $path = "business-logos/{$organization->id}/billing-logo.{$extension}";
        Storage::disk('public')->put(
            $path,
            UploadedFile::fake()->image("billing-logo.{$extension}", 320, 160)->getContent(),
        );
        $billing = $this->billing($organization, $user, [
            'business_logo_path' => $path,
        ]);

        $presented = $this->presented($billing);

        $this->assertStringStartsWith("data:{$mimeType};base64,", $presented['logoDataUri']);

        $organization->businessSetting->update(['logo_path' => null]);

        $this->assertSame($path, $billing->fresh()->business_logo_path);
        $this->assertSame(
            $presented['logoDataUri'],
            $this->presented($billing->fresh())['logoDataUri'],
        );
    }

    public function test_invalid_or_missing_historical_logo_uses_business_name_fallback(): void
    {
        Storage::fake('public');
        [$user, $organization] = $this->tenant();
        [, $foreignOrganization] = $this->tenant();
        $foreignPath = "business-logos/{$foreignOrganization->id}/foreign-logo.png";
        Storage::disk('public')->put(
            $foreignPath,
            UploadedFile::fake()->image('foreign-logo.png')->getContent(),
        );
        $billing = $this->billing($organization, $user, [
            'business_display_name' => 'Historical Name Fallback',
            'business_logo_path' => $foreignPath,
        ]);

        $presented = $this->presented($billing);
        $html = view('pdf.billing', $presented)->render();

        $this->assertNull($presented['logoDataUri']);
        $this->assertStringContainsString('Historical Name Fallback', $html);
        $this->assertStringNotContainsString('<img', $html);

        $billing->update([
            'business_logo_path' => "business-logos/{$organization->id}/missing.png",
        ]);

        $this->actingAs($user)
            ->get("/api/v1/billings/{$billing->id}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_many_items_and_payments_render_into_a_multi_page_pdf(): void
    {
        [$user, $organization] = $this->tenant();
        $billing = $this->billing($organization, $user, [
            'subtotal' => '4500.00',
            'transportation_fee' => '0.00',
            'crew_meal_fee' => '0.00',
            'discount_amount' => '0.00',
            'total' => '4500.00',
        ]);

        foreach (range(1, 45) as $position) {
            BillingItem::factory()->forBilling($billing)->create([
                'service_name' => "Snapshot Service {$position}",
                'unit_rate' => '100.00',
                'line_total' => '100.00',
                'sort_order' => $position,
            ]);
        }

        foreach (range(1, 30) as $position) {
            Payment::factory()->forBilling($billing)->create([
                'amount' => '1.00',
                'paid_at' => "2027-05-03 14:{$position}:00",
                'reference_number' => "PAYMENT-{$position}",
            ]);
        }

        $html = $this->renderedDocument($billing);
        $this->assertStringContainsString('Snapshot Service 1', $html);
        $this->assertStringContainsString('Snapshot Service 45', $html);
        $this->assertStringContainsString('PAYMENT-1', $html);
        $this->assertStringContainsString('PAYMENT-30', $html);

        $content = $this->actingAs($user)
            ->get("/api/v1/billings/{$billing->id}/pdf")
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

    /** @param array<string, mixed> $attributes */
    private function billing(Organization $organization, User $user, array $attributes = []): Billing
    {
        $eventType = EventType::factory()->create([
            'organization_id' => $organization->id,
            'name' => fake()->unique()->numerify('Billing PDF Event Type ######'),
        ]);
        $booking = Booking::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $user->id,
            'event_type_id' => $eventType->id,
            'event_type_name' => $eventType->name,
            'status' => BookingStatus::Confirmed,
        ]);
        $quotation = Quotation::factory()->accepted()->forBooking($booking)->create();

        return Billing::factory()->forQuotation($quotation)->create($attributes);
    }

    /** @return array<string, mixed> */
    private function presented(Billing $billing): array
    {
        return app(BillingPdfPresenter::class)->present($billing->fresh(['items', 'payments']));
    }

    private function renderedDocument(Billing $billing): string
    {
        return view('pdf.billing', $this->presented($billing))->render();
    }
}
