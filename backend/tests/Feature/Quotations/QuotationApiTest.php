<?php

namespace Tests\Feature\Quotations;

use App\Actions\Quotations\AcceptQuotation;
use App\Actions\Quotations\CreateQuotation;
use App\Actions\Quotations\SendQuotation;
use App\Enums\BookingStatus;
use App\Enums\QuotationStatus;
use App\Models\Booking;
use App\Models\BookingService;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QuotationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_quotation_api_requires_authentication(): void
    {
        $this->getJson('/api/v1/quotations')->assertUnauthorized();
        $this->getJson('/api/v1/quotations/1')->assertUnauthorized();
        $this->postJson('/api/v1/bookings/1/quotations')->assertUnauthorized();
        $this->patchJson('/api/v1/quotations/1')->assertUnauthorized();
        $this->postJson('/api/v1/quotations/1/send')->assertUnauthorized();
    }

    public function test_owner_creates_draft_with_immutable_snapshots_items_and_exact_totals(): void
    {
        [$user, $organization] = $this->tenant();
        $booking = $this->booking($organization, $user);

        $response = $this->actingAs($user)->postJson("/api/v1/bookings/{$booking->id}/quotations", [
            'transportation_fee' => '100.10',
            'crew_meal_fee' => '50.20',
            'discount_amount' => '25.05',
            'valid_until' => '2099-12-31',
        ])->assertCreated()
            ->assertJsonPath('status', 'DRAFT')
            ->assertJsonPath('booking.id', $booking->id)
            ->assertJsonPath('booking.booking_number', $booking->booking_number)
            ->assertJsonPath('valid_until', '2099-12-31')
            ->assertJsonPath('seller_snapshot.display_name', $organization->businessSetting->display_name)
            ->assertJsonPath('customer_snapshot.name', $booking->customer_name)
            ->assertJsonPath('event_snapshot.event_name', $booking->event_name)
            ->assertJsonPath('currency', 'PHP')
            ->assertJsonPath('subtotal', '15000.50')
            ->assertJsonPath('transportation_fee', '100.10')
            ->assertJsonPath('crew_meal_fee', '50.20')
            ->assertJsonPath('discount_amount', '25.05')
            ->assertJsonPath('total', '15125.75')
            ->assertJsonPath('items.0.service_name', 'Stored API Service')
            ->assertJsonPath('items.0.package_name', 'Stored API Package')
            ->assertJsonPath('items.0.start_at', '2027-06-15 18:00')
            ->assertJsonPath('items.0.end_at', '2027-06-15 21:00')
            ->assertJsonPath('items.0.unit_rate', '7500.25')
            ->assertJsonPath('items.0.line_total', '15000.50')
            ->assertJsonMissingPath('organization_id')
            ->assertJsonMissingPath('booking_id');

        $this->assertMatchesRegularExpression('/^QT-\d{4}-\d{6}$/', $response->json('quotation_number'));
    }

    public function test_create_rejects_document_controlled_fields(): void
    {
        [$user, $organization] = $this->tenant();
        [, $foreignOrganization] = $this->tenant();
        $booking = $this->booking($organization, $user);

        $this->actingAs($user)->postJson("/api/v1/bookings/{$booking->id}/quotations", [
            'organization_id' => $foreignOrganization->id,
            'booking_id' => 999999,
            'quotation_number' => 'HOSTILE-1',
            'status' => 'ACCEPTED',
            'items' => [['line_total' => '0.01']],
            'subtotal' => '0.01',
            'total' => '0.01',
            'customer_snapshot' => ['name' => 'Hostile Customer'],
            'event_snapshot' => ['event_name' => 'Hostile Event'],
            'service_id' => 999999,
            'unit_rate' => '0.01',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'organization_id',
                'booking_id',
                'quotation_number',
                'status',
                'items',
                'subtotal',
                'total',
                'customer_snapshot',
                'event_snapshot',
                'service_id',
                'unit_rate',
            ]);

        $this->assertDatabaseCount('quotations', 0);
    }

    public function test_foreign_and_ineligible_bookings_cannot_receive_drafts(): void
    {
        [$owner, $organization] = $this->tenant();
        [$foreignUser, $foreignOrganization] = $this->tenant();
        $booking = $this->booking($organization, $owner);
        $foreignBooking = $this->booking($foreignOrganization, $foreignUser);

        $this->actingAs($owner)
            ->postJson("/api/v1/bookings/{$foreignBooking->id}/quotations")
            ->assertNotFound();

        $booking->update(['status' => BookingStatus::Quoted]);
        $this->actingAs($owner)
            ->postJson("/api/v1/bookings/{$booking->id}/quotations")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_index_is_tenant_scoped_paginated_filterable_and_searchable(): void
    {
        [$user, $organization] = $this->tenant();
        [$foreignUser, $foreignOrganization] = $this->tenant();
        $booking = $this->booking($organization, $user);
        $foreignBooking = $this->booking($foreignOrganization, $foreignUser);
        Quotation::factory()->forBooking($booking)->rejected()->create([
            'quotation_number' => 'QT-2027-000001',
            'customer_name' => 'First Customer',
            'created_at' => '2027-01-01 10:00:00',
        ]);
        $match = Quotation::factory()->forBooking($booking)->outdated()->create([
            'quotation_number' => 'QT-2027-000002',
            'customer_name' => 'Searchable Customer',
            'created_at' => '2027-01-02 10:00:00',
        ]);
        Quotation::factory()->forBooking($booking)->cancelled()->create([
            'quotation_number' => 'QT-2027-000003',
            'customer_name' => 'Third Customer',
            'created_at' => '2027-01-03 10:00:00',
        ]);
        Quotation::factory()->forBooking($foreignBooking)->outdated()->create([
            'quotation_number' => 'QT-FOREIGN',
            'customer_name' => 'Searchable Customer',
        ]);

        $this->actingAs($user)->getJson('/api/v1/quotations?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.quotation_number', 'QT-2027-000003')
            ->assertJsonPath('data.0.booking.id', $booking->id)
            ->assertJsonPath('data.0.booking.booking_number', $booking->booking_number);

        $this->actingAs($user)->getJson('/api/v1/quotations?status=OUTDATED&search=Searchable')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $match->id)
            ->assertJsonMissingPath('data.0.items');
    }

    public function test_show_returns_tenant_owned_detail_with_items_and_exact_money(): void
    {
        [$user, $organization] = $this->tenant();
        $booking = $this->booking($organization, $user);
        $quotation = app(CreateQuotation::class)->handle($user, $booking->id);

        $this->actingAs($user)->getJson("/api/v1/quotations/{$quotation->id}")
            ->assertOk()
            ->assertJsonPath('id', $quotation->id)
            ->assertJsonPath('booking.id', $booking->id)
            ->assertJsonPath('subtotal', '15000.50')
            ->assertJsonPath('total', '15000.50')
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.quantity', 2)
            ->assertJsonMissingPath('organization_id');
    }

    public function test_draft_adjustments_and_nullable_valid_until_can_be_updated(): void
    {
        [$user, $organization] = $this->tenant();
        $booking = $this->booking($organization, $user);
        $quotation = app(CreateQuotation::class)->handle($user, $booking->id, [
            'valid_until' => '2099-12-31',
        ]);

        $this->actingAs($user)->patchJson("/api/v1/quotations/{$quotation->id}", [
            'transportation_fee' => '100.10',
            'crew_meal_fee' => '50.20',
            'discount_amount' => '25.05',
            'valid_until' => null,
        ])->assertOk()
            ->assertJsonPath('valid_until', null)
            ->assertJsonPath('subtotal', '15000.50')
            ->assertJsonPath('transportation_fee', '100.10')
            ->assertJsonPath('crew_meal_fee', '50.20')
            ->assertJsonPath('discount_amount', '25.05')
            ->assertJsonPath('total', '15125.75')
            ->assertJsonPath('items.0.line_total', '15000.50');
    }

    public function test_update_rejects_client_controlled_document_fields_without_mutation(): void
    {
        [$user, $organization] = $this->tenant();
        $booking = $this->booking($organization, $user);
        $quotation = app(CreateQuotation::class)->handle($user, $booking->id);
        $item = $quotation->items->first();

        $this->actingAs($user)->putJson("/api/v1/quotations/{$quotation->id}", [
            'status' => 'ACCEPTED',
            'items' => [['id' => $item->id, 'line_total' => '0.01']],
            'subtotal' => '0.01',
            'total' => '0.01',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['status', 'items', 'subtotal', 'total']);

        $this->assertSame(QuotationStatus::Draft, $quotation->fresh()->status);
        $this->assertSame('15000.50', $quotation->fresh()->total);
        $this->assertSame('15000.50', $item->fresh()->line_total);
    }

    public function test_sent_and_terminal_quotations_reject_draft_updates(): void
    {
        [$user, $organization] = $this->tenant();
        $booking = $this->booking($organization, $user);
        $sent = $this->sent($user, $booking);

        $this->actingAs($user)->patchJson("/api/v1/quotations/{$sent->id}", [
            'transportation_fee' => '1.00',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');

        $accepted = app(AcceptQuotation::class)->handle($user, $sent->id);
        $this->actingAs($user)->patchJson("/api/v1/quotations/{$accepted->id}", [
            'transportation_fee' => '1.00',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_send_endpoint_transitions_draft_and_booking(): void
    {
        [$user, $organization] = $this->tenant();
        $booking = $this->booking($organization, $user);
        $draft = $this->draft($user, $booking);

        $this->actingAs($user)->postJson("/api/v1/quotations/{$draft->id}/send")
            ->assertOk()
            ->assertJsonPath('status', 'SENT')
            ->assertJsonPath('items.0.line_total', '15000.50');

        $this->assertSame(BookingStatus::Quoted, $booking->fresh()->status);
    }

    public function test_accept_endpoint_accepts_sent_without_confirming_booking(): void
    {
        [$user, $organization] = $this->tenant();
        $booking = $this->booking($organization, $user);
        $sent = $this->sent($user, $booking);

        $this->actingAs($user)->postJson("/api/v1/quotations/{$sent->id}/accept")
            ->assertOk()
            ->assertJsonPath('status', 'ACCEPTED');

        $this->assertSame(BookingStatus::Quoted, $booking->fresh()->status);
    }

    public function test_reject_endpoint_rejects_sent_and_returns_booking_to_pending(): void
    {
        [$user, $organization] = $this->tenant();
        $booking = $this->booking($organization, $user);
        $sent = $this->sent($user, $booking);

        $this->actingAs($user)->postJson("/api/v1/quotations/{$sent->id}/reject")
            ->assertOk()
            ->assertJsonPath('status', 'REJECTED');

        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
    }

    #[DataProvider('cancellableQuotationStates')]
    public function test_cancel_endpoint_handles_draft_and_sent(string $state): void
    {
        [$user, $organization] = $this->tenant();
        $booking = $this->booking($organization, $user);
        $quotation = $state === 'sent'
            ? $this->sent($user, $booking)
            : $this->draft($user, $booking);

        $this->actingAs($user)->postJson("/api/v1/quotations/{$quotation->id}/cancel")
            ->assertOk()
            ->assertJsonPath('status', 'CANCELLED');

        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
    }

    /** @return array<string, array{string}> */
    public static function cancellableQuotationStates(): array
    {
        return [
            'draft' => ['draft'],
            'sent' => ['sent'],
        ];
    }

    public function test_invalid_transition_returns_validation_response(): void
    {
        [$user, $organization] = $this->tenant();
        $booking = $this->booking($organization, $user);
        $draft = $this->draft($user, $booking);

        $this->actingAs($user)->postJson("/api/v1/quotations/{$draft->id}/accept")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
        $this->assertSame(QuotationStatus::Draft, $draft->fresh()->status);
        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
    }

    public function test_no_delete_outdate_expire_or_arbitrary_status_endpoint_exists(): void
    {
        [$user, $organization] = $this->tenant();
        $booking = $this->booking($organization, $user);
        $draft = $this->draft($user, $booking);

        $this->actingAs($user)->deleteJson("/api/v1/quotations/{$draft->id}")
            ->assertMethodNotAllowed();
        $this->actingAs($user)->postJson("/api/v1/quotations/{$draft->id}/outdate")
            ->assertNotFound();
        $this->actingAs($user)->postJson("/api/v1/quotations/{$draft->id}/expire")
            ->assertNotFound();
        $this->actingAs($user)->patchJson("/api/v1/quotations/{$draft->id}", [
            'status' => 'SENT',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_booking_detail_contains_ordered_history_summaries_without_items(): void
    {
        [$user, $organization] = $this->tenant();
        $booking = $this->booking($organization, $user);
        $oldest = Quotation::factory()->forBooking($booking)->rejected()->create([
            'quotation_number' => 'QT-2027-000001',
            'created_at' => '2027-01-01 10:00:00',
        ]);
        $middle = Quotation::factory()->forBooking($booking)->outdated()->create([
            'quotation_number' => 'QT-2027-000002',
            'created_at' => '2027-01-02 10:00:00',
        ]);
        $newest = Quotation::factory()->forBooking($booking)->cancelled()->create([
            'quotation_number' => 'QT-2027-000003',
            'created_at' => '2027-01-03 10:00:00',
        ]);
        foreach ([$oldest, $middle, $newest] as $quotation) {
            QuotationItem::factory()->forQuotation($quotation)->create();
        }

        $this->actingAs($user)->getJson("/api/v1/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonCount(3, 'quotations')
            ->assertJsonPath('quotations.0.id', $newest->id)
            ->assertJsonPath('quotations.1.id', $middle->id)
            ->assertJsonPath('quotations.2.id', $oldest->id)
            ->assertJsonMissingPath('quotations.0.items');

        $this->actingAs($user)->getJson('/api/v1/bookings')
            ->assertOk()
            ->assertJsonMissingPath('data.0.quotations');
    }

    public function test_every_quotation_operation_is_tenant_scoped(): void
    {
        [$owner, $organization] = $this->tenant();
        [$foreignUser, $foreignOrganization] = $this->tenant();
        $booking = $this->booking($organization, $owner);
        $foreignBooking = $this->booking($foreignOrganization, $foreignUser);
        $quotation = $this->draft($owner, $booking);
        $foreignQuotation = Quotation::factory()->forBooking($foreignBooking)->rejected()->create();

        $this->actingAs($foreignUser)->getJson('/api/v1/quotations')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $foreignQuotation->id);
        $this->actingAs($foreignUser)->getJson("/api/v1/quotations/{$quotation->id}")
            ->assertNotFound();
        $this->actingAs($foreignUser)->patchJson("/api/v1/quotations/{$quotation->id}", [
            'transportation_fee' => '1.00',
        ])->assertNotFound();
        $this->actingAs($foreignUser)->postJson("/api/v1/bookings/{$booking->id}/quotations")
            ->assertNotFound();

        foreach (['send', 'accept', 'reject', 'cancel'] as $transition) {
            $this->actingAs($foreignUser)
                ->postJson("/api/v1/quotations/{$quotation->id}/{$transition}")
                ->assertNotFound();
        }

        $this->assertSame(QuotationStatus::Draft, $quotation->fresh()->status);
    }

    /** @return array{User, Organization} */
    private function tenant(): array
    {
        $organization = Organization::factory()->withBusinessSettings()->create();

        return [User::factory()->for($organization)->create(), $organization];
    }

    private function booking(Organization $organization, User $user): Booking
    {
        $booking = Booking::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $user->id,
            'customer_name' => 'Stored API Customer',
            'customer_email' => 'customer@example.test',
            'customer_phone' => '09170000000',
            'customer_address' => 'Stored Customer Address',
            'event_type_name' => 'Stored API Event Type',
            'event_name' => 'Stored API Occasion',
            'venue_name' => 'Stored API Venue',
            'venue_address' => 'Stored Venue Address',
            'contact_person' => 'Stored API Contact',
            'contact_number' => '09171111111',
        ]);
        BookingService::factory()->forBooking($booking)->create([
            'service_name' => 'Stored API Service',
            'package_name' => 'Stored API Package',
            'unit_rate' => '7500.25',
            'line_total' => '15000.50',
            'quantity' => 2,
        ]);

        return $booking;
    }

    private function draft(User $user, Booking $booking): Quotation
    {
        return app(CreateQuotation::class)->handle($user, $booking->id, [
            'valid_until' => '2099-12-31',
        ]);
    }

    private function sent(User $user, Booking $booking): Quotation
    {
        return app(SendQuotation::class)->handle($user, $this->draft($user, $booking)->id);
    }
}
