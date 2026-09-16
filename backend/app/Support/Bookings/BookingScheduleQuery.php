<?php

namespace App\Support\Bookings;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BookingScheduleQuery
{
    public function whereOverlaps(
        EloquentBuilder|QueryBuilder $query,
        DateTimeInterface $startAt,
        DateTimeInterface $endAt,
    ): void {
        $query
            ->where('bookings.start_at', '<', $this->wallClock($endAt))
            ->whereRaw(
                $this->endExpression().' > ?',
                [$this->wallClock($startAt)],
            );
    }

    private function endExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'mysql' => 'TIMESTAMPADD(MINUTE, booking_services.duration_minutes, bookings.start_at)',
            'sqlite' => "datetime(bookings.start_at, '+' || booking_services.duration_minutes || ' minutes')",
            default => throw new RuntimeException('Unsupported database driver for Booking schedule queries.'),
        };
    }

    private function wallClock(DateTimeInterface $value): string
    {
        return $value->format('Y-m-d H:i:s');
    }
}
