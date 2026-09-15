<?php

namespace Tests\Feature\Quotations;

use App\Actions\Quotations\CreateQuotation;
use App\Actions\Quotations\UpdateDraftQuotation;
use App\Enums\BookingStatus;
use App\Enums\QuotationStatus;
use App\Models\Booking;
use App\Models\BookingService;
use App\Models\BusinessSetting;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\ServiceRate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QuotationDraftActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_booking_creates_a_numbered_draft_with_all_items_and_exact_totals(): void
    {
        [$user, $organization] = $this->tenant();
        [$booking, $lines] = $this->bookingWithServices($organization, $user);
        $this->travelTo(CarbonImmutable::parse('2027-04-10 12:00:00', 'UTC'));

        $quotation = app(CreateQuotation::class)->handle($user, $booking->id, [
            'transportation_fee' => '100.10',
            'crew_meal_fee' => '50.20',
            'discount_amount' => '25.05',
            'valid_until' => '2027-05-31',
        ]);

        $this->assertSame(QuotationStatus::Draft, $quotation->status);
        $this->assertSame('QT-2027-000001', $quotation->quotation_number);
        $this->assertSame($organization->id, $quotation->organization_id);
        $this->assertSame($user->id, $quotation->created_by);
        $this->assertSame('2500.25', $quotation->subtotal);
        $this->assertSame('100.10', $quotation->transportation_fee);
        $this->assertSame('50.20', $quotation->crew_meal_fee);
        $this->assertSame('25.05', $quotation->discount_amount);
        $this->assertSame('2625.50', $quotation->total);
        $this->assertSame('2027-05-31', $quotation->valid_until->format('Y-m-d'));
        $this->assertCount(2, $quotation->items);
        $this->assertSame([
            $lines[1]->id,
            $lines[0]->id,
        ], $quotation->items->pluck('booking_service_id')->all());
        $this->assertDatabaseHas('document_sequences', [
            'organization_id' => $organization->id,
            'document_type' => 'QUOTATION',
            'year' => 2027,
            'next_number' => 2,
        ]);
    }

    public function test_item_snapshots_come_only_from_saved_booking_services_after_master_data_changes(): void
    {
        [$user, $organization] = $this->tenant();
        [$booking, $lines] = $this->bookingWithServices($organization, $user);
        $source = $lines[0];
        $rate = ServiceRate::factory()->forCombination(
            $booking->eventType,
            $source->service,
            $source->package,
        )->create([
            'duration_minutes' => $source->duration_minutes,
            'unit_rate' => '9999.99',
        ]);

        $source->service->update(['name' => 'Current Master Service']);
        $source->package->update(['name' => 'Current Master Package']);
        $rate->update(['unit_rate' => '1.00']);

        $quotation = app(CreateQuotation::class)->handle($user, $booking->id);
        $item = $quotation->items->firstWhere('booking_service_id', $source->id);

        $this->assertSame('Stored Service 1', $item->service_name);
        $this->assertSame('Stored Package 1', $item->package_name);
        $this->assertSame('2027-06-15 18:00:00', $item->start_at->format('Y-m-d H:i:s'));
        $this->assertSame('2027-06-15 20:00:00', $item->end_at->format('Y-m-d H:i:s'));
        $this->assertSame(120, $item->duration_minutes);
        $this->assertSame(2, $item->quantity);
        $this->assertSame('1000.10', $item->unit_rate);
        $this->assertSame('2000.20', $item->line_total);
        $this->assertSame(1, $item->sort_order);
    }

    public function test_header_snapshots_come_from_booking_and_tenant_business_settings(): void
    {
        [$user, $organization, $settings] = $this->tenant([
            'display_name' => 'Snapshot Seller',
            'email' => 'seller@example.test',
            'phone' => '+63 917 111 2222',
            'address' => 'Seller Address',
            'logo_path' => 'logos/snapshot-v1.png',
            'currency' => 'PHP',
        ]);
        [$booking] = $this->bookingWithServices($organization, $user);
        $booking->customer->update([
            'name' => 'Current Customer',
            'email' => 'current@example.test',
        ]);
        $booking->eventType->update(['name' => 'Current Event Type']);

        $quotation = app(CreateQuotation::class)->handle($user, $booking->id);

        $this->assertSame($settings->display_name, $quotation->business_display_name);
        $this->assertSame($settings->email, $quotation->business_email);
        $this->assertSame($settings->phone, $quotation->business_phone);
        $this->assertSame($settings->address, $quotation->business_address);
        $this->assertSame($settings->logo_path, $quotation->business_logo_path);
        $this->assertSame('PHP', $quotation->currency);
        $this->assertSame('Booked Customer', $quotation->customer_name);
        $this->assertSame('booked@example.test', $quotation->customer_email);
        $this->assertSame('Booked Event Type', $quotation->event_type_name);
        $this->assertSame('Booked Occasion', $quotation->event_name);
        $this->assertSame('2027-06-15', $quotation->event_date->format('Y-m-d'));
        $this->assertSame('Booked Venue', $quotation->venue_name);
        $this->assertSame('Booked Contact', $quotation->contact_person);
    }

    public function test_creation_boundary_ignores_items_identity_number_status_and_calculated_totals(): void
    {
        [$user, $organization] = $this->tenant();
        [$booking] = $this->bookingWithServices($organization, $user);

        $quotation = app(CreateQuotation::class)->handle($user, $booking->id, [
            'organization_id' => 999999,
            'quotation_number' => 'CLIENT-NUMBER',
            'status' => QuotationStatus::Accepted->value,
            'items' => [['line_total' => '0.01']],
            'subtotal' => '0.01',
            'total' => '0.01',
        ]);

        $this->assertSame($organization->id, $quotation->organization_id);
        $this->assertStringStartsWith('QT-', $quotation->quotation_number);
        $this->assertSame(QuotationStatus::Draft, $quotation->status);
        $this->assertSame('2500.25', $quotation->subtotal);
        $this->assertSame('2500.25', $quotation->total);
        $this->assertCount(2, $quotation->items);
    }

    public function test_foreign_tenant_booking_cannot_be_quoted(): void
    {
        [$owner, $organization] = $this->tenant();
        [$foreignUser, $foreignOrganization] = $this->tenant();
        [$booking] = $this->bookingWithServices($organization, $owner);

        $this->expectException(ModelNotFoundException::class);

        app(CreateQuotation::class)->handle($foreignUser, $booking->id);
    }

    #[DataProvider('nonPendingStatuses')]
    public function test_non_pending_booking_cannot_create_a_draft(string $status): void
    {
        [$user, $organization] = $this->tenant();
        [$booking] = $this->bookingWithServices($organization, $user);
        $booking->update(['status' => $status]);

        try {
            app(CreateQuotation::class)->handle($user, $booking->id);
            $this->fail('A non-pending Booking was quoted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertDatabaseCount('quotations', 0);
    }

    /** @return array<string, array{string}> */
    public static function nonPendingStatuses(): array
    {
        return [
            'quoted' => [BookingStatus::Quoted->value],
            'confirmed' => [BookingStatus::Confirmed->value],
            'completed' => [BookingStatus::Completed->value],
            'cancelled' => [BookingStatus::Cancelled->value],
        ];
    }

    public function test_second_active_draft_is_rejected_without_consuming_a_number(): void
    {
        [$user, $organization] = $this->tenant();
        [$booking] = $this->bookingWithServices($organization, $user);
        app(CreateQuotation::class)->handle($user, $booking->id);

        try {
            app(CreateQuotation::class)->handle($user, $booking->id);
            $this->fail('A second active quotation was created.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quotation', $exception->errors());
        }

        $this->assertDatabaseCount('quotations', 1);
        $this->assertDatabaseHas('document_sequences', [
            'organization_id' => $organization->id,
            'document_type' => 'QUOTATION',
            'next_number' => 2,
        ]);
    }

    public function test_accepted_quotation_prevents_a_new_draft(): void
    {
        [$user, $organization] = $this->tenant();
        [$booking] = $this->bookingWithServices($organization, $user);
        Quotation::factory()->accepted()->forBooking($booking)->create();

        try {
            app(CreateQuotation::class)->handle($user, $booking->id);
            $this->fail('A Booking with an accepted quotation received another Draft.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'This Booking already has an accepted quotation.',
                $exception->errors()['quotation'][0],
            );
        }

        $this->assertDatabaseCount('quotations', 1);
    }

    public function test_sent_quotation_prevents_a_new_draft(): void
    {
        [$user, $organization] = $this->tenant();
        [$booking] = $this->bookingWithServices($organization, $user);
        Quotation::factory()->sent()->forBooking($booking)->create();

        try {
            app(CreateQuotation::class)->handle($user, $booking->id);
            $this->fail('A Booking with a Sent quotation received another Draft.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'This Booking already has an active quotation.',
                $exception->errors()['quotation'][0],
            );
        }

        $this->assertDatabaseCount('quotations', 1);
    }

    public function test_creation_rejects_negative_adjustments_before_allocating_a_number(): void
    {
        [$user, $organization] = $this->tenant();
        [$booking] = $this->bookingWithServices($organization, $user);

        try {
            app(CreateQuotation::class)->handle($user, $booking->id, [
                'transportation_fee' => '-0.01',
            ]);
            $this->fail('A Draft was created with a negative adjustment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('transportation_fee', $exception->errors());
        }

        $this->assertDatabaseCount('quotations', 0);
        $this->assertDatabaseMissing('document_sequences', [
            'organization_id' => $organization->id,
            'document_type' => 'QUOTATION',
        ]);
    }

    public function test_creation_is_atomic_when_an_item_snapshot_cannot_be_persisted(): void
    {
        [$user, $organization] = $this->tenant();
        [$booking, $lines] = $this->bookingWithServices($organization, $user);
        DB::table('booking_services')->where('id', $lines[0]->id)->update(['line_total' => '0.01']);

        try {
            app(CreateQuotation::class)->handle($user, $booking->id);
            $this->fail('An invalid quotation item was persisted.');
        } catch (QueryException) {
            $this->assertDatabaseCount('quotations', 0);
            $this->assertDatabaseCount('quotation_items', 0);
            $this->assertDatabaseMissing('document_sequences', [
                'organization_id' => $organization->id,
                'document_type' => 'QUOTATION',
            ]);
        }
    }

    public function test_draft_fees_and_discount_recalculate_total_exactly(): void
    {
        [$user, $organization] = $this->tenant();
        [$booking] = $this->bookingWithServices($organization, $user);
        $quotation = app(CreateQuotation::class)->handle($user, $booking->id);

        $updated = app(UpdateDraftQuotation::class)->handle($user, $quotation->id, [
            'transportation_fee' => '100.10',
            'crew_meal_fee' => '50.20',
            'discount_amount' => '25.05',
        ]);

        $this->assertSame('2500.25', $updated->subtotal);
        $this->assertSame('100.10', $updated->transportation_fee);
        $this->assertSame('50.20', $updated->crew_meal_fee);
        $this->assertSame('25.05', $updated->discount_amount);
        $this->assertSame('2625.50', $updated->total);
    }

    public function test_draft_valid_until_can_be_set_and_cleared(): void
    {
        [$user, $organization] = $this->tenant();
        [$booking] = $this->bookingWithServices($organization, $user);
        $quotation = app(CreateQuotation::class)->handle($user, $booking->id);

        $dated = app(UpdateDraftQuotation::class)->handle($user, $quotation->id, [
            'valid_until' => '2027-05-31',
        ]);
        $this->assertSame('2027-05-31', $dated->valid_until->format('Y-m-d'));

        $cleared = app(UpdateDraftQuotation::class)->handle($user, $quotation->id, [
            'valid_until' => null,
        ]);
        $this->assertNull($cleared->valid_until);
    }

    #[DataProvider('negativeAdjustmentFields')]
    public function test_negative_draft_adjustments_are_rejected(string $field): void
    {
        [$user, $organization] = $this->tenant();
        [$booking] = $this->bookingWithServices($organization, $user);
        $quotation = app(CreateQuotation::class)->handle($user, $booking->id);

        try {
            app(UpdateDraftQuotation::class)->handle($user, $quotation->id, [
                $field => '-0.01',
            ]);
            $this->fail('A negative quotation adjustment was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }

        $this->assertSame('2500.25', $quotation->fresh()->total);
    }

    /** @return array<string, array{string}> */
    public static function negativeAdjustmentFields(): array
    {
        return [
            'transportation fee' => ['transportation_fee'],
            'crew meal fee' => ['crew_meal_fee'],
            'discount' => ['discount_amount'],
        ];
    }

    public function test_discount_cannot_make_the_draft_total_zero_or_negative(): void
    {
        [$user, $organization] = $this->tenant();
        [$booking] = $this->bookingWithServices($organization, $user);
        $quotation = app(CreateQuotation::class)->handle($user, $booking->id);

        $this->expectException(ValidationException::class);

        app(UpdateDraftQuotation::class)->handle($user, $quotation->id, [
            'discount_amount' => '2500.25',
        ]);
    }

    public function test_draft_update_does_not_rebuild_items_or_accept_calculated_fields(): void
    {
        [$user, $organization] = $this->tenant();
        [$booking] = $this->bookingWithServices($organization, $user);
        $quotation = app(CreateQuotation::class)->handle($user, $booking->id);
        $before = $quotation->items->map->getAttributes()->all();
        $booking->bookingServices()->first()->update(['service_name' => 'Changed Booking Snapshot']);

        $updated = app(UpdateDraftQuotation::class)->handle($user, $quotation->id, [
            'items' => [['service_name' => 'Client Item']],
            'subtotal' => '0.01',
            'total' => '0.01',
            'transportation_fee' => '10.00',
        ]);

        $this->assertSame('2500.25', $updated->subtotal);
        $this->assertSame('2510.25', $updated->total);
        $this->assertSame($before, $updated->items->map->getAttributes()->all());
    }

    #[DataProvider('nonDraftQuotationStatuses')]
    public function test_non_draft_quotation_cannot_be_edited(string $status): void
    {
        [$user, $organization] = $this->tenant();
        [$booking] = $this->bookingWithServices($organization, $user);
        $quotation = app(CreateQuotation::class)->handle($user, $booking->id);
        $quotation->update(['status' => $status]);

        try {
            app(UpdateDraftQuotation::class)->handle($user, $quotation->id, [
                'transportation_fee' => '10.00',
            ]);
            $this->fail('A non-Draft quotation was edited.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }
    }

    /** @return array<string, array{string}> */
    public static function nonDraftQuotationStatuses(): array
    {
        return [
            'sent' => [QuotationStatus::Sent->value],
            'accepted' => [QuotationStatus::Accepted->value],
            'rejected' => [QuotationStatus::Rejected->value],
            'cancelled' => [QuotationStatus::Cancelled->value],
            'expired' => [QuotationStatus::Expired->value],
            'outdated' => [QuotationStatus::Outdated->value],
        ];
    }

    public function test_foreign_tenant_draft_cannot_be_edited(): void
    {
        [$user, $organization] = $this->tenant();
        [$foreignUser] = $this->tenant();
        [$booking] = $this->bookingWithServices($organization, $user);
        $quotation = app(CreateQuotation::class)->handle($user, $booking->id);

        $this->expectException(ModelNotFoundException::class);

        app(UpdateDraftQuotation::class)->handle($foreignUser, $quotation->id, [
            'transportation_fee' => '10.00',
        ]);
    }

    /** @return array{User, Organization, BusinessSetting} */
    private function tenant(array $settings = []): array
    {
        $organization = Organization::factory()->create();
        $businessSettings = BusinessSetting::factory()->for($organization)->create($settings);
        $user = User::factory()->for($organization)->create();

        return [$user, $organization, $businessSettings];
    }

    /** @return array{Booking, list<BookingService>} */
    private function bookingWithServices(Organization $organization, User $user): array
    {
        $booking = Booking::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $user->id,
            'customer_name' => 'Booked Customer',
            'customer_email' => 'booked@example.test',
            'customer_phone' => '+63 917 000 0000',
            'customer_address' => 'Booked Customer Address',
            'event_type_name' => 'Booked Event Type',
            'event_name' => 'Booked Occasion',
            'event_date' => '2027-06-15',
            'venue_name' => 'Booked Venue',
            'venue_address' => 'Booked Venue Address',
            'contact_person' => 'Booked Contact',
            'contact_number' => '+63 917 999 9999',
        ]);
        $first = BookingService::factory()->forBooking($booking)->create([
            'service_name' => 'Stored Service 1',
            'package_name' => 'Stored Package 1',
            'start_at' => '2027-06-15 18:00:00',
            'end_at' => '2027-06-15 20:00:00',
            'duration_minutes' => 120,
            'quantity' => 2,
            'unit_rate' => '1000.10',
            'line_total' => '2000.20',
            'sort_order' => 1,
        ]);
        $second = BookingService::factory()
            ->forBooking($booking)
            ->forPackage($first->package, $first->service)
            ->create([
                'service_name' => 'Stored Service 2',
                'package_name' => 'Stored Package 2',
                'start_at' => '2027-06-15 17:00:00',
                'end_at' => '2027-06-15 18:00:00',
                'duration_minutes' => 60,
                'quantity' => 1,
                'unit_rate' => '500.05',
                'line_total' => '500.05',
                'sort_order' => 0,
            ]);

        return [$booking, [$first, $second]];
    }
}
