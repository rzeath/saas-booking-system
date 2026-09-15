<?php

namespace App\Support\Billings;

final readonly class PaymentSummary
{
    public const UNPAID = 'UNPAID';

    public const PARTIALLY_PAID = 'PARTIALLY_PAID';

    public const PAID = 'PAID';

    public function __construct(
        public string $total,
        public string $amountPaid,
        public string $remainingBalance,
        public string $paymentStatus,
        public int $remainingCents,
    ) {}
}
