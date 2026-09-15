<?php

namespace Tests\Feature\Billings;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Models\Billing;
use App\Models\Booking;
use App\Models\BusinessSetting;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BillingPaymentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2027-05-10 12:00:00', 'Asia/Manila'),
        );
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_financial_api_requires_authentication(): void
    {
        $this->getJson('/api/v1/billings')->assertUnauthorized();
        $this->getJson('/api/v1/billings/1')->assertUnauthorized();
        $this->getJson('/api/v1/billings/1/payments')->assertUnauthorized();
        $this->postJson('/api/v1/quotations/1/payments')->assertUnauthorized();
        $this->getJson('/api/v1/payments')->assertUnauthorized();
        $this->postJson('/api/v1/payments/1/void')->assertUnauthorized();
    }

    public function test_first_and_subsequent_payment_return_exact_financial_context_and_reuse_billing(): void
    {
        [, $user, $booking, $quotation] = $this->acceptedQuotationFixture();

        $this->actingAs($user, 'sanctum')->getJson("/api/v1/quotations/{$quotation->id}")
            ->assertOk()
            ->assertJsonPath('billing', null);
        $this->assertDatabaseCount('billings', 0);

        $first = $this->record($user, $quotation, '2000.10')
            ->assertCreated()
            ->assertJsonPath('payment.amount', '2000.10')
            ->assertJsonPath('payment.status', 'POSTED')
            ->assertJsonPath('payment.payment_method', 'CASH')
            ->assertJsonPath('payment.paid_at', '2027-05-10 10:00:00')
            ->assertJsonPath('payment.internal_note', 'Owner payment note.')
            ->assertJsonPath('payment_summary.amount_paid', '2000.10')
            ->assertJsonPath('payment_summary.remaining_balance', '5999.90')
            ->assertJsonPath('payment_summary.payment_status', 'PARTIALLY_PAID')
            ->assertJsonPath('booking.id', $booking->id)
            ->assertJsonPath('booking.status', 'CONFIRMED')
            ->assertJsonMissingPath('currency')
            ->assertJsonMissingPath('payment.organization_id');
        $billingId = $first->json('billing.id');

        $this->record(
            $user,
            $quotation,
            '5999.90',
            method: PaymentMethod::GCash,
            reference: 'GCASH-002',
        )->assertCreated()
            ->assertJsonPath('billing.id', $billingId)
            ->assertJsonPath('payment.reference_number', 'GCASH-002')
            ->assertJsonPath('payment_summary.amount_paid', '8000.00')
            ->assertJsonPath('payment_summary.remaining_balance', '0.00')
            ->assertJsonPath('payment_summary.payment_status', 'PAID');

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        $this->assertSame(1, Billing::query()->where('quotation_id', $quotation->id)->count());
        $this->assertDatabaseCount('payments', 2);

        $this->actingAs($user, 'sanctum')->getJson("/api/v1/quotations/{$quotation->id}")
            ->assertOk()
            ->assertJsonPath('billing.id', $billingId)
            ->assertJsonPath('billing.billing_number', $first->json('billing.billing_number'));
    }

    public function test_record_payment_surfaces_domain_validation_and_rejects_hostile_fields(): void
    {
        [, $user, $booking, $quotation] = $this->acceptedQuotationFixture();

        $this->record($user, $quotation, '8000.01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');
        $this->record($user, $quotation, '0.00')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');
        $this->record($user, $quotation, '-1.00')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');
        $this->record($user, $quotation, '1.00', paidAt: '2027-05-10 12:00:01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('paid_at');
        $this->record($user, $quotation, '1.00', method: PaymentMethod::BankTransfer)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reference_number');

        $hostileFields = [
            'organization_id' => 999,
            'booking_id' => 999,
            'quotation_id' => 999,
            'billing_id' => 999,
            'status' => 'VOIDED',
            'created_by' => 999,
            'voided_at' => '2027-01-01 00:00:00',
            'voided_by' => 999,
            'void_reason' => 'Hostile',
            'currency' => 'USD',
            'billing_number' => 'HOSTILE-1',
            'total' => '0.01',
            'remaining_balance' => '0.00',
        ];
        $this->actingAs($user, 'sanctum')->postJson(
            "/api/v1/quotations/{$quotation->id}/payments",
            [...$this->paymentPayload('1.00'), ...$hostileFields],
        )->assertUnprocessable()
            ->assertJsonValidationErrors(array_keys($hostileFields));

        $this->assertDatabaseCount('billings', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(BookingStatus::Quoted, $booking->fresh()->status);
    }

    public function test_fully_paid_non_accepted_and_cancelled_bookings_reject_payment(): void
    {
        [, $user, $booking, $quotation] = $this->acceptedQuotationFixture();
        $this->record($user, $quotation, '8000.00')->assertCreated();
        $this->record($user, $quotation, '0.01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        [, $otherUser, $otherBooking, $otherQuotation] = $this->acceptedQuotationFixture();
        $otherQuotation->update(['status' => 'SENT', 'accepted_at' => null]);
        $this->record($otherUser, $otherQuotation, '1.00')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quotation');

        $otherQuotation->update(['status' => 'ACCEPTED', 'accepted_at' => '2027-05-02 10:00:00']);
        $otherBooking->update([
            'status' => BookingStatus::Cancelled,
            'cancelled_at' => '2027-05-09 09:00:00',
            'cancelled_by' => $otherUser->id,
        ]);
        $this->record($otherUser, $otherQuotation, '1.00')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('booking');

        $this->assertSame(1, Payment::query()->where('quotation_id', $quotation->id)->count());
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
    }

    public function test_billing_index_and_detail_are_tenant_scoped_searchable_and_snapshot_based(): void
    {
        [, $user, , $quotation] = $this->acceptedQuotationFixture();
        $billingId = $this->record($user, $quotation, '2000.10')->json('billing.id');
        [, $foreignUser, , $foreignQuotation] = $this->acceptedQuotationFixture();
        $foreignBillingId = $this->record($foreignUser, $foreignQuotation, '1000.00')->json('billing.id');

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/billings?search=Historical%20Customer')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $billingId)
            ->assertJsonPath('data.0.payment_summary.amount_paid', '2000.10')
            ->assertJsonPath('data.0.payment_summary.remaining_balance', '5999.90')
            ->assertJsonMissingPath('data.0.items')
            ->assertJsonMissingPath('data.0.payments');

        $this->actingAs($user, 'sanctum')->getJson("/api/v1/billings/{$billingId}")
            ->assertOk()
            ->assertJsonPath('quotation.quotation_number', $quotation->quotation_number)
            ->assertJsonPath('seller_snapshot.display_name', 'Historical Events Co.')
            ->assertJsonPath('customer_snapshot.name', 'Historical Customer')
            ->assertJsonPath('event_snapshot.event_name', 'Historical Event')
            ->assertJsonPath('subtotal', '7500.00')
            ->assertJsonPath('total', '8000.00')
            ->assertJsonPath('payment_summary.payment_status', 'PARTIALLY_PAID')
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.service_name', 'Mirror Booth')
            ->assertJsonPath('items.1.start_at', '2027-06-15 21:00')
            ->assertJsonPath('items.1.end_at', '2027-06-16 00:00')
            ->assertJsonMissingPath('currency')
            ->assertJsonMissingPath('status')
            ->assertJsonMissingPath('amount_paid')
            ->assertJsonMissingPath('remaining_balance')
            ->assertJsonMissingPath('payments');

        $this->actingAs($user, 'sanctum')->getJson("/api/v1/billings/{$foreignBillingId}")
            ->assertNotFound();
        $this->actingAs($user, 'sanctum')->getJson("/api/v1/billings/{$foreignBillingId}/payments")
            ->assertNotFound();
    }

    public function test_payment_history_global_index_filters_and_void_api_preserve_audit_history(): void
    {
        [, $user, $booking, $quotation] = $this->acceptedQuotationFixture();
        $first = $this->record($user, $quotation, '3000.00')->json('payment');
        $second = $this->record(
            $user,
            $quotation,
            '2000.00',
            paidAt: '2027-05-09 09:00:00',
            method: PaymentMethod::GCash,
            reference: 'GCASH-VOID-1',
        )->json('payment');

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/payments/{$second['id']}/void", [
            'void_reason' => 'Duplicate payment entry.',
        ])->assertOk()
            ->assertJsonPath('payment.status', 'VOIDED')
            ->assertJsonPath('payment.void_reason', 'Duplicate payment entry.')
            ->assertJsonPath('payment.voided_by.id', $user->id)
            ->assertJsonPath('payment_summary.amount_paid', '3000.00')
            ->assertJsonPath('payment_summary.remaining_balance', '5000.00')
            ->assertJsonPath('payment_summary.payment_status', 'PARTIALLY_PAID')
            ->assertJsonPath('booking.status', 'CONFIRMED');

        $billingId = $first['billing']['id'];
        $this->actingAs($user, 'sanctum')->getJson("/api/v1/billings/{$billingId}/payments")
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $first['id'])
            ->assertJsonPath('data.0.amount', '3000.00')
            ->assertJsonPath('data.1.id', $second['id'])
            ->assertJsonPath('data.1.status', 'VOIDED')
            ->assertJsonPath('data.1.void_reason', 'Duplicate payment entry.');

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/payments?status=VOIDED&payment_method=GCASH&search=VOID-1&paid_from=2027-05-09&paid_to=2027-05-09')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $second['id'])
            ->assertJsonPath('data.0.billing.id', $billingId)
            ->assertJsonPath('data.0.quotation.id', $quotation->id)
            ->assertJsonPath('data.0.booking.id', $booking->id)
            ->assertJsonPath('data.0.created_by.id', $user->id);

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/payments/{$first['id']}/void")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('void_reason');
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/payments/{$second['id']}/void", [
            'void_reason' => 'Replay.',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('payment');

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_void_rejects_client_controlled_audit_fields_and_manual_financial_routes_do_not_exist(): void
    {
        [, $user, , $quotation] = $this->acceptedQuotationFixture();
        $paymentResponse = $this->record($user, $quotation, '1000.00');
        $billingId = $paymentResponse->json('billing.id');
        $paymentId = $paymentResponse->json('payment.id');

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/payments/{$paymentId}/void", [
            'void_reason' => 'Hostile audit update.',
            'status' => 'VOIDED',
            'voided_at' => '2027-01-01 00:00:00',
            'voided_by' => 999,
            'currency' => 'USD',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['status', 'voided_at', 'voided_by', 'currency']);

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/quotations/{$quotation->id}/billing")
            ->assertNotFound();
        $this->actingAs($user, 'sanctum')->patchJson("/api/v1/billings/{$billingId}/status", [
            'status' => 'PAID',
        ])->assertNotFound();
        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/payments/{$paymentId}")
            ->assertNotFound();

        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'status' => 'POSTED',
            'voided_at' => null,
        ]);
    }

    public function test_all_financial_lookups_and_mutations_hide_foreign_tenant_records(): void
    {
        [, $owner, , $quotation] = $this->acceptedQuotationFixture();
        $paymentResponse = $this->record($owner, $quotation, '1000.00');
        $billingId = $paymentResponse->json('billing.id');
        $paymentId = $paymentResponse->json('payment.id');
        [, $foreignUser] = $this->acceptedQuotationFixture();

        $this->actingAs($foreignUser, 'sanctum')->getJson('/api/v1/billings')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->actingAs($foreignUser, 'sanctum')->getJson('/api/v1/payments')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->actingAs($foreignUser, 'sanctum')->getJson("/api/v1/billings/{$billingId}")
            ->assertNotFound();
        $this->actingAs($foreignUser, 'sanctum')->getJson("/api/v1/billings/{$billingId}/payments")
            ->assertNotFound();
        $this->actingAs($foreignUser, 'sanctum')->postJson(
            "/api/v1/quotations/{$quotation->id}/payments",
            $this->paymentPayload('1.00'),
        )->assertNotFound();
        $this->actingAs($foreignUser, 'sanctum')->postJson("/api/v1/payments/{$paymentId}/void", [
            'void_reason' => 'Foreign attempt.',
        ])->assertNotFound();

        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'status' => 'POSTED',
        ]);
    }

    /** @return array{Organization, User, Booking, Quotation} */
    private function acceptedQuotationFixture(): array
    {
        $organization = Organization::factory()->create();
        BusinessSetting::factory()->for($organization)->create([
            'display_name' => 'Historical Events Co.',
            'billing_prefix' => 'INV',
        ]);
        $user = User::factory()->for($organization)->create();
        $booking = Booking::factory()->create([
            'organization_id' => $organization->id,
            'customer_name' => 'Historical Customer',
            'event_type_name' => 'Historical Event Type',
            'event_name' => 'Historical Event',
            'venue_name' => 'Historical Venue',
            'status' => BookingStatus::Quoted,
            'created_by' => $user->id,
        ]);
        $quotation = Quotation::factory()->accepted()->forBooking($booking)->create([
            'quotation_number' => 'QT-2027-'.str_pad((string) $organization->id, 6, '0', STR_PAD_LEFT),
            'business_display_name' => 'Historical Events Co.',
            'customer_name' => 'Historical Customer',
            'event_type_name' => 'Historical Event Type',
            'event_name' => 'Historical Event',
            'event_date' => '2027-06-15',
            'venue_name' => 'Historical Venue',
            'subtotal' => '7500.00',
            'transportation_fee' => '500.00',
            'crew_meal_fee' => '250.00',
            'discount_amount' => '250.00',
            'total' => '8000.00',
        ]);
        QuotationItem::factory()->forQuotation($quotation)->create([
            'service_name' => 'Mirror Booth',
            'package_name' => 'Classic',
            'start_at' => '2027-06-15 18:00:00',
            'end_at' => '2027-06-15 20:00:00',
            'duration_minutes' => 120,
            'quantity' => 1,
            'unit_rate' => '5000.00',
            'line_total' => '5000.00',
            'sort_order' => 0,
        ]);
        QuotationItem::factory()->forQuotation($quotation)->create([
            'service_name' => '360 Booth',
            'package_name' => 'Essential',
            'start_at' => '2027-06-15 21:00:00',
            'end_at' => '2027-06-16 00:00:00',
            'duration_minutes' => 180,
            'quantity' => 1,
            'unit_rate' => '2500.00',
            'line_total' => '2500.00',
            'sort_order' => 1,
        ]);

        return [$organization, $user, $booking, $quotation];
    }

    private function record(
        User $user,
        Quotation $quotation,
        string $amount,
        string $paidAt = '2027-05-10 10:00:00',
        PaymentMethod $method = PaymentMethod::Cash,
        ?string $reference = null,
    ): TestResponse {
        return $this->actingAs($user, 'sanctum')->postJson(
            "/api/v1/quotations/{$quotation->id}/payments",
            $this->paymentPayload($amount, $paidAt, $method, $reference),
        );
    }

    /** @return array<string, mixed> */
    private function paymentPayload(
        string $amount,
        string $paidAt = '2027-05-10 10:00:00',
        PaymentMethod $method = PaymentMethod::Cash,
        ?string $reference = null,
    ): array {
        return [
            'amount' => $amount,
            'paid_at' => $paidAt,
            'payment_method' => $method->value,
            'reference_number' => $reference,
            'internal_note' => 'Owner payment note.',
        ];
    }
}
