<?php

namespace App\Enums;

enum DocumentType: string
{
    case Booking = 'BOOKING';
    case Quotation = 'QUOTATION';
    case Billing = 'BILLING';

    public function settingsPrefixColumn(): string
    {
        return match ($this) {
            self::Booking => 'booking_prefix',
            self::Quotation => 'quotation_prefix',
            self::Billing => 'billing_prefix',
        };
    }
}
