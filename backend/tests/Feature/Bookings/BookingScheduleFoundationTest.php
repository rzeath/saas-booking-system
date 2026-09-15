<?php

namespace Tests\Feature\Bookings;

use App\Models\Booking;
use App\Models\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookingScheduleFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_schema_stores_one_shared_booking_start(): void
    {
        $this->assertTrue(Schema::hasColumn('bookings', 'start_at'));
        $this->assertFalse(Schema::hasColumn('bookings', 'event_date'));
        $this->assertFalse(Schema::hasColumn('bookings', 'end_at'));
        $this->assertFalse(Schema::hasColumn('booking_services', 'start_at'));
        $this->assertFalse(Schema::hasColumn('booking_services', 'end_at'));
        $this->assertTrue(Schema::hasColumn('booking_services', 'duration_minutes'));
    }

    public function test_factories_create_valid_shared_schedule_records(): void
    {
        $booking = Booking::factory()->create(['start_at' => '2027-06-15 23:30:00']);
        $bookingService = BookingService::factory()->forBooking($booking)->create([
            'duration_minutes' => BookingService::MAX_DURATION_MINUTES,
        ]);

        $this->assertInstanceOf(CarbonImmutable::class, $booking->start_at);
        $this->assertSame('2027-06-15 23:30:00', $booking->start_at->format('Y-m-d H:i:s'));
        $this->assertSame(BookingService::MAX_DURATION_MINUTES, $bookingService->duration_minutes);
        $this->assertArrayNotHasKey('start_at', $bookingService->getAttributes());
        $this->assertArrayNotHasKey('end_at', $bookingService->getAttributes());
    }

    #[DataProvider('invalidDurations')]
    public function test_booking_service_duration_is_database_bounded(int $duration): void
    {
        $this->expectException(QueryException::class);

        BookingService::factory()->create(['duration_minutes' => $duration]);
    }

    /** @return array<string, array{int}> */
    public static function invalidDurations(): array
    {
        return [
            'zero' => [0],
            'more than seven days' => [BookingService::MAX_DURATION_MINUTES + 1],
        ];
    }

    public function test_commercial_items_retain_explicit_schedule_snapshots(): void
    {
        foreach (['quotation_items', 'billing_items'] as $table) {
            $this->assertTrue(Schema::hasColumns($table, [
                'start_at',
                'end_at',
                'duration_minutes',
            ]));
        }
    }
}
