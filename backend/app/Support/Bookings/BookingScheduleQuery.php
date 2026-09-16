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
            ->where('bookings.start_at', '<', $this->wallClock($endAt));

        $this->whereEndsAfter($query, $startAt);
    }

    public function whereEndsAfter(
        EloquentBuilder|QueryBuilder $query,
        DateTimeInterface $boundary,
        string $durationColumn = 'booking_services.duration_minutes',
    ): void {
        $query->whereRaw(
            $this->endExpression($durationColumn).' > ?',
            [$this->wallClock($boundary)],
        );
    }

    private function endExpression(string $durationColumn): string
    {
        $durationColumn = DB::connection()->getQueryGrammar()->wrap($durationColumn);

        return match (DB::connection()->getDriverName()) {
            'mysql' => "TIMESTAMPADD(MINUTE, {$durationColumn}, bookings.start_at)",
            'sqlite' => "datetime(bookings.start_at, '+' || {$durationColumn} || ' minutes')",
            default => throw new RuntimeException('Unsupported database driver for Booking schedule queries.'),
        };
    }

    private function wallClock(DateTimeInterface $value): string
    {
        return $value->format('Y-m-d H:i:s');
    }
}
