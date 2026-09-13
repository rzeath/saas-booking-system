<?php

namespace App\Support\Bookings;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

class LocalScheduleConverter
{
    public function toUtc(
        string $eventDate,
        string $localStartTime,
        string $timezone,
        string $attribute,
    ): DateTimeImmutable {
        $input = "{$eventDate} {$localStartTime}";
        $zone = new DateTimeZone($timezone);
        $local = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $input, $zone);
        $errors = DateTimeImmutable::getLastErrors();

        if ($local === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $local->format('Y-m-d H:i') !== $input) {
            throw ValidationException::withMessages([
                $attribute => 'The local start time does not exist in the booking timezone.',
            ]);
        }

        if ($this->isAmbiguous($input, $zone)) {
            throw ValidationException::withMessages([
                $attribute => 'The local start time is ambiguous in the booking timezone.',
            ]);
        }

        return $local->setTimezone(new DateTimeZone('UTC'));
    }

    private function isAmbiguous(string $localInput, DateTimeZone $zone): bool
    {
        $wallClock = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i',
            $localInput,
            new DateTimeZone('UTC'),
        );

        if ($wallClock === false) {
            return false;
        }

        $offsets = [];
        foreach ($zone->getTransitions($wallClock->getTimestamp() - 86400, $wallClock->getTimestamp() + 86400) ?: [] as $transition) {
            $offsets[(int) $transition['offset']] = true;
        }

        $matches = [];
        foreach (array_keys($offsets) as $offset) {
            $candidate = (new DateTimeImmutable('@'.($wallClock->getTimestamp() - $offset)))
                ->setTimezone($zone);

            if ($candidate->format('Y-m-d H:i') === $localInput) {
                $matches[$candidate->format('U.u')] = true;
            }
        }

        return count($matches) > 1;
    }
}
