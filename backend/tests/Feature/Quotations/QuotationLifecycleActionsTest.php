<?php

namespace Tests\Feature\Quotations;

use App\Actions\Quotations\AcceptQuotation;
use App\Actions\Quotations\CancelQuotation;
use App\Actions\Quotations\CreateQuotation;
use App\Actions\Quotations\ExpireQuotations;
use App\Actions\Quotations\RejectQuotation;
use App\Actions\Quotations\SendQuotation;
use App\Enums\BookingStatus;
use App\Enums\QuotationStatus;
use App\Models\Booking;
use App\Models\BookingService;
use App\Models\BusinessSetting;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QuotationLifecycleActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_sends_and_transitions_pending_booking_to_quoted(): void
    {
        [$user, $organization] = $this->tenant();
        $booking = $this->booking($organization, $user);
        $this->travelTo(CarbonImmutable::parse('2027-09-15 09:00:00', 'Asia/Manila'));
        $draft = $this->draft($user, $booking, '2027-09-16');

        $sent = app(SendQuotation::class)->handle($user, $draft->id);

        $this->assertSame(QuotationStatus::Sent, $sent->status);
        $this->assertSame(BookingStatus::Quoted, $sent->booking->status);
        $this->assertSame(now('UTC')->format('Y-m-d H:i:s'), $sent->getRawOriginal('sent_at'));
        $this->assertNull($sent->accepted_at);
        $this->assertNull($sent->closed_at);
    }

    public function test_send_requires_valid_until(): void
    {
        [$user, $organization] = $this->tenant();
        $booking = $this->booking($organization, $user);
        $draft = $this->draft($user, $booking);

        $this->expectValidationError(
            'valid_until',
            fn () => app(SendQuotation::class)->handle($user, $draft->id),
        );

        $this->assertSame(QuotationStatus::Draft, $draft->fresh()->status);
        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
    }

    public function test_send_rejects_a_valid_until_before_the_current_manila_date(): void
    {
        [$user, $organization] = $this->tenant();
        $booking = $this->booking($organization, $user);
        $this->travelTo(CarbonImmutable::parse('2027-09-15 16:30:00', 'UTC'));
        $draft = $this->draft($user, $booking, '2027-09-15');

        $this->expectValidationError(
            'valid_until',
            fn () => app(SendQuotation::class)->handle($user, $draft->id),
        );

        $this->assertSame(QuotationStatus::Draft, $draft->fresh()->status);
    }

    public function test_send_accepts_valid_until_equal_to_the_current_manila_date(): void
    {
        [$user, $organization] = $this->tenant();
        $booking = $this->booking($organization, $user);
        $this->travelTo(CarbonImmutable::parse('2027-09-15 15:59:00', 'UTC'));
        $draft = $this->draft($user, $booking, '2027-09-15');

        $sent = app(SendQuotation::class)->handle($user, $draft->id);

        $this->assertSame(QuotationStatus::Sent, $sent->status);
    }

    public function test_send_requires_at_least_one_item(): void
    {
        [$user, $organization] = $this->tenant();
        $booking = $this->booking($organization, $user);
        $draft = $this->draft($user, $booking, '2027-09-16');
        $draft->items()->delete();

        $this->expectValidationError(
            'items',
            fn () => app(SendQuotation::class)->handle($user, $draft->id),
        );
    }

    public function test_send_requires_a_positive_total(): void
    {
        [$user, $organization] = $this->tenant();
        $booking = $this->booking($organization, $user);
        $quotation = Quotation::factory()->forBooking($booking)->create([
            'valid_until' => '2027-09-16',
            'subtotal' => '0.00',
            'transportation_fee' => '0.00',
            'crew_meal_fee' => '0.00',
            'discount_amount' => '0.00',
            'total' => '0.00',
        ]);
        QuotationItem::factory()->forQuotation($quotation)->create([
            'unit_rate' => '0.00',
            'line_total' => '0.00',
        ]);

        $this->expectValidationError(
            'total',
            fn () => app(SendQuotation::class)->handle($user, $quotation->id),
        );
    }

    public function test_send_rejects_non_draft_and_replay(): void
    {
        [$user, $organization] = $this->tenant();
        $booking = $this->booking($organization, $user);
        $sent = $this->sent($user, $booking);
        $sentAt = $sent->getRawOriginal('sent_at');

        $this->expectValidationError(
            'status',
            fn () => app(SendQuotation::class)->handle($user, $sent->id),
        );

        $this->assertSame($sentAt, $sent->fresh()->getRawOriginal('sent_at'));
    }

    public function test_send_rejects_an_inconsistent_booking_status(): void
    {
        [$user, $organization] = $this->tenant();
        $booking = $this->booking($organization, $user);
        $draft = $this->draft($user, $booking, '2027-09-16');
        $booking->update(['status' => BookingStatus::Quoted]);

        $this->expectValidationError(
            'booking_status',
            fn () => app(SendQuotation::class)->handle($user, $draft->id),
        );

        $this->assertSame(QuotationStatus::Draft, $draft->fresh()->status);
        $this->assertSame(BookingStatus::Quoted, $booking->fresh()->status);
    }

    public function test_foreign_tenant_cannot_send_a_quotation(): void
    {
        [$user, $organization] = $this->tenant();
        [$foreignUser] = $this->tenant();
        $booking = $this->booking($organization, $user);
        $draft = $this->draft($user, $booking, '2027-09-16');

        $this->expectException(ModelNotFoundException::class);

        app(SendQuotation::class)->handle($foreignUser, $draft->id);
    }

    public function test_sent_quotation_can_be_accepted_without_confirming_booking(): void
    {
        [$user, $organization] = $this->tenant();
        $booking = $this->booking($organization, $user);
        $this->travelTo(CarbonImmutable::parse('2027-09-15 09:00:00', 'Asia/Manila'));
        $sent = $this->sent($user, $booking, '2027-09-16');
        $sentAt = $sent->getRawOriginal('sent_at');
        $this->travelTo(CarbonImmutable::parse('2027-09-15 10:00:00', 'Asia/Manila'));

        $accepted = app(AcceptQuotation::class)->handle($user, $sent->id);

        $this->assertSame(QuotationStatus::Accepted, $accepted->status);
        $this->assertSame(BookingStatus::Quoted, $accepted->booking->status);
        $this->assertSame($sentAt, $accepted->getRawOriginal('sent_at'));
        $this->assertSame(now('UTC')->format('Y-m-d H:i:s'), $accepted->getRawOriginal('accepted_at'));
        $this->assertSame($accepted->getRawOriginal('accepted_at'), $accepted->getRawOriginal('closed_at'));
    }

    public function test_expired_sent_quotation_cannot_be_accepted(): void
    {
        [$user, $organization] = $this->tenant();
        $booking = $this->booking($organization, $user);
        $this->travelTo(CarbonImmutable::parse('2027-09-15 09:00:00', 'Asia/Manila'));
        $sent = $this->sent($user, $booking, '2027-09-15');
        $this->travelTo(CarbonImmutable::parse('2027-09-16 00:01:00', 'Asia/Manila'));

        $this->expectValidationError(
            'valid_until',
            fn () => app(AcceptQuotation::class)->handle($user, $sent->id),
        );

        $this->assertSame(QuotationStatus::Sent, $sent->fresh()->status);
        $this->assertSame(BookingStatus::Quoted, $booking->fresh()->status);
    }

    public function test_accept_allows_valid_until_equal_to_the_current_manila_date(): void
    {
        [$user, $organization] = $this->tenant();
        $booking = $this->booking($organization, $user);
        $this->travelTo(CarbonImmutable::parse('2027-09-15 09:00:00', 'Asia/Manila'));
        $sent = $this->sent($user, $booking, '2027-09-15');

        $accepted = app(AcceptQuotation::class)->handle($user, $sent->id);

        $this->assertSame(QuotationStatus::Accepted, $accepted->status);
        $this->assertSame(BookingStatus::Quoted, $accepted->booking->status);
    }

    #[DataProvider('nonSentStatuses')]
    public function test_accept_rejects_every_non_sent_status(string $status): void
    {
        [$user, $organization] = $this->tenant();
        $booking = $this->booking($organization, $user);
        $quotation = $this->draft($user, $booking, '2027-09-16');
        $quotation->update(['status' => $status]);

        $this->expectValidationError(
            'status',
            fn () => app(AcceptQuotation::class)->handle($user, $quotation->id),
        );
    }

    /** @return array<string, array{string}> */
    public static function nonSentStatuses(): array
    {
        return [
            'draft' => [QuotationStatus::Draft->value],
            'accepted' => [QuotationStatus::Accepted->value],
            'rejected' => [QuotationStatus::Rejected->value],
            'cancelled' => [QuotationStatus::Cancelled->value],
            'expired' => [QuotationStatus::Expired->value],
            'outdated' => [QuotationStatus::Outdated->value],
        ];
    }

    #[DataProvider('sentTerminalActions')]
    public function test_sent_terminal_actions_reject_an_inconsistent_booking_status(string $action): void
    {
        [$user, $organization] = $this->tenant();
        $booking = $this->booking($organization, $user);
        $sent = $this->sent($user, $booking);
        $booking->refresh()->update(['status' => BookingStatus::Pending]);

        $this->expectValidationError(
            'booking_status',
            fn () => app($action)->handle($user, $sent->id),
        );

        $this->assertSame(QuotationStatus::Sent, $sent->fresh()->status);
    }

    /** @return array<string, array{class-string}> */
    public static function sentTerminalActions(): array
    {
        return [
            'accept' => [AcceptQuotation::class],
            'reject' => [RejectQuotation::class],
            'cancel' => [CancelQuotation::class],
        ];
    }

    public function test_accepted_quotation_prevents_later_draft_creation(): void
    {
        [$user, $organization] = $this->tenant();
        $booking = $this->booking($organization, $user);
        $accepted = app(AcceptQuotation::class)->handle($user, $this->sent($user, $booking)->id);
        $booking->refresh()->update(['status' => BookingStatus::Pending]);

        $this->expectValidationError(
            'quotation',
            fn () => app(CreateQuotation::class)->handle($user, $booking->id),
        );

        $this->assertSame(QuotationStatus::Accepted, $accepted->fresh()->status);
    }

    public function test_sent_quotation_can_be_rejected_and_a_new_draft_created(): void
    {
        [$user, $organization] = $this->tenant();
        $booking = $this->booking($organization, $user);
        $sent = $this->sent($user, $booking);

        $rejected = app(RejectQuotation::class)->handle($user, $sent->id);

        $this->assertSame(QuotationStatus::Rejected, $rejected->status);
        $this->assertNotNull($rejected->closed_at);
        $this->assertSame(BookingStatus::Pending, $rejected->booking->status);

        $newDraft = app(CreateQuotation::class)->handle($user, $booking->id);
        $this->assertSame(QuotationStatus::Draft, $newDraft->status);
        $this->assertNotSame($rejected->quotation_number, $newDraft->quotation_number);
    }

    public function test_reject_replay_is_rejected(): void
    {
        [$user, $organization] = $this->tenant();
        $booking = $this->booking($organization, $user);
        $rejected = app(RejectQuotation::class)->handle($user, $this->sent($user, $booking)->id);

        $this->expectValidationError(
            'status',
            fn () => app(RejectQuotation::class)->handle($user, $rejected->id),
        );
    }

    public function test_draft_can_be_cancelled_without_changing_pending_booking(): void
    {
        [$user, $organization] = $this->tenant();
        $booking = $this->booking($organization, $user);
        $draft = $this->draft($user, $booking);

        $cancelled = app(CancelQuotation::class)->handle($user, $draft->id);

        $this->assertSame(QuotationStatus::Cancelled, $cancelled->status);
        $this->assertNotNull($cancelled->closed_at);
        $this->assertNull($cancelled->sent_at);
        $this->assertSame(BookingStatus::Pending, $cancelled->booking->status);
    }

    public function test_draft_cancellation_rejects_an_inconsistent_booking_status(): void
    {
        [$user, $organization] = $this->tenant();
        $booking = $this->booking($organization, $user);
        $draft = $this->draft($user, $booking);
        $booking->update(['status' => BookingStatus::Quoted]);

        $this->expectValidationError(
            'booking_status',
            fn () => app(CancelQuotation::class)->handle($user, $draft->id),
        );

        $this->assertSame(QuotationStatus::Draft, $draft->fresh()->status);
    }

    public function test_sent_quotation_can_be_cancelled_and_returns_booking_to_pending(): void
    {
        [$user, $organization] = $this->tenant();
        $booking = $this->booking($organization, $user);
        $sent = $this->sent($user, $booking);

        $cancelled = app(CancelQuotation::class)->handle($user, $sent->id);

        $this->assertSame(QuotationStatus::Cancelled, $cancelled->status);
        $this->assertNotNull($cancelled->sent_at);
        $this->assertNotNull($cancelled->closed_at);
        $this->assertSame(BookingStatus::Pending, $cancelled->booking->status);
    }

    public function test_terminal_quotation_cannot_be_cancelled_again(): void
    {
        [$user, $organization] = $this->tenant();
        $booking = $this->booking($organization, $user);
        $cancelled = app(CancelQuotation::class)->handle($user, $this->draft($user, $booking)->id);
        $closedAt = $cancelled->closed_at;

        $this->expectValidationError(
            'status',
            fn () => app(CancelQuotation::class)->handle($user, $cancelled->id),
        );

        $this->assertTrue($cancelled->fresh()->closed_at->equalTo($closedAt));
    }

    public function test_yesterdays_sent_quotation_expires_and_returns_booking_to_pending(): void
    {
        [$user, $organization] = $this->tenant();
        $booking = $this->booking($organization, $user);
        $this->travelTo(CarbonImmutable::parse('2027-09-15 09:00:00', 'Asia/Manila'));
        $sent = $this->sent($user, $booking, '2027-09-15');
        $this->travelTo(CarbonImmutable::parse('2027-09-16 00:01:00', 'Asia/Manila'));

        $count = app(ExpireQuotations::class)->handle($organization);

        $this->assertSame(1, $count);
        $this->assertSame(QuotationStatus::Expired, $sent->fresh()->status);
        $this->assertNotNull($sent->fresh()->closed_at);
        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
    }

    public function test_today_and_future_sent_quotations_do_not_expire(): void
    {
        [$todayUser, $todayOrganization] = $this->tenant();
        [$futureUser, $futureOrganization] = $this->tenant();
        $this->travelTo(CarbonImmutable::parse('2027-09-15 09:00:00', 'Asia/Manila'));
        $todayBooking = $this->booking($todayOrganization, $todayUser);
        $futureBooking = $this->booking($futureOrganization, $futureUser);
        $today = $this->sent($todayUser, $todayBooking, '2027-09-15');
        $future = $this->sent($futureUser, $futureBooking, '2027-09-16');

        $this->assertSame(0, app(ExpireQuotations::class)->handle($todayOrganization));
        $this->assertSame(0, app(ExpireQuotations::class)->handle($futureOrganization));
        $this->assertSame(QuotationStatus::Sent, $today->fresh()->status);
        $this->assertSame(QuotationStatus::Sent, $future->fresh()->status);
    }

    public function test_only_sent_quotations_are_expired(): void
    {
        [$user, $organization] = $this->tenant();
        $booking = $this->booking($organization, $user);
        $draft = $this->draft($user, $booking, '2027-09-14');
        $this->travelTo(CarbonImmutable::parse('2027-09-15 09:00:00', 'Asia/Manila'));

        $this->assertSame(0, app(ExpireQuotations::class)->handle($organization));
        $this->assertSame(QuotationStatus::Draft, $draft->fresh()->status);
        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
    }

    public function test_expiry_is_tenant_scoped(): void
    {
        [$firstUser, $firstOrganization] = $this->tenant();
        [$secondUser, $secondOrganization] = $this->tenant();
        $this->travelTo(CarbonImmutable::parse('2027-09-15 09:00:00', 'Asia/Manila'));
        $firstBooking = $this->booking($firstOrganization, $firstUser);
        $secondBooking = $this->booking($secondOrganization, $secondUser);
        $first = $this->sent($firstUser, $firstBooking, '2027-09-15');
        $second = $this->sent($secondUser, $secondBooking, '2027-09-15');
        $this->travelTo(CarbonImmutable::parse('2027-09-16 09:00:00', 'Asia/Manila'));

        $this->assertSame(1, app(ExpireQuotations::class)->handle($firstOrganization));
        $this->assertSame(QuotationStatus::Expired, $first->fresh()->status);
        $this->assertSame(QuotationStatus::Sent, $second->fresh()->status);
        $this->assertSame(BookingStatus::Quoted, $secondBooking->fresh()->status);
    }

    public function test_expiry_rejects_an_inconsistent_booking_status(): void
    {
        [$user, $organization] = $this->tenant();
        $this->travelTo(CarbonImmutable::parse('2027-09-15 09:00:00', 'Asia/Manila'));
        $booking = $this->booking($organization, $user);
        $sent = $this->sent($user, $booking, '2027-09-15');
        $booking->refresh()->update(['status' => BookingStatus::Pending]);
        $this->travelTo(CarbonImmutable::parse('2027-09-16 09:00:00', 'Asia/Manila'));

        $this->expectValidationError(
            'booking_status',
            fn () => app(ExpireQuotations::class)->handle($organization),
        );

        $this->assertSame(QuotationStatus::Sent, $sent->fresh()->status);
        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
    }

    /** @return array{User, Organization} */
    private function tenant(): array
    {
        $organization = Organization::factory()->create();
        BusinessSetting::factory()->for($organization)->create();
        $user = User::factory()->for($organization)->create();

        return [$user, $organization];
    }

    private function booking(Organization $organization, User $user): Booking
    {
        $booking = Booking::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $user->id,
        ]);
        BookingService::factory()->forBooking($booking)->create([
            'service_name' => 'Lifecycle Service',
            'package_name' => 'Lifecycle Package',
            'unit_rate' => '2500.25',
            'line_total' => '2500.25',
            'quantity' => 1,
        ]);

        return $booking;
    }

    private function draft(User $user, Booking $booking, ?string $validUntil = null): Quotation
    {
        return app(CreateQuotation::class)->handle($user, $booking->id, [
            'valid_until' => $validUntil,
        ]);
    }

    private function sent(User $user, Booking $booking, string $validUntil = '2027-09-16'): Quotation
    {
        return app(SendQuotation::class)->handle(
            $user,
            $this->draft($user, $booking, $validUntil)->id,
        );
    }

    private function expectValidationError(string $key, callable $operation): void
    {
        try {
            $operation();
            $this->fail("Expected a validation error for {$key}.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }
    }
}
