<?php

namespace Tests\Feature\Quotations;

use App\Enums\DocumentType;
use App\Enums\QuotationStatus;
use App\Models\Booking;
use App\Models\BookingService;
use App\Models\BusinessSetting;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Support\DocumentNumbers\DocumentNumberAllocator;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class QuotationPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_quotation_status_enum_contains_the_approved_states(): void
    {
        $this->assertSame([
            'DRAFT',
            'SENT',
            'ACCEPTED',
            'REJECTED',
            'CANCELLED',
            'EXPIRED',
            'OUTDATED',
        ], array_column(QuotationStatus::cases(), 'value'));
    }

    public function test_quotation_schema_contains_document_snapshots_totals_and_slots(): void
    {
        $this->assertTrue(Schema::hasColumns('quotations', [
            'id',
            'organization_id',
            'booking_id',
            'quotation_number',
            'status',
            'valid_until',
            'sent_at',
            'accepted_at',
            'closed_at',
            'business_display_name',
            'business_email',
            'business_phone',
            'business_address',
            'business_logo_path',
            'customer_name',
            'customer_email',
            'customer_phone',
            'customer_address',
            'event_type_name',
            'event_name',
            'event_date',
            'venue_name',
            'venue_address',
            'contact_person',
            'contact_number',
            'currency',
            'subtotal',
            'transportation_fee',
            'crew_meal_fee',
            'discount_amount',
            'total',
            'active_slot',
            'accepted_slot',
            'created_by',
            'created_at',
            'updated_at',
        ]));
        $this->assertFalse(Schema::hasColumn('quotations', 'tax'));
        $this->assertFalse(Schema::hasColumn('quotations', 'notes'));
        $this->assertFalse(Schema::hasColumn('quotations', 'terms'));
    }

    public function test_quotation_item_schema_contains_immutable_commercial_snapshots(): void
    {
        $this->assertTrue(Schema::hasColumns('quotation_items', [
            'id',
            'quotation_id',
            'booking_id',
            'booking_service_id',
            'service_name',
            'package_name',
            'start_at',
            'end_at',
            'duration_minutes',
            'quantity',
            'unit_rate',
            'line_total',
            'sort_order',
            'created_at',
            'updated_at',
        ]));

        $sourceColumn = collect(Schema::getColumns('quotation_items'))
            ->firstWhere('name', 'booking_service_id');

        $this->assertTrue($sourceColumn['nullable']);
    }

    public function test_database_rejects_a_cross_tenant_booking_relationship(): void
    {
        $booking = Booking::factory()->create();
        $foreignOrganization = Organization::factory()->create();
        $foreignCreator = User::factory()->for($foreignOrganization)->create();

        $this->expectException(QueryException::class);

        Quotation::factory()->forBooking($booking)->create([
            'organization_id' => $foreignOrganization->id,
            'created_by' => $foreignCreator->id,
        ]);
    }

    public function test_booking_retains_multiple_historical_quotations(): void
    {
        $booking = Booking::factory()->create();

        Quotation::factory()->rejected()->forBooking($booking)->create();
        Quotation::factory()->cancelled()->forBooking($booking)->create();
        Quotation::factory()->expired()->forBooking($booking)->create();
        Quotation::factory()->outdated()->forBooking($booking)->create();

        $this->assertCount(4, $booking->quotations);
    }

    public function test_database_allows_only_one_combined_draft_or_sent_quotation_per_booking(): void
    {
        $booking = Booking::factory()->create();
        Quotation::factory()->forBooking($booking)->create();

        $this->expectException(QueryException::class);

        Quotation::factory()->sent()->forBooking($booking)->create();
    }

    public function test_database_allows_only_one_accepted_quotation_per_booking(): void
    {
        $booking = Booking::factory()->create();
        Quotation::factory()->accepted()->forBooking($booking)->create();

        $this->expectException(QueryException::class);

        Quotation::factory()->accepted()->forBooking($booking)->create();
    }

    public function test_quotation_numbers_are_unique_within_an_organization(): void
    {
        $organization = Organization::factory()->create();
        $firstBooking = Booking::factory()->create(['organization_id' => $organization->id]);
        $secondBooking = Booking::factory()->create([
            'organization_id' => $organization->id,
            'customer_id' => $firstBooking->customer_id,
            'event_type_id' => $firstBooking->event_type_id,
            'created_by' => $firstBooking->created_by,
        ]);
        Quotation::factory()->rejected()->forBooking($firstBooking)->create([
            'quotation_number' => 'QT-2027-000001',
        ]);

        $this->expectException(QueryException::class);

        Quotation::factory()->rejected()->forBooking($secondBooking)->create([
            'quotation_number' => 'QT-2027-000001',
        ]);
    }

    public function test_different_organizations_may_use_the_same_quotation_number(): void
    {
        $first = Quotation::factory()->rejected()->create([
            'quotation_number' => 'QT-2027-000001',
        ]);
        $second = Quotation::factory()->rejected()->create([
            'quotation_number' => 'QT-2027-000001',
        ]);

        $this->assertNotSame($first->organization_id, $second->organization_id);
    }

    public function test_money_fields_are_returned_as_exact_two_decimal_values(): void
    {
        $quotation = Quotation::factory()->create([
            'subtotal' => '1000.10',
            'transportation_fee' => '50.20',
            'crew_meal_fee' => '10.30',
            'discount_amount' => '0.60',
            'total' => '1060.00',
        ]);
        $item = QuotationItem::factory()->forQuotation($quotation)->create([
            'quantity' => 3,
            'unit_rate' => '333.33',
            'line_total' => '999.99',
        ]);

        $this->assertSame('1000.10', $quotation->fresh()->subtotal);
        $this->assertSame('50.20', $quotation->fresh()->transportation_fee);
        $this->assertSame('10.30', $quotation->fresh()->crew_meal_fee);
        $this->assertSame('0.60', $quotation->fresh()->discount_amount);
        $this->assertSame('1060.00', $quotation->fresh()->total);
        $this->assertSame('333.33', $item->fresh()->unit_rate);
        $this->assertSame('999.99', $item->fresh()->line_total);
    }

    public function test_database_rejects_an_inexact_quotation_total(): void
    {
        $this->expectException(QueryException::class);

        Quotation::factory()->create(['total' => '7999.99']);
    }

    public function test_database_rejects_an_inexact_quotation_item_total(): void
    {
        $quotation = Quotation::factory()->create();

        $this->expectException(QueryException::class);

        QuotationItem::factory()->forQuotation($quotation)->create([
            'line_total' => '7499.99',
        ]);
    }

    public function test_database_rejects_an_unknown_quotation_status(): void
    {
        $quotation = Quotation::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('quotations')->where('id', $quotation->id)->update(['status' => 'UNKNOWN']);
    }

    public function test_database_requires_positive_quotation_item_quantity(): void
    {
        $quotation = Quotation::factory()->create();

        $this->expectException(QueryException::class);

        QuotationItem::factory()->forQuotation($quotation)->create([
            'quantity' => 0,
            'line_total' => '0.00',
        ]);
    }

    public function test_database_requires_positive_quotation_item_duration(): void
    {
        $quotation = Quotation::factory()->create();

        $this->expectException(QueryException::class);

        QuotationItem::factory()->forQuotation($quotation)->create([
            'duration_minutes' => 0,
        ]);
    }

    public function test_database_requires_quotation_item_start_before_end(): void
    {
        $quotation = Quotation::factory()->create();

        $this->expectException(QueryException::class);

        QuotationItem::factory()->forQuotation($quotation)->create([
            'start_at' => '2027-06-15 21:00:00',
            'end_at' => '2027-06-15 21:00:00',
        ]);
    }

    public function test_source_booking_service_must_belong_to_the_quoted_booking(): void
    {
        $quotation = Quotation::factory()->create();
        $foreignSource = BookingService::factory()->create();

        $this->expectException(QueryException::class);

        QuotationItem::factory()->forQuotation($quotation)->create([
            'booking_service_id' => $foreignSource->id,
        ]);
    }

    public function test_source_reference_can_be_cleared_before_removal_without_losing_snapshots(): void
    {
        $booking = Booking::factory()->create();
        $source = BookingService::factory()->forBooking($booking)->create();
        $quotation = Quotation::factory()->forBooking($booking)->create();
        $item = QuotationItem::factory()->fromBookingService($quotation, $source)->create();

        $item->update(['booking_service_id' => null]);
        $source->delete();

        $item->refresh();
        $this->assertNull($item->booking_service_id);
        $this->assertSame('7500.00', $item->line_total);
        $this->assertSame($source->service_name, $item->service_name);
        $this->assertSame($source->package_name, $item->package_name);
    }

    public function test_model_relationships_and_casts_match_the_domain(): void
    {
        $booking = Booking::factory()->create();
        $source = BookingService::factory()->forBooking($booking)->create();
        $quotation = Quotation::factory()->forBooking($booking)->create();
        $item = QuotationItem::factory()->fromBookingService($quotation, $source)->create();

        $quotation->refresh();
        $item->refresh();

        $this->assertTrue($quotation->organization->is($booking->organization));
        $this->assertTrue($quotation->booking->is($booking));
        $this->assertTrue($quotation->creator->is($booking->creator));
        $this->assertTrue($quotation->items->first()->is($item));
        $this->assertTrue($item->quotation->is($quotation));
        $this->assertTrue($item->bookingService->is($source));
        $this->assertTrue($booking->organization->quotations->first()->is($quotation));
        $this->assertSame(QuotationStatus::Draft, $quotation->status);
        $this->assertInstanceOf(CarbonImmutable::class, $quotation->valid_until);
        $this->assertInstanceOf(CarbonImmutable::class, $quotation->event_date);
        $this->assertInstanceOf(CarbonImmutable::class, $item->start_at);
        $this->assertInstanceOf(CarbonImmutable::class, $item->end_at);
        $this->assertSame('2027-06-15 18:00:00', $item->start_at->format('Y-m-d H:i:s'));
        $this->assertSame('2027-06-15 21:00:00', $item->end_at->format('Y-m-d H:i:s'));
        $this->assertSame(180, $item->duration_minutes);
        $this->assertSame(1, $item->quantity);
    }

    public function test_quotation_allocator_output_is_compatible_with_the_schema(): void
    {
        $organization = Organization::factory()->create();
        BusinessSetting::factory()->for($organization)->create(['quotation_prefix' => 'QT']);
        $booking = Booking::factory()->create(['organization_id' => $organization->id]);

        $quotation = DB::transaction(function () use ($booking, $organization): Quotation {
            $number = app(DocumentNumberAllocator::class)->allocate(
                $organization,
                DocumentType::Quotation,
                new DateTimeImmutable('2027-04-10T12:00:00Z'),
            );

            return Quotation::factory()->forBooking($booking)->create([
                'quotation_number' => $number,
            ]);
        });

        $this->assertSame('QT-2027-000001', $quotation->quotation_number);
    }
}
