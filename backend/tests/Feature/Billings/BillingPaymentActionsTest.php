<?php

namespace Tests\Feature\Billings;

use App\Actions\Billings\RecordPayment;
use App\Actions\Billings\VoidPayment;
use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\QuotationStatus;
use App\Models\Billing;
use App\Models\Booking;
use App\Models\BookingService;
use App\Models\BusinessSetting;
use App\Models\DocumentSequence;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Support\Billings\PaymentMutationResult;
use App\Support\Billings\PaymentSummary;
use App\Support\Billings\PaymentSummaryCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BillingPaymentActionsTest extends TestCase
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

    public function test_first_payment_creates_exact_billing_snapshots_and_confirms_booking_atomically(): void
    {
        [$organization, $user, $booking, $quotation] = $this->acceptedQuotationFixture();
        $quotationItems = $quotation->items()->get();

        $this->assertNull($quotation->billing);

        $result = $this->record($user, $quotation, '2000.00');
        $billing = $result->billing->fresh('items');

        $this->assertSame('INV-2027-000001', $billing->billing_number);
        $this->assertSame($organization->id, $billing->organization_id);
        $this->assertSame($booking->id, $billing->booking_id);
        $this->assertSame($quotation->id, $billing->quotation_id);
        $this->assertSame($user->id, $billing->created_by);

        foreach ($this->billingHeaderMap() as $billingField => $quotationField) {
            $expected = $quotation->getAttribute($quotationField);
            $actual = $billing->getAttribute($billingField);

            if ($quotationField === 'event_date') {
                $expected = $expected->format('Y-m-d');
                $actual = $actual->format('Y-m-d');
            }

            $this->assertSame($expected, $actual, "Billing snapshot mismatch for {$billingField}.");
        }

        $this->assertCount(2, $billing->items);
        foreach ($quotationItems as $index => $quotationItem) {
            $billingItem = $billing->items[$index];
            $this->assertSame($quotationItem->id, $billingItem->quotation_item_id);
            $this->assertSame($quotationItem->service_name, $billingItem->service_name);
            $this->assertSame($quotationItem->package_name, $billingItem->package_name);
            $this->assertSame($quotationItem->start_at->format('Y-m-d H:i:s'), $billingItem->start_at->format('Y-m-d H:i:s'));
            $this->assertSame($quotationItem->end_at->format('Y-m-d H:i:s'), $billingItem->end_at->format('Y-m-d H:i:s'));
            $this->assertSame($quotationItem->duration_minutes, $billingItem->duration_minutes);
            $this->assertSame($quotationItem->quantity, $billingItem->quantity);
            $this->assertSame($quotationItem->unit_rate, $billingItem->unit_rate);
            $this->assertSame($quotationItem->line_total, $billingItem->line_total);
            $this->assertSame($quotationItem->sort_order, $billingItem->sort_order);
        }

        $this->assertSame(PaymentStatus::Posted, $result->payment->status);
        $this->assertSame('2000.00', $result->payment->amount);
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        $this->assertSame('2000.00', $result->summary->amountPaid);
        $this->assertSame('6000.00', $result->summary->remainingBalance);
        $this->assertSame(PaymentSummary::PARTIALLY_PAID, $result->summary->paymentStatus);
        $this->assertDatabaseHas('document_sequences', [
            'organization_id' => $organization->id,
            'document_type' => 'BILLING',
            'year' => 2027,
            'next_number' => 2,
        ]);
    }

    public function test_billing_uses_quotation_snapshots_after_current_master_data_and_settings_change(): void
    {
        [$organization, $user, $booking, $quotation] = $this->acceptedQuotationFixture();

        $booking->customer()->update(['name' => 'Current Customer Name']);
        $booking->eventType()->update(['name' => 'Current Event Type']);
        $booking->update([
            'customer_name' => 'Changed Booking Customer',
            'event_name' => 'Changed Booking Event',
            'venue_name' => 'Changed Booking Venue',
            'start_at' => '2027-07-20 09:00:00',
        ]);
        $booking->bookingServices()->update(['duration_minutes' => 60]);
        $organization->businessSetting()->update([
            'display_name' => 'Current Business Name',
            'email' => 'current@example.test',
            'logo_path' => 'business-logos/current.png',
        ]);

        $billing = $this->record($user, $quotation, '1000.00')->billing->fresh('items');
        $booking->update(['start_at' => '2027-08-25 14:00:00']);
        $booking->bookingServices()->update(['duration_minutes' => 30]);
        $billing->refresh();

        $this->assertSame('Historical Events Co.', $billing->business_display_name);
        $this->assertSame('historical@example.test', $billing->business_email);
        $this->assertSame('business-logos/historical.png', $billing->business_logo_path);
        $this->assertSame('Historical Customer', $billing->customer_name);
        $this->assertSame('Historical Event', $billing->event_name);
        $this->assertSame('Historical Venue', $billing->venue_name);
        $this->assertSame('Mirror Booth', $billing->items[0]->service_name);
        $this->assertSame('360 Booth', $billing->items[1]->service_name);
        $this->assertSame('2027-06-15 18:00:00', $billing->items[0]->start_at->format('Y-m-d H:i:s'));
        $this->assertSame('2027-06-15 20:00:00', $billing->items[0]->end_at->format('Y-m-d H:i:s'));
        $this->assertSame(120, $billing->items[0]->duration_minutes);
        $this->assertSame('2027-06-15 21:00:00', $billing->items[1]->start_at->format('Y-m-d H:i:s'));
        $this->assertSame('2027-06-16 00:00:00', $billing->items[1]->end_at->format('Y-m-d H:i:s'));
        $this->assertSame(180, $billing->items[1]->duration_minutes);
    }

    public function test_partial_payments_reuse_billing_and_derive_exact_financial_states(): void
    {
        [, $user, $booking, $quotation] = $this->acceptedQuotationFixture();

        $first = $this->record($user, $quotation, '2000.10');
        $second = $this->record($user, $quotation, '3999.80', reference: 'GCASH-001', method: PaymentMethod::GCash);
        $final = $this->record($user, $quotation, '2000.10', reference: 'BANK-001', method: PaymentMethod::BankTransfer);

        $this->assertTrue($first->billing->is($second->billing));
        $this->assertTrue($second->billing->is($final->billing));
        $this->assertSame('2000.10', $first->summary->amountPaid);
        $this->assertSame('5999.90', $first->summary->remainingBalance);
        $this->assertSame(PaymentSummary::PARTIALLY_PAID, $first->summary->paymentStatus);
        $this->assertSame('5999.90', $second->summary->amountPaid);
        $this->assertSame('2000.10', $second->summary->remainingBalance);
        $this->assertSame('8000.00', $final->summary->amountPaid);
        $this->assertSame('0.00', $final->summary->remainingBalance);
        $this->assertSame(PaymentSummary::PAID, $final->summary->paymentStatus);
        $this->assertSame(1, Billing::query()->where('quotation_id', $quotation->id)->count());
        $this->assertSame(3, Payment::query()->where('quotation_id', $quotation->id)->count());
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        $this->assertSame(2, DocumentSequence::query()->where('organization_id', $booking->organization_id)->where('document_type', 'BILLING')->value('next_number'));
    }

    public function test_zero_negative_and_overpayment_roll_back_first_billing_creation(): void
    {
        [, $user, $booking, $quotation] = $this->acceptedQuotationFixture();

        foreach (['0.00', '-1.00', '8000.01'] as $amount) {
            try {
                $this->record($user, $quotation, $amount);
                $this->fail("The invalid Payment amount {$amount} was accepted.");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }

            $this->assertDatabaseMissing('billings', ['quotation_id' => $quotation->id]);
            $this->assertDatabaseMissing('payments', ['quotation_id' => $quotation->id]);
            $this->assertSame(BookingStatus::Quoted, $booking->fresh()->status);
            $this->assertDatabaseMissing('document_sequences', [
                'organization_id' => $booking->organization_id,
                'document_type' => 'BILLING',
            ]);
        }
    }

    public function test_additional_payment_is_rejected_after_billing_is_fully_paid(): void
    {
        [, $user, , $quotation] = $this->acceptedQuotationFixture();
        $this->record($user, $quotation, '8000.00');

        try {
            $this->record($user, $quotation, '0.01');
            $this->fail('An additional Payment was accepted after the Billing was fully paid.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }

        $this->assertSame(1, Payment::query()->where('quotation_id', $quotation->id)->count());
    }

    public function test_payment_method_reference_rules_are_enforced_and_blank_values_are_normalized(): void
    {
        [, $user, , $quotation] = $this->acceptedQuotationFixture();

        foreach ([PaymentMethod::GCash, PaymentMethod::BankTransfer, PaymentMethod::Check] as $method) {
            try {
                $this->record($user, $quotation, '1.00', reference: '   ', method: $method);
                $this->fail("{$method->value} was accepted without a reference.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('reference_number', $exception->errors());
            }
        }

        $cash = $this->record($user, $quotation, '1000.00', reference: '   ');
        $gcash = $this->record($user, $quotation, '1000.00', reference: '  G-001  ', method: PaymentMethod::GCash);
        $bank = $this->record($user, $quotation, '1000.00', reference: 'B-001', method: PaymentMethod::BankTransfer);
        $check = $this->record($user, $quotation, '1000.00', reference: 'C-001', method: PaymentMethod::Check);

        $this->assertNull($cash->payment->reference_number);
        $this->assertSame('G-001', $gcash->payment->reference_number);
        $this->assertSame('B-001', $bank->payment->reference_number);
        $this->assertSame('C-001', $check->payment->reference_number);
    }

    public function test_manila_today_and_backdated_payments_are_allowed_but_future_payment_is_rejected(): void
    {
        [, $user, , $quotation] = $this->acceptedQuotationFixture();

        try {
            $this->record($user, $quotation, '1000.00', paidAt: '2027-05-10 12:00:01');
            $this->fail('A future Payment was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('paid_at', $exception->errors());
        }

        $backdated = $this->record($user, $quotation, '1000.00', paidAt: '2027-04-01 08:00:00');
        $today = $this->record($user, $quotation, '1000.00', paidAt: '2027-05-10 12:00:00');

        $this->assertSame('2027-04-01 08:00:00', $backdated->payment->paid_at->format('Y-m-d H:i:s'));
        $this->assertSame('2027-05-10 12:00:00', $today->payment->paid_at->format('Y-m-d H:i:s'));
    }

    public function test_first_payment_requires_accepted_quotation_and_consistent_quoted_booking(): void
    {
        [, $user, $booking, $quotation] = $this->acceptedQuotationFixture();
        $quotation->update(['status' => QuotationStatus::Sent, 'accepted_at' => null]);

        try {
            $this->record($user, $quotation, '1000.00');
            $this->fail('A non-Accepted Quotation received a Payment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quotation', $exception->errors());
        }

        $quotation->update(['status' => QuotationStatus::Accepted, 'accepted_at' => '2027-05-02 10:00:00']);
        $booking->update(['status' => BookingStatus::Pending]);

        try {
            $this->record($user, $quotation, '1000.00');
            $this->fail('A first Billing was created for a Pending Booking.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('booking', $exception->errors());
        }

        $booking->update(['status' => BookingStatus::Confirmed]);

        try {
            $this->record($user, $quotation, '1000.00');
            $this->fail('A first Billing was created for an already Confirmed Booking.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('booking', $exception->errors());
        }

        $this->assertDatabaseMissing('billings', ['quotation_id' => $quotation->id]);
    }

    public function test_payment_lineage_and_status_are_derived_instead_of_taken_from_input(): void
    {
        [$organization, $user, $booking, $quotation] = $this->acceptedQuotationFixture();
        $foreignOrganization = Organization::factory()->create();
        $foreignUser = User::factory()->for($foreignOrganization)->create();

        $result = app(RecordPayment::class)->handle($user, $quotation->id, [
            'organization_id' => $foreignOrganization->id,
            'booking_id' => 999999,
            'quotation_id' => 999999,
            'billing_id' => 999999,
            'status' => PaymentStatus::Voided->value,
            'created_by' => $foreignUser->id,
            'amount' => '1000.00',
            'paid_at' => '2027-05-10 10:00:00',
            'payment_method' => PaymentMethod::Cash->value,
        ]);

        $this->assertSame($organization->id, $result->payment->organization_id);
        $this->assertSame($booking->id, $result->payment->booking_id);
        $this->assertSame($quotation->id, $result->payment->quotation_id);
        $this->assertSame($result->billing->id, $result->payment->billing_id);
        $this->assertSame($user->id, $result->payment->created_by);
        $this->assertSame(PaymentStatus::Posted, $result->payment->status);
    }

    public function test_cancelled_booking_rejects_new_payment(): void
    {
        [, $user, $booking, $quotation] = $this->acceptedQuotationFixture();
        $booking->update([
            'status' => BookingStatus::Cancelled,
            'cancelled_at' => '2027-05-09 09:00:00',
            'cancelled_by' => $user->id,
            'cancellation_reason' => 'Event cancelled.',
        ]);

        $this->expectException(ValidationException::class);

        $this->record($user, $quotation, '1000.00');
    }

    public function test_completed_booking_with_existing_billing_can_settle_balance_without_status_change(): void
    {
        [, $user, $booking, $quotation] = $this->acceptedQuotationFixture();
        $this->record($user, $quotation, '3000.00');
        $booking->update([
            'status' => BookingStatus::Completed,
            'completed_at' => '2027-05-09 09:00:00',
            'completed_by' => $user->id,
        ]);

        $result = $this->record($user, $quotation, '5000.00');

        $this->assertSame(PaymentSummary::PAID, $result->summary->paymentStatus);
        $this->assertSame(BookingStatus::Completed, $booking->fresh()->status);
    }

    public function test_failure_after_number_allocation_rolls_back_billing_items_payment_booking_and_sequence(): void
    {
        [$organization, $user, , $existingQuotation] = $this->acceptedQuotationFixture();
        Billing::factory()->forQuotation($existingQuotation)->create([
            'billing_number' => 'INV-2027-000001',
        ]);
        $targetBooking = Booking::factory()->create([
            'organization_id' => $organization->id,
            'customer_id' => $existingQuotation->booking->customer_id,
            'event_type_id' => $existingQuotation->booking->event_type_id,
            'status' => BookingStatus::Quoted,
            'created_by' => $user->id,
        ]);
        $targetQuotation = Quotation::factory()->accepted()->forBooking($targetBooking)->create();
        QuotationItem::factory()->forQuotation($targetQuotation)->create();

        try {
            $this->record($user, $targetQuotation, '1000.00');
            $this->fail('The duplicate Billing number was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('billing', $exception->errors());
        }

        $this->assertDatabaseMissing('billings', ['quotation_id' => $targetQuotation->id]);
        $this->assertDatabaseMissing('billing_items', ['quotation_id' => $targetQuotation->id]);
        $this->assertDatabaseMissing('payments', ['quotation_id' => $targetQuotation->id]);
        $this->assertSame(BookingStatus::Quoted, $targetBooking->fresh()->status);
        $this->assertDatabaseMissing('document_sequences', [
            'organization_id' => $organization->id,
            'document_type' => 'BILLING',
        ]);
    }

    public function test_payment_persistence_failure_rolls_back_created_billing_items_and_number(): void
    {
        [$organization, $user, $booking, $quotation] = $this->acceptedQuotationFixture();
        $this->app->instance(PaymentSummaryCalculator::class, new class extends PaymentSummaryCalculator
        {
            public function format(int $cents): string
            {
                return '0.00';
            }
        });

        try {
            $this->record($user, $quotation, '1000.00');
            $this->fail('The invalid persisted Payment was accepted.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseMissing('billings', ['quotation_id' => $quotation->id]);
        $this->assertDatabaseMissing('billing_items', ['quotation_id' => $quotation->id]);
        $this->assertDatabaseMissing('payments', ['quotation_id' => $quotation->id]);
        $this->assertSame(BookingStatus::Quoted, $booking->fresh()->status);
        $this->assertDatabaseMissing('document_sequences', [
            'organization_id' => $organization->id,
            'document_type' => 'BILLING',
        ]);
    }

    public function test_void_recalculates_summary_preserves_history_and_never_demotes_booking(): void
    {
        [, $user, $booking, $quotation] = $this->acceptedQuotationFixture();
        $first = $this->record($user, $quotation, '3000.00')->payment;
        $second = $this->record($user, $quotation, '2000.00')->payment;

        $partial = app(VoidPayment::class)->handle($user, $second->id, 'Duplicate deposit.');
        $unpaid = app(VoidPayment::class)->handle($user, $first->id, 'Payment reversed by bank.');

        $this->assertSame(PaymentStatus::Voided, $partial->payment->status);
        $this->assertNotNull($partial->payment->voided_at);
        $this->assertTrue($partial->payment->voider->is($user));
        $this->assertSame('Duplicate deposit.', $partial->payment->void_reason);
        $this->assertSame('3000.00', $partial->summary->amountPaid);
        $this->assertSame('5000.00', $partial->summary->remainingBalance);
        $this->assertSame(PaymentSummary::PARTIALLY_PAID, $partial->summary->paymentStatus);
        $this->assertSame('0.00', $unpaid->summary->amountPaid);
        $this->assertSame('8000.00', $unpaid->summary->remainingBalance);
        $this->assertSame(PaymentSummary::UNPAID, $unpaid->summary->paymentStatus);
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
        $this->assertSame(2, Payment::query()->where('quotation_id', $quotation->id)->count());
    }

    public function test_void_requires_reason_and_replay_is_rejected(): void
    {
        [, $user, , $quotation] = $this->acceptedQuotationFixture();
        $payment = $this->record($user, $quotation, '1000.00')->payment;

        try {
            app(VoidPayment::class)->handle($user, $payment->id, '   ');
            $this->fail('A blank void reason was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('void_reason', $exception->errors());
        }

        app(VoidPayment::class)->handle($user, $payment->id, 'Incorrect reference.');

        try {
            app(VoidPayment::class)->handle($user, $payment->id, 'Replay.');
            $this->fail('A voided Payment was voided again.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payment', $exception->errors());
        }

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => PaymentStatus::Voided->value,
            'void_reason' => 'Incorrect reference.',
        ]);
    }

    public function test_cross_tenant_quotation_and_payment_cannot_be_mutated(): void
    {
        [, $owner, , $quotation] = $this->acceptedQuotationFixture();
        $payment = $this->record($owner, $quotation, '1000.00')->payment;
        $foreignOrganization = Organization::factory()->create();
        $foreignUser = User::factory()->for($foreignOrganization)->create();

        try {
            $this->record($foreignUser, $quotation, '1000.00');
            $this->fail('A foreign tenant recorded a Payment.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }

        try {
            app(VoidPayment::class)->handle($foreignUser, $payment->id, 'Foreign void attempt.');
            $this->fail('A foreign tenant voided a Payment.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(PaymentStatus::Posted, $payment->fresh()->status);
    }

    /**
     * @return array{Organization, User, Booking, Quotation}
     */
    private function acceptedQuotationFixture(): array
    {
        $organization = Organization::factory()->create();
        BusinessSetting::factory()->for($organization)->create([
            'display_name' => 'Historical Events Co.',
            'email' => 'historical@example.test',
            'logo_path' => 'business-logos/historical.png',
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
        $mirrorSource = BookingService::factory()->forBooking($booking)->create([
            'duration_minutes' => 120,
        ]);
        $videoSource = BookingService::factory()
            ->forBooking($booking)
            ->forPackage($mirrorSource->package, $mirrorSource->service)
            ->create([
                'duration_minutes' => 180,
                'sort_order' => 1,
            ]);
        $quotation = Quotation::factory()->accepted()->forBooking($booking)->create([
            'business_display_name' => 'Historical Events Co.',
            'business_email' => 'historical@example.test',
            'business_phone' => '09170000000',
            'business_address' => 'Historical Business Address',
            'business_logo_path' => 'business-logos/historical.png',
            'customer_name' => 'Historical Customer',
            'customer_email' => 'customer@example.test',
            'customer_phone' => '09171111111',
            'customer_address' => 'Historical Customer Address',
            'event_type_name' => 'Historical Event Type',
            'event_name' => 'Historical Event',
            'event_date' => '2027-06-15',
            'venue_name' => 'Historical Venue',
            'venue_address' => 'Historical Venue Address',
            'contact_person' => 'Historical Contact',
            'contact_number' => '09172222222',
            'subtotal' => '7500.00',
            'transportation_fee' => '500.00',
            'crew_meal_fee' => '250.00',
            'discount_amount' => '250.00',
            'total' => '8000.00',
        ]);
        QuotationItem::factory()->forQuotation($quotation)->create([
            'booking_service_id' => $mirrorSource->id,
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
            'booking_service_id' => $videoSource->id,
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
        ?string $reference = null,
        PaymentMethod $method = PaymentMethod::Cash,
    ): PaymentMutationResult {
        return app(RecordPayment::class)->handle($user, $quotation->id, [
            'amount' => $amount,
            'paid_at' => $paidAt,
            'payment_method' => $method->value,
            'reference_number' => $reference,
            'internal_note' => 'Recorded by owner.',
        ]);
    }

    /** @return array<string, string> */
    private function billingHeaderMap(): array
    {
        return [
            'quotation_number' => 'quotation_number',
            'business_display_name' => 'business_display_name',
            'business_email' => 'business_email',
            'business_phone' => 'business_phone',
            'business_address' => 'business_address',
            'business_logo_path' => 'business_logo_path',
            'customer_name' => 'customer_name',
            'customer_email' => 'customer_email',
            'customer_phone' => 'customer_phone',
            'customer_address' => 'customer_address',
            'event_type_name' => 'event_type_name',
            'event_name' => 'event_name',
            'event_date' => 'event_date',
            'venue_name' => 'venue_name',
            'venue_address' => 'venue_address',
            'contact_person' => 'contact_person',
            'contact_number' => 'contact_number',
            'subtotal' => 'subtotal',
            'transportation_fee' => 'transportation_fee',
            'crew_meal_fee' => 'crew_meal_fee',
            'discount_amount' => 'discount_amount',
            'total' => 'total',
        ];
    }
}
