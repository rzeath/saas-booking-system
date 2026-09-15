<?php

namespace App\Support\Quotations;

use Illuminate\Validation\ValidationException;

class QuotationMoneyCalculator
{
    private const MAX_CENTS = 9_999_999_999_999;

    /**
     * @param  iterable<mixed>  $lineTotals
     * @return array{subtotal: string, transportation_fee: string, crew_meal_fee: string, discount_amount: string, total: string}
     */
    public function calculate(
        iterable $lineTotals,
        mixed $transportationFee = '0.00',
        mixed $crewMealFee = '0.00',
        mixed $discountAmount = '0.00',
    ): array {
        $subtotal = 0;

        foreach ($lineTotals as $lineTotal) {
            $subtotal = $this->addToSubtotal(
                $subtotal,
                $this->toCents($lineTotal, 'quotation_items'),
            );
        }

        $transportation = $this->toCents($transportationFee, 'transportation_fee');
        $crewMeal = $this->toCents($crewMealFee, 'crew_meal_fee');
        $discount = $this->toCents($discountAmount, 'discount_amount');
        $gross = $subtotal + $transportation + $crewMeal;
        $total = $gross - $discount;

        if ($total <= 0) {
            throw ValidationException::withMessages([
                'discount_amount' => 'The quotation total must be greater than zero.',
            ]);
        }

        if ($total > self::MAX_CENTS) {
            throw ValidationException::withMessages([
                'total' => 'The quotation total exceeds the supported amount.',
            ]);
        }

        return [
            'subtotal' => $this->format($subtotal),
            'transportation_fee' => $this->format($transportation),
            'crew_meal_fee' => $this->format($crewMeal),
            'discount_amount' => $this->format($discount),
            'total' => $this->format($total),
        ];
    }

    public function ensurePositive(mixed $amount, string $attribute = 'total'): void
    {
        if ($this->toCents($amount, $attribute) === 0) {
            throw ValidationException::withMessages([
                $attribute => 'The quotation total must be greater than zero.',
            ]);
        }
    }

    private function toCents(mixed $amount, string $attribute): int
    {
        if (is_int($amount)) {
            $amount = (string) $amount;
        }

        if (! is_string($amount)
            || ! preg_match('/^(\d{1,11})(?:\.(\d{1,2}))?$/', $amount, $matches)) {
            throw ValidationException::withMessages([
                $attribute => 'The value must be a non-negative monetary amount with at most two decimal places.',
            ]);
        }

        $fraction = str_pad($matches[2] ?? '', 2, '0');
        $cents = ((int) $matches[1] * 100) + (int) $fraction;

        if ($cents > self::MAX_CENTS) {
            throw ValidationException::withMessages([
                $attribute => 'The value exceeds the supported amount.',
            ]);
        }

        return $cents;
    }

    private function addToSubtotal(int $subtotal, int $lineTotal): int
    {
        if ($subtotal > self::MAX_CENTS - $lineTotal) {
            throw ValidationException::withMessages([
                'subtotal' => 'The quotation subtotal exceeds the supported amount.',
            ]);
        }

        return $subtotal + $lineTotal;
    }

    private function format(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
