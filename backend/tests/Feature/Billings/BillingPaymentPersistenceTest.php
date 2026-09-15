<?php

namespace Tests\Feature\Billings;

use App\Enums\BookingStatus;
use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Billing;
use App\Models\BillingItem;
use App\Models\Booking;
use App\Models\BusinessSetting;
use App\Models\EventType;
use App\Models\Organization;
use App\Models\Payment;
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

class BillingPaymentPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_enums_contain_the_approved_values(): void
    {
        $this->assertSame(['POSTED', 'VOIDED'], array_column(PaymentStatus::cases(), 'value'));
        $this->assertSame(
            ['CASH', 'GCASH', 'BANK_TRANSFER', 'CHECK'],
            array_column(PaymentMethod::cases(), 'value'),
        );
    }

    public function test_billing_schema_contains_snapshots_and_exact_totals_only(): void
    {
        $this->assertTrue(Schema::hasColumns('billings', [
            'id',
            'organization_id',
            'booking_id',
            'quotation_id',
            'billing_number',
            'quotation_number',
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
            'subtotal',
            'transportation_fee',
            'crew_meal_fee',
            'discount_amount',
            'total',
            'created_by',
            'created_at',
            'updated_at',
        ]));
        $this->assertFalse(Schema::hasColumn('billings', 'currency'));
        $this->assertFalse(Schema::hasColumn('billings', 'status'));
        $this->assertFalse(Schema::hasColumn('billings', 'paid_amount'));
        $this->assertFalse(Schema::hasColumn('billings', 'remaining_balance'));
    }

    public function test_billing_item_schema_contains_commercial_and_schedule_snapshots(): void
    {
        $this->assertTrue(Schema::hasColumns('billing_items', [
            'id',
            'organization_id',
            'booking_id',
            'quotation_id',
            'billing_id',
            'quotation_item_id',
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

        $sourceColumn = collect(Schema::getColumns('billing_items'))
            ->firstWhere('name', 'quotation_item_id');

        $this->assertTrue($sourceColumn['nullable']);
    }

    public function test_payment_schema_contains_financial_and_void_audit_fields(): void
    {
        $this->assertTrue(Schema::hasColumns('payments', [
            'id',
            'organization_id',
            'booking_id',
            'quotation_id',
            'billing_id',
            'amount',
            'paid_at',
            'payment_method',
            'reference_number',
            'internal_note',
            'status',
            'created_by',
            'voided_at',
            'voided_by',
            'void_reason',
            'created_at',
            'updated_at',
        ]));
        $this->assertFalse(Schema::hasColumn('payments', 'currency'));
        $this->assertFalse(Schema::hasColumn('payments', 'payment_number'));
    }

    public function test_database_enforces_billing_tenant_booking_and_quotation_lineage(): void
    {
        $quotation = $this->acceptedQuotation();
        $foreignBooking = Booking::factory()->create([
            'organization_id' => $quotation->organization_id,
            'customer_id' => $quotation->booking->customer_id,
            'event_type_id' => $quotation->booking->event_type_id,
            'status' => BookingStatus::Quoted,
            'created_by' => $quotation->created_by,
        ]);

        $this->expectException(QueryException::class);

        Billing::factory()->forQuotation($quotation)->create([
            'booking_id' => $foreignBooking->id,
        ]);
    }

    public function test_database_rejects_a_cross_tenant_billing_creator(): void
    {
        $quotation = $this->acceptedQuotation();
        $foreignOrganization = Organization::factory()->create();
        $foreignCreator = User::factory()->for($foreignOrganization)->create();

        $this->expectException(QueryException::class);

        Billing::factory()->forQuotation($quotation)->create([
            'created_by' => $foreignCreator->id,
        ]);
    }

    public function test_database_allows_only_one_billing_per_quotation(): void
    {
        $quotation = $this->acceptedQuotation();
        Billing::factory()->forQuotation($quotation)->create();

        $this->expectException(QueryException::class);

        Billing::factory()->forQuotation($quotation)->create();
    }

    public function test_billing_numbers_are_unique_only_within_a_tenant(): void
    {
        $first = Billing::factory()->create(['billing_number' => 'INV-2027-000001']);
        $second = Billing::factory()->create(['billing_number' => 'INV-2027-000001']);

        $this->assertNotSame($first->organization_id, $second->organization_id);

        $quotation = $this->acceptedQuotationFor($first->organization, $first->creator);

        $this->expectException(QueryException::class);

        Billing::factory()->forQuotation($quotation)->create([
            'billing_number' => 'INV-2027-000001',
        ]);
    }

    public function test_billing_money_fields_are_exact_decimal_strings(): void
    {
        $billing = Billing::factory()->create([
            'subtotal' => '1000.10',
            'transportation_fee' => '50.20',
            'crew_meal_fee' => '10.30',
            'discount_amount' => '0.60',
            'total' => '1060.00',
        ]);

        $billing->refresh();
        $this->assertSame('1000.10', $billing->subtotal);
        $this->assertSame('50.20', $billing->transportation_fee);
        $this->assertSame('10.30', $billing->crew_meal_fee);
        $this->assertSame('0.60', $billing->discount_amount);
        $this->assertSame('1060.00', $billing->total);
    }

    public function test_database_requires_an_exact_positive_billing_total(): void
    {
        $this->expectException(QueryException::class);

        Billing::factory()->create([
            'subtotal' => '0.00',
            'transportation_fee' => '0.00',
            'crew_meal_fee' => '0.00',
            'discount_amount' => '0.00',
            'total' => '0.00',
        ]);
    }

    public function test_database_rejects_an_inexact_billing_total(): void
    {
        $this->expectException(QueryException::class);

        Billing::factory()->create(['total' => '7999.99']);
    }

    public function test_database_enforces_billing_item_lineage(): void
    {
        $firstBilling = Billing::factory()->create();
        $secondBilling = Billing::factory()->create();

        $this->expectException(QueryException::class);

        BillingItem::factory()->forBilling($firstBilling)->create([
            'billing_id' => $secondBilling->id,
        ]);
    }

    public function test_source_quotation_item_must_belong_to_the_billed_quotation(): void
    {
        $billing = Billing::factory()->create();
        $foreignItem = QuotationItem::factory()->create();

        $this->expectException(QueryException::class);

        BillingItem::factory()->forBilling($billing)->create([
            'quotation_item_id' => $foreignItem->id,
        ]);
    }

    public function test_billing_item_survives_source_lineage_removal_with_snapshots_intact(): void
    {
        $quotation = $this->acceptedQuotation();
        $source = QuotationItem::factory()->forQuotation($quotation)->create();
        $billing = Billing::factory()->forQuotation($quotation)->create();
        $item = BillingItem::factory()->fromQuotationItem($billing, $source)->create();

        $item->update(['quotation_item_id' => null]);
        $source->delete();

        $item->refresh();
        $this->assertNull($item->quotation_item_id);
        $this->assertSame('360 Video Booth', $item->service_name);
        $this->assertSame('Premium', $item->package_name);
        $this->assertSame('7500.00', $item->line_total);
    }

    public function test_database_requires_valid_billing_item_values(): void
    {
        $billing = Billing::factory()->create();

        foreach ([
            ['duration_minutes' => 0],
            ['quantity' => 0, 'line_total' => '0.00'],
            ['start_at' => '2027-06-15 21:00:00', 'end_at' => '2027-06-15 21:00:00'],
            ['quantity' => 2, 'line_total' => '7500.00'],
        ] as $attributes) {
            try {
                BillingItem::factory()->forBilling($billing)->create($attributes);
                $this->fail('The database accepted an invalid Billing Item.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_payment_casts_and_relationships_match_the_domain(): void
    {
        $quotation = $this->acceptedQuotation();
        $source = QuotationItem::factory()->forQuotation($quotation)->create();
        $billing = Billing::factory()->forQuotation($quotation)->create();
        $item = BillingItem::factory()->fromQuotationItem($billing, $source)->create();
        $posted = Payment::factory()->forBilling($billing)->create([
            'amount' => '1234.56',
            'internal_note' => 'Deposit received at the studio.',
        ]);
        $voided = Payment::factory()->forBilling($billing)->voided()->create();

        $billing->refresh();
        $posted->refresh();
        $voided->refresh();

        $this->assertTrue($billing->organization->is($quotation->organization));
        $this->assertTrue($billing->booking->is($quotation->booking));
        $this->assertTrue($billing->quotation->is($quotation));
        $this->assertTrue($billing->creator->is($quotation->creator));
        $this->assertTrue($billing->items->first()->is($item));
        $this->assertTrue($billing->payments->contains($posted));
        $this->assertTrue($item->quotationItem->is($source));
        $this->assertTrue($source->billingItem->is($item));
        $this->assertTrue($quotation->billing->is($billing));
        $this->assertTrue($quotation->payments->contains($posted));
        $this->assertTrue($quotation->booking->billings->contains($billing));
        $this->assertTrue($quotation->booking->payments->contains($posted));
        $this->assertTrue($quotation->organization->billings->contains($billing));
        $this->assertTrue($quotation->organization->payments->contains($posted));
        $this->assertTrue($posted->billing->is($billing));
        $this->assertTrue($posted->quotation->is($quotation));
        $this->assertTrue($posted->booking->is($quotation->booking));
        $this->assertTrue($posted->creator->is($quotation->creator));
        $this->assertTrue($voided->voider->is($quotation->creator));
        $this->assertSame('1234.56', $posted->amount);
        $this->assertSame(PaymentMethod::Cash, $posted->payment_method);
        $this->assertSame(PaymentStatus::Posted, $posted->status);
        $this->assertSame(PaymentStatus::Voided, $voided->status);
        $this->assertInstanceOf(CarbonImmutable::class, $posted->paid_at);
        $this->assertInstanceOf(CarbonImmutable::class, $voided->voided_at);
        $this->assertSame('2027-05-03 14:30:00', $posted->paid_at->format('Y-m-d H:i:s'));
    }

    public function test_database_enforces_payment_billing_lineage(): void
    {
        $firstBilling = Billing::factory()->create();
        $secondBilling = Billing::factory()->create();

        $this->expectException(QueryException::class);

        Payment::factory()->forBilling($firstBilling)->create([
            'billing_id' => $secondBilling->id,
        ]);
    }

    public function test_database_requires_a_positive_payment_amount(): void
    {
        $this->expectException(QueryException::class);

        Payment::factory()->create(['amount' => '0.00']);
    }

    public function test_database_rejects_unknown_payment_enums(): void
    {
        $payment = Payment::factory()->create();

        foreach (['status' => 'UNKNOWN', 'payment_method' => 'CARD'] as $column => $value) {
            try {
                DB::table('payments')->where('id', $payment->id)->update([$column => $value]);
                $this->fail("The database accepted an invalid {$column}.");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_posted_payment_cannot_contain_void_audit_data(): void
    {
        $billing = Billing::factory()->create();

        $this->expectException(QueryException::class);

        Payment::factory()->forBilling($billing)->create([
            'voided_at' => '2027-05-04 09:00:00',
            'voided_by' => $billing->created_by,
            'void_reason' => 'Should not be present.',
        ]);
    }

    public function test_voided_payment_requires_complete_nonblank_audit_data(): void
    {
        $billing = Billing::factory()->create();

        foreach ([
            ['voided_at' => null],
            ['voided_by' => null],
            ['void_reason' => null],
            ['void_reason' => '   '],
        ] as $attributes) {
            try {
                Payment::factory()->forBilling($billing)->voided()->create($attributes);
                $this->fail('The database accepted incomplete Payment void audit data.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_non_cash_payments_require_a_reference_while_cash_does_not(): void
    {
        $billing = Billing::factory()->create();
        $cash = Payment::factory()->forBilling($billing)->create();

        $this->assertNull($cash->reference_number);

        foreach ([PaymentMethod::GCash, PaymentMethod::BankTransfer, PaymentMethod::Check] as $method) {
            try {
                Payment::factory()->forBilling($billing)->create([
                    'payment_method' => $method,
                    'reference_number' => null,
                ]);
                $this->fail("The database accepted {$method->value} without a reference.");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_billing_number_allocator_uses_inv_with_tenant_and_manila_year_isolation(): void
    {
        $first = $this->organizationWithSettings();
        $second = $this->organizationWithSettings();

        $this->assertSame(
            'INV-2026-000001',
            $this->allocateBillingNumber($first, new DateTimeImmutable('2026-12-31T15:30:00Z')),
        );
        $this->assertSame(
            'INV-2027-000001',
            $this->allocateBillingNumber($first, new DateTimeImmutable('2026-12-31T16:30:00Z')),
        );
        $this->assertSame(
            'INV-2027-000001',
            $this->allocateBillingNumber($second, new DateTimeImmutable('2026-12-31T16:30:00Z')),
        );
    }

    private function acceptedQuotation(): Quotation
    {
        $organization = Organization::factory()->create();
        $creator = User::factory()->for($organization)->create();

        return $this->acceptedQuotationFor($organization, $creator);
    }

    private function acceptedQuotationFor(Organization $organization, User $creator): Quotation
    {
        $booking = $this->bookingFor($organization, $creator);

        return Quotation::factory()->accepted()->forBooking($booking)->create();
    }

    private function bookingFor(Organization $organization, User $creator): Booking
    {
        $eventTypeName = 'Billing Event '.fake()->uuid();
        $eventType = EventType::factory()->create([
            'organization_id' => $organization->id,
            'name' => $eventTypeName,
        ]);

        return Booking::factory()->create([
            'organization_id' => $organization->id,
            'event_type_id' => $eventType->id,
            'event_type_name' => $eventTypeName,
            'status' => BookingStatus::Quoted,
            'created_by' => $creator->id,
        ]);
    }

    private function organizationWithSettings(): Organization
    {
        $organization = Organization::factory()->create();
        BusinessSetting::factory()->for($organization)->create(['billing_prefix' => 'INV']);

        return $organization;
    }

    private function allocateBillingNumber(Organization $organization, DateTimeImmutable $createdAt): string
    {
        return DB::transaction(
            fn (): string => app(DocumentNumberAllocator::class)->allocate(
                $organization,
                DocumentType::Billing,
                $createdAt,
            ),
        );
    }
}
