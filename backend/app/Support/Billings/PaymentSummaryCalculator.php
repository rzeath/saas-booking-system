<?php

namespace App\Support\Billings;

use App\Enums\PaymentStatus;
use App\Models\Billing;
use App\Models\Payment;
use Illuminate\Validation\ValidationException;
use LogicException;

class PaymentSummaryCalculator
{
    private const MAX_CENTS = 9_999_999_999_999;

    public function forBilling(Billing $billing): PaymentSummary
    {
        $payments = $billing->relationLoaded('payments')
            ? $billing->getRelation('payments')
            : $billing->payments()->get(['amount', 'status']);

        return $this->calculate(
            $billing,
            $payments,
        );
    }

    /** @param iterable<Payment> $payments */
    public function calculate(Billing $billing, iterable $payments): PaymentSummary
    {
        $totalCents = $this->toCents($billing->total, 'billing.total');
        $paidCents = 0;

        foreach ($payments as $payment) {
            if ($payment->status !== PaymentStatus::Posted) {
                continue;
            }

            $paymentCents = $this->toCents($payment->amount, 'payments.amount');

            if ($paidCents > self::MAX_CENTS - $paymentCents) {
                throw new LogicException('Posted Payment totals exceed the supported monetary range.');
            }

            $paidCents += $paymentCents;
        }

        if ($paidCents > $totalCents) {
            throw new LogicException('Posted Payments exceed the Billing total.');
        }

        $remainingCents = $totalCents - $paidCents;
        $status = match (true) {
            $paidCents === 0 => PaymentSummary::UNPAID,
            $remainingCents === 0 => PaymentSummary::PAID,
            default => PaymentSummary::PARTIALLY_PAID,
        };

        return new PaymentSummary(
            total: $this->format($totalCents),
            amountPaid: $this->format($paidCents),
            remainingBalance: $this->format($remainingCents),
            paymentStatus: $status,
            remainingCents: $remainingCents,
        );
    }

    public function toCents(mixed $amount, string $attribute = 'amount'): int
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

    public function format(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
