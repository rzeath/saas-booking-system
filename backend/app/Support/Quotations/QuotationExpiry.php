<?php

namespace App\Support\Quotations;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;

class QuotationExpiry
{
    public function today(): string
    {
        return CarbonImmutable::now(
            new DateTimeZone((string) config('app.timezone')),
        )->toDateString();
    }

    public function isExpired(?DateTimeInterface $validUntil): bool
    {
        return $validUntil !== null
            && $validUntil->format('Y-m-d') < $this->today();
    }
}
