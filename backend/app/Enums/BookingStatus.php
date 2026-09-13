<?php

namespace App\Enums;

enum BookingStatus: string
{
    case Pending = 'PENDING';
    case Quoted = 'QUOTED';
    case Confirmed = 'CONFIRMED';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';

    public function reservesCapacity(): bool
    {
        return match ($this) {
            self::Pending, self::Quoted, self::Confirmed => true,
            self::Completed, self::Cancelled => false,
        };
    }

    /** @return list<string> */
    public static function capacityReservingValues(): array
    {
        return array_values(array_map(
            fn (self $status): string => $status->value,
            array_filter(self::cases(), fn (self $status): bool => $status->reservesCapacity()),
        ));
    }
}
