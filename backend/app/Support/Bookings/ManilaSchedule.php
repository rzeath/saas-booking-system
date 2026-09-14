<?php

namespace App\Support\Bookings;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

class ManilaSchedule
{
    public function startAt(string $eventDate, string $startTime, string $attribute): DateTimeImmutable
    {
        $input = "{$eventDate} {$startTime}";
        $zone = new DateTimeZone((string) config('app.timezone'));
        $startAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $input, $zone);
        $errors = DateTimeImmutable::getLastErrors();

        if ($startAt === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $startAt->format('Y-m-d H:i') !== $input) {
            throw ValidationException::withMessages([
                $attribute => 'The start time is invalid.',
            ]);
        }

        return $startAt;
    }
}
