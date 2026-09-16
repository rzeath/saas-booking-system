<?php

namespace Tests\Feature\Bookings;

use App\Models\Booking;
use App\Models\BookingService;
use App\Models\Customer;
use App\Models\EventType;
use App\Models\Organization;
use App\Models\Package;
use App\Models\Service;
use App\Models\User;
use App\Support\Bookings\BookingCommercialChangeDetector;
use App\Support\Bookings\BookingServiceCandidate;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingCommercialChangeDetectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_start_change_is_commercial_but_ephemeral_candidate_endpoints_are_not_line_fingerprints(): void
    {
        [$booking, $line, $customer, $eventType, $service, $package] = $this->context();
        $detector = app(BookingCommercialChangeDetector::class);
        $data = $this->headerData($booking);
        $sameStart = new DateTimeImmutable('2027-06-15 18:00:00');
        $candidate = $this->candidate(
            $line,
            $service,
            $package,
            new DateTimeImmutable('2030-01-01 00:00:00'),
            180,
        );

        $this->assertFalse($detector->hasChanges(
            $booking,
            new Collection([$line]),
            $customer,
            $eventType,
            [$candidate],
            $sameStart,
            $data,
        ));
        $this->assertTrue($detector->hasChanges(
            $booking,
            new Collection([$line]),
            $customer,
            $eventType,
            [$candidate],
            new DateTimeImmutable('2027-06-15 19:00:00'),
            $data,
        ));
    }

    public function test_duration_change_remains_commercial(): void
    {
        [$booking, $line, $customer, $eventType, $service, $package] = $this->context();
        $candidate = $this->candidate(
            $line,
            $service,
            $package,
            new DateTimeImmutable('2027-06-15 18:00:00'),
            240,
        );

        $this->assertTrue(app(BookingCommercialChangeDetector::class)->hasChanges(
            $booking,
            new Collection([$line]),
            $customer,
            $eventType,
            [$candidate],
            new DateTimeImmutable('2027-06-15 18:00:00'),
            $this->headerData($booking),
        ));
    }

    /** @return array{Booking, BookingService, Customer, EventType, Service, Package} */
    private function context(): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->for($organization)->create();
        $customer = Customer::factory()->for($organization)->create();
        $eventType = EventType::factory()->for($organization)->create();
        $service = Service::factory()->for($organization)->create();
        $package = Package::factory()->forService($service)->create();
        $booking = Booking::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $user->id,
            'customer_id' => $customer->id,
            'event_type_id' => $eventType->id,
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'customer_phone' => $customer->phone,
            'customer_address' => $customer->address,
            'event_type_name' => $eventType->name,
            'start_at' => '2027-06-15 18:00:00',
        ]);
        $line = BookingService::factory()
            ->forBooking($booking)
            ->forPackage($package, $service)
            ->create(['duration_minutes' => 180]);

        return [$booking, $line, $customer, $eventType, $service, $package];
    }

    private function candidate(
        BookingService $line,
        Service $service,
        Package $package,
        DateTimeImmutable $startAt,
        int $durationMinutes,
    ): BookingServiceCandidate {
        return new BookingServiceCandidate(
            $line->id,
            $service,
            $package,
            $startAt,
            $startAt->modify("+{$durationMinutes} minutes"),
            $durationMinutes,
            $line->quantity,
            [],
            $line->unit_rate,
            $line->line_total,
            $line->sort_order,
        );
    }

    /** @return array<string, mixed> */
    private function headerData(Booking $booking): array
    {
        return [
            'event_name' => $booking->event_name,
            'venue_name' => $booking->venue_name,
            'venue_address' => $booking->venue_address,
            'contact_person' => $booking->contact_person,
            'contact_number' => $booking->contact_number,
        ];
    }
}
