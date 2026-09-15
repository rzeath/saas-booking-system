<?php

namespace App\Support\Billings;

use App\Models\Billing;
use App\Models\Payment;

final readonly class PaymentMutationResult
{
    public function __construct(
        public Payment $payment,
        public Billing $billing,
        public PaymentSummary $summary,
    ) {}
}
