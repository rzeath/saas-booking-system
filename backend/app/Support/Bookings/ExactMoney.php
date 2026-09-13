<?php

namespace App\Support\Bookings;

use Illuminate\Validation\ValidationException;

class ExactMoney
{
    private const MAX_CENTS = 9_999_999_999_999;

    public function multiply(string $amount, int $quantity, string $attribute): string
    {
        if (! preg_match('/^(\d{1,11})(?:\.(\d{1,2}))?$/', $amount, $matches)) {
            throw ValidationException::withMessages([$attribute => 'The configured rate is invalid.']);
        }

        $fraction = str_pad($matches[2] ?? '', 2, '0');
        $cents = ((int) $matches[1] * 100) + (int) $fraction;

        if ($quantity < 1 || ($cents > 0 && $quantity > intdiv(self::MAX_CENTS, $cents))) {
            throw ValidationException::withMessages([
                $attribute => 'The calculated line total exceeds the supported amount.',
            ]);
        }

        $total = $cents * $quantity;

        return sprintf('%d.%02d', intdiv($total, 100), $total % 100);
    }
}
