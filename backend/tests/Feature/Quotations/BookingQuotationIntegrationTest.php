<?php

namespace Tests\Feature\Quotations;

use App\Actions\Bookings\CancelBooking;
use App\Actions\Bookings\CreateBooking;
use App\Actions\Bookings\UpdateBooking;
use App\Actions\Quotations\AcceptQuotation;
use App\Actions\Quotations\CreateQuotation;
use App\Actions\Quotations\OutdateQuotation;
use App\Actions\Quotations\SendQuotation;
use App\Enums\BookingStatus;
use App\Enums\QuotationStatus;
use App\Models\Booking;
use App\Models\BookingService;
use App\Models\Customer;
use App\Models\EventType;
use App\Models\Organization;
use App\Models\Package;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Service;
use App\Models\ServiceRate;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class BookingQuotationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_commercial_booking_change_outdates_draft_and_allows_a_new_snapshot(): void
    {
        $context = $this->context();
        $draft = $this->draft($context['user'], $context['booking']);
        $payload = $this->updatePayload($context['booking']);
        $payload['event_name'] = 'Updated Occasion';

        $updated = app(UpdateBooking::class)->handle(
            $context['organization'],
            $context['user'],
            $context['booking']->id,
            $payload,
        );

        $this->assertSame(QuotationStatus::Outdated, $draft->fresh()->status);
        $this->assertNotNull($draft->fresh()->closed_at);
        $this->assertSame(BookingStatus::Pending, $updated->status);
        $newDraft = $this->draft($context['user'], $updated);
        $this->assertSame(QuotationStatus::Draft, $newDraft->status);
        $this->assertSame('Updated Occasion', $newDraft->event_name);
        $this->assertNotSame($draft->quotation_number, $newDraft->quotation_number);
    }

    public function test_commercial_booking_change_outdates_sent_and_returns_booking_to_pending(): void
    {
        $context = $this->context();
        $sent = $this->sent($context['user'], $context['booking']);
        $payload = $this->updatePayload($context['booking']);
        $payload['venue_address'] = 'New commercial venue address';

        $updated = app(UpdateBooking::class)->handle(
            $context['organization'],
            $context['user'],
            $context['booking']->id,
            $payload,
        );

        $this->assertSame(QuotationStatus::Outdated, $sent->fresh()->status);
        $this->assertNotNull($sent->fresh()->closed_at);
        $this->assertSame(BookingStatus::Pending, $updated->status);
    }

    public function test_outdated_transition_is_terminal(): void
    {
        $context = $this->context();
        $draft = $this->draft($context['user'], $context['booking']);
        $outdated = app(OutdateQuotation::class)->handle($context['user'], $draft->id);
        $closedAt = $outdated->closed_at;

        $this->expectValidationError(
            'status',
            fn () => app(OutdateQuotation::class)->handle($context['user'], $outdated->id),
        );

        $this->assertSame(QuotationStatus::Outdated, $outdated->fresh()->status);
        $this->assertTrue($outdated->fresh()->closed_at->equalTo($closedAt));
        $this->expectValidationError(
            'status',
            fn () => app(SendQuotation::class)->handle($context['user'], $outdated->id),
        );
    }

    #[DataProvider('commercialChanges')]
    public function test_effective_commercial_changes_outdate_an_active_draft(string $change): void
    {
        $context = $this->context();
        $draft = $this->draft($context['user'], $context['booking']);
        $payload = $this->updatePayload($context['booking']);
        $this->applyCommercialChange($change, $context, $payload);

        app(UpdateBooking::class)->handle(
            $context['organization'],
            $context['user'],
            $context['booking']->id,
            $payload,
        );

        $this->assertSame(QuotationStatus::Outdated, $draft->fresh()->status);
    }

    /** @return array<string, array{string}> */
    public static function commercialChanges(): array
    {
        return [
            'customer snapshot' => ['customer'],
            'event type snapshot' => ['event_type'],
            'event identity' => ['event_name'],
            'venue snapshot' => ['venue'],
            'contact snapshot' => ['contact'],
            'service and package replacement' => ['service'],
            'package replacement' => ['package'],
            'schedule' => ['schedule'],
            'duration' => ['duration'],
            'quantity' => ['quantity'],
            'authoritative price and line total' => ['price'],
            'added Booking Service' => ['add_service'],
        ];
    }

    public function test_same_commercial_values_and_operational_changes_do_not_outdate_a_draft(): void
    {
        $context = $this->context();
        $draft = $this->draft($context['user'], $context['booking']);
        $staff = Staff::factory()->for($context['organization'])->create();
        $payload = $this->updatePayload($context['booking']);
        $payload['internal_notes'] = 'Updated internal setup note';
        $payload['booking_services'][0]['staff_ids'] = [$staff->id];

        $updated = app(UpdateBooking::class)->handle(
            $context['organization'],
            $context['user'],
            $context['booking']->id,
            $payload,
        );

        $this->assertSame(QuotationStatus::Draft, $draft->fresh()->status);
        $this->assertNull($draft->fresh()->closed_at);
        $this->assertSame('Updated internal setup note', $updated->internal_notes);
        $this->assertSame([$staff->id], $updated->bookingServices->first()->assignedStaff->pluck('id')->all());
    }

    public function test_accepted_quotation_blocks_commercial_changes_without_mutating_booking_or_quote(): void
    {
        $context = $this->context();
        $accepted = app(AcceptQuotation::class)->handle(
            $context['user'],
            $this->sent($context['user'], $context['booking'])->id,
        );
        $originalClosedAt = $accepted->getRawOriginal('closed_at');
        $originalEventName = $context['booking']->event_name;
        $originalQuantity = $context['booking']->bookingServices->first()->quantity;
        $payload = $this->updatePayload($context['booking']);
        $payload['event_name'] = 'Forbidden commercial change';
        $payload['booking_services'][0]['quantity'] = 2;

        $this->expectValidationError(
            'booking',
            fn () => app(UpdateBooking::class)->handle(
                $context['organization'],
                $context['user'],
                $context['booking']->id,
                $payload,
            ),
        );

        $this->assertSame(QuotationStatus::Accepted, $accepted->fresh()->status);
        $this->assertSame($originalClosedAt, $accepted->fresh()->getRawOriginal('closed_at'));
        $this->assertSame($originalEventName, $context['booking']->fresh()->event_name);
        $this->assertSame($originalQuantity, $context['booking']->bookingServices()->first()->quantity);
        $this->assertSame(BookingStatus::Quoted, $context['booking']->fresh()->status);
    }

    public function test_operational_only_update_remains_available_after_acceptance(): void
    {
        $context = $this->context();
        $accepted = app(AcceptQuotation::class)->handle(
            $context['user'],
            $this->sent($context['user'], $context['booking'])->id,
        );
        $staff = Staff::factory()->for($context['organization'])->create();
        $payload = $this->updatePayload($context['booking']);
        $payload['internal_notes'] = 'Accepted event operational note';
        $payload['booking_services'][0]['staff_ids'] = [$staff->id];

        $updated = app(UpdateBooking::class)->handle(
            $context['organization'],
            $context['user'],
            $context['booking']->id,
            $payload,
        );

        $this->assertSame(QuotationStatus::Accepted, $accepted->fresh()->status);
        $this->assertSame(BookingStatus::Quoted, $updated->status);
        $this->assertSame('Accepted event operational note', $updated->internal_notes);
        $this->assertSame([$staff->id], $updated->bookingServices->first()->assignedStaff->pluck('id')->all());
    }

    public function test_removing_service_preserves_terminal_historical_items_and_nulls_only_lineage(): void
    {
        $context = $this->context(withSecondService: true);
        $removedLine = $context['booking']->bookingServices->last();
        $frozen = [
            'service_name' => $removedLine->service_name,
            'package_name' => $removedLine->package_name,
            'start_at' => $removedLine->getRawOriginal('start_at'),
            'end_at' => $removedLine->getRawOriginal('end_at'),
            'quantity' => $removedLine->quantity,
            'unit_rate' => $removedLine->unit_rate,
            'line_total' => $removedLine->line_total,
        ];
        $items = collect();

        foreach ([
            QuotationStatus::Rejected,
            QuotationStatus::Cancelled,
            QuotationStatus::Expired,
            QuotationStatus::Outdated,
        ] as $status) {
            $quotation = Quotation::factory()->forBooking($context['booking'])->create([
                'status' => $status,
                'closed_at' => '2027-01-02 10:00:00',
            ]);
            $items->push(QuotationItem::factory()->fromBookingService($quotation, $removedLine)->create());
        }

        $payload = $this->updatePayload($context['booking']);
        $payload['booking_services'] = [$payload['booking_services'][0]];
        app(UpdateBooking::class)->handle(
            $context['organization'],
            $context['user'],
            $context['booking']->id,
            $payload,
        );

        $this->assertDatabaseMissing('booking_services', ['id' => $removedLine->id]);
        foreach ($items as $item) {
            $item->refresh();
            $this->assertNull($item->booking_service_id);
            $this->assertSame($frozen['service_name'], $item->service_name);
            $this->assertSame($frozen['package_name'], $item->package_name);
            $this->assertSame($frozen['start_at'], $item->getRawOriginal('start_at'));
            $this->assertSame($frozen['end_at'], $item->getRawOriginal('end_at'));
            $this->assertSame($frozen['quantity'], $item->quantity);
            $this->assertSame($frozen['unit_rate'], $item->unit_rate);
            $this->assertSame($frozen['line_total'], $item->line_total);
            $this->assertNotNull($item->quotation);
        }
    }

    #[DataProvider('activeRemovalQuotationStates')]
    public function test_removing_service_outdates_active_quote_before_preserving_its_item(string $state): void
    {
        $context = $this->context(withSecondService: true);
        $removedLine = $context['booking']->bookingServices->last();
        $quotation = $state === 'sent'
            ? $this->sent($context['user'], $context['booking'])
            : $this->draft($context['user'], $context['booking']);
        $item = $quotation->items->firstWhere('booking_service_id', $removedLine->id);
        $snapshot = $item->only(['service_name', 'package_name', 'duration_minutes', 'quantity', 'unit_rate', 'line_total']);
        $payload = $this->updatePayload($context['booking']);
        $payload['booking_services'] = [$payload['booking_services'][0]];

        $updated = app(UpdateBooking::class)->handle(
            $context['organization'],
            $context['user'],
            $context['booking']->id,
            $payload,
        );

        $this->assertSame(QuotationStatus::Outdated, $quotation->fresh()->status);
        $this->assertSame(BookingStatus::Pending, $updated->status);
        $this->assertNull($item->fresh()->booking_service_id);
        $this->assertSame($snapshot, $item->fresh()->only(array_keys($snapshot)));
    }

    /** @return array<string, array{string}> */
    public static function activeRemovalQuotationStates(): array
    {
        return [
            'draft' => ['draft'],
            'sent' => ['sent'],
        ];
    }

    #[DataProvider('activeCancellationStates')]
    public function test_booking_cancellation_cancels_active_quotation_atomically(string $state): void
    {
        $context = $this->context();
        $quotation = $state === 'sent'
            ? $this->sent($context['user'], $context['booking'])
            : $this->draft($context['user'], $context['booking']);

        $cancelled = app(CancelBooking::class)->handle(
            $context['organization'],
            $context['user'],
            $context['booking']->id,
            'Client cancelled',
        );

        $this->assertSame(BookingStatus::Cancelled, $cancelled->status);
        $this->assertSame(QuotationStatus::Cancelled, $quotation->fresh()->status);
        $this->assertNotNull($quotation->fresh()->closed_at);
        $this->assertFalse($context['booking']->quotations()->whereIn('status', ['DRAFT', 'SENT'])->exists());
    }

    /** @return array<string, array{string}> */
    public static function activeCancellationStates(): array
    {
        return [
            'draft' => ['draft'],
            'sent' => ['sent'],
        ];
    }

    public function test_booking_cancellation_does_not_rewrite_accepted_quotation(): void
    {
        $context = $this->context();
        $accepted = app(AcceptQuotation::class)->handle(
            $context['user'],
            $this->sent($context['user'], $context['booking'])->id,
        );
        $acceptedAt = $accepted->getRawOriginal('accepted_at');
        $closedAt = $accepted->getRawOriginal('closed_at');

        $cancelled = app(CancelBooking::class)->handle(
            $context['organization'],
            $context['user'],
            $context['booking']->id,
            null,
        );

        $this->assertSame(BookingStatus::Cancelled, $cancelled->status);
        $this->assertSame(QuotationStatus::Accepted, $accepted->fresh()->status);
        $this->assertSame($acceptedAt, $accepted->fresh()->getRawOriginal('accepted_at'));
        $this->assertSame($closedAt, $accepted->fresh()->getRawOriginal('closed_at'));
    }

    public function test_failure_after_invalidation_rolls_back_quote_and_booking_aggregate(): void
    {
        $context = $this->context();
        $draft = $this->draft($context['user'], $context['booking']);
        $originalEventName = $context['booking']->event_name;
        $originalQuantity = $context['booking']->bookingServices->first()->quantity;
        $payload = $this->updatePayload($context['booking']);
        $payload['event_name'] = 'Rolled back occasion';
        $payload['booking_services'][0]['quantity'] = 2;
        Event::listen('eloquent.updating: '.BookingService::class, function (): never {
            throw new RuntimeException('Simulated Booking Service update failure.');
        });

        try {
            app(UpdateBooking::class)->handle(
                $context['organization'],
                $context['user'],
                $context['booking']->id,
                $payload,
            );
            $this->fail('The simulated Booking update failure should abort the transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated Booking Service update failure.', $exception->getMessage());
        }

        $this->assertSame(QuotationStatus::Draft, $draft->fresh()->status);
        $this->assertNull($draft->fresh()->closed_at);
        $this->assertSame($originalEventName, $context['booking']->fresh()->event_name);
        $this->assertSame($originalQuantity, $context['booking']->bookingServices()->first()->quantity);
    }

    public function test_invalidation_failure_prevents_booking_mutation(): void
    {
        $context = $this->context();
        $draft = $this->draft($context['user'], $context['booking']);
        $originalEventName = $context['booking']->event_name;
        $payload = $this->updatePayload($context['booking']);
        $payload['event_name'] = 'Must not persist';
        Event::listen('eloquent.updating: '.Quotation::class, function (Quotation $quotation): void {
            if ($quotation->isDirty('status') && $quotation->status === QuotationStatus::Outdated) {
                throw new RuntimeException('Simulated quotation invalidation failure.');
            }
        });

        try {
            app(UpdateBooking::class)->handle(
                $context['organization'],
                $context['user'],
                $context['booking']->id,
                $payload,
            );
            $this->fail('The simulated invalidation failure should abort the transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated quotation invalidation failure.', $exception->getMessage());
        }

        $this->assertSame(QuotationStatus::Draft, $draft->fresh()->status);
        $this->assertSame($originalEventName, $context['booking']->fresh()->event_name);
    }

    public function test_booking_update_with_active_quote_remains_tenant_scoped(): void
    {
        $context = $this->context();
        $foreign = $this->context();
        $draft = $this->draft($context['user'], $context['booking']);
        $payload = $this->updatePayload($context['booking']);
        $payload['event_name'] = 'Foreign attempt';

        $this->actingAs($foreign['user'])
            ->putJson("/api/v1/bookings/{$context['booking']->id}", $payload)
            ->assertNotFound();

        $this->assertSame(QuotationStatus::Draft, $draft->fresh()->status);
        $this->assertNotSame('Foreign attempt', $context['booking']->fresh()->event_name);
    }

    /**
     * @return array{
     *   user: User,
     *   organization: Organization,
     *   booking: Booking,
     *   customer: Customer,
     *   event_type: EventType,
     *   service: Service,
     *   package: Package,
     *   rate: ServiceRate
     * }
     */
    private function context(bool $withSecondService = false): array
    {
        $organization = Organization::factory()->withBusinessSettings()->create();
        $user = User::factory()->for($organization)->create();
        $customer = Customer::factory()->for($organization)->create([
            'name' => 'Booked Customer',
            'email' => 'booked@example.test',
            'phone' => '09170000000',
            'address' => 'Booked Customer Address',
        ]);
        $eventType = EventType::factory()->for($organization)->create(['name' => 'Wedding']);
        $service = Service::factory()->for($organization)->create([
            'name' => 'Mirror Booth',
            'total_units' => 10,
        ]);
        $package = Package::factory()->forService($service)->create(['name' => 'Premium']);
        $rate = ServiceRate::factory()->forCombination($eventType, $service, $package)->create([
            'duration_minutes' => 180,
            'unit_rate' => '7500.00',
        ]);
        $payload = $this->createPayload($customer, $eventType, $service, $package);

        if ($withSecondService) {
            [$otherService, $otherPackage] = $this->newCatalogCombination($organization, $eventType);
            $payload['booking_services'][] = [
                'service_id' => $otherService->id,
                'package_id' => $otherPackage->id,
                'duration_minutes' => 180,
                'quantity' => 1,
            ];
        }

        $booking = app(CreateBooking::class)->handle($organization, $user, $payload);

        return compact('user', 'organization', 'booking', 'customer', 'eventType', 'service', 'package', 'rate') + [
            'event_type' => $eventType,
        ];
    }

    /** @param array<string, mixed> $context @param array<string, mixed> $payload */
    private function applyCommercialChange(string $change, array $context, array &$payload): void
    {
        switch ($change) {
            case 'customer':
                $payload['customer_id'] = Customer::factory()->for($context['organization'])->create()->id;
                break;
            case 'event_type':
                $eventType = EventType::factory()->for($context['organization'])->create(['name' => 'Corporate Event']);
                ServiceRate::factory()->forCombination(
                    $eventType,
                    $context['service'],
                    $context['package'],
                )->create(['duration_minutes' => 180, 'unit_rate' => '7500.00']);
                $payload['event_type_id'] = $eventType->id;
                break;
            case 'event_name':
                $payload['event_name'] = 'Different occasion';
                break;
            case 'venue':
                $payload['venue_name'] = 'Different Venue';
                break;
            case 'contact':
                $payload['contact_number'] = '09179999999';
                break;
            case 'service':
                [$service, $package] = $this->newCatalogCombination(
                    $context['organization'],
                    $context['event_type'],
                );
                $payload['booking_services'][0]['service_id'] = $service->id;
                $payload['booking_services'][0]['package_id'] = $package->id;
                break;
            case 'package':
                $package = Package::factory()->forService($context['service'])->create(['name' => 'Deluxe']);
                ServiceRate::factory()->forCombination(
                    $context['event_type'],
                    $context['service'],
                    $package,
                )->create(['duration_minutes' => 180, 'unit_rate' => '7600.00']);
                $payload['booking_services'][0]['package_id'] = $package->id;
                break;
            case 'schedule':
                $payload['start_time'] = '20:00';
                break;
            case 'duration':
                ServiceRate::factory()->forCombination(
                    $context['event_type'],
                    $context['service'],
                    $context['package'],
                )->create(['duration_minutes' => 240, 'unit_rate' => '9000.00']);
                $payload['booking_services'][0]['duration_minutes'] = 240;
                break;
            case 'quantity':
                $payload['booking_services'][0]['quantity'] = 2;
                break;
            case 'price':
                $context['rate']->update(['unit_rate' => '8000.00']);
                break;
            case 'add_service':
                [$service, $package] = $this->newCatalogCombination(
                    $context['organization'],
                    $context['event_type'],
                );
                $payload['booking_services'][] = [
                    'service_id' => $service->id,
                    'package_id' => $package->id,
                    'duration_minutes' => 180,
                    'quantity' => 1,
                ];
                break;
        }
    }

    /** @return array{Service, Package} */
    private function newCatalogCombination(Organization $organization, EventType $eventType): array
    {
        $service = Service::factory()->for($organization)->create([
            'name' => '360 Booth',
            'total_units' => 10,
        ]);
        $package = Package::factory()->forService($service)->create(['name' => 'Deluxe']);
        ServiceRate::factory()->forCombination($eventType, $service, $package)->create([
            'duration_minutes' => 180,
            'unit_rate' => '5000.00',
        ]);

        return [$service, $package];
    }

    /** @return array<string, mixed> */
    private function createPayload(
        Customer $customer,
        EventType $eventType,
        Service $service,
        Package $package,
    ): array {
        return [
            'customer_id' => $customer->id,
            'event_type_id' => $eventType->id,
            'event_name' => 'Booked Occasion',
            'event_date' => '2027-06-15',
            'start_time' => '18:00',
            'venue_name' => 'Booked Venue',
            'venue_address' => 'Booked Venue Address',
            'contact_person' => 'Booked Contact',
            'contact_number' => '09171111111',
            'internal_notes' => 'Initial operational note',
            'booking_services' => [[
                'service_id' => $service->id,
                'package_id' => $package->id,
                'duration_minutes' => 180,
                'quantity' => 1,
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function updatePayload(Booking $booking): array
    {
        $booking->refresh()->load('bookingServices.assignedStaff');

        return [
            'customer_id' => $booking->customer_id,
            'event_type_id' => $booking->event_type_id,
            'event_name' => $booking->event_name,
            'event_date' => $booking->start_at->format('Y-m-d'),
            'start_time' => $booking->start_at->format('H:i'),
            'venue_name' => $booking->venue_name,
            'venue_address' => $booking->venue_address,
            'contact_person' => $booking->contact_person,
            'contact_number' => $booking->contact_number,
            'internal_notes' => $booking->internal_notes,
            'booking_services' => $booking->bookingServices->map(fn (BookingService $line): array => [
                'id' => $line->id,
                'service_id' => $line->service_id,
                'package_id' => $line->package_id,
                'duration_minutes' => $line->duration_minutes,
                'quantity' => $line->quantity,
                'staff_ids' => $line->assignedStaff->pluck('id')->all(),
            ])->all(),
        ];
    }

    private function draft(User $user, Booking $booking): Quotation
    {
        return app(CreateQuotation::class)->handle($user, $booking->id, [
            'valid_until' => '2027-12-31',
        ]);
    }

    private function sent(User $user, Booking $booking): Quotation
    {
        return app(SendQuotation::class)->handle($user, $this->draft($user, $booking)->id);
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
