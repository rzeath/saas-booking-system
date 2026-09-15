<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentMutationResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'payment' => new PaymentResource($this->resource->payment),
            'billing' => [
                'id' => $this->resource->billing->id,
                'billing_number' => $this->resource->billing->billing_number,
            ],
            'payment_summary' => [
                'amount_paid' => $this->resource->summary->amountPaid,
                'remaining_balance' => $this->resource->summary->remainingBalance,
                'payment_status' => $this->resource->summary->paymentStatus,
            ],
            'booking' => [
                'id' => $this->resource->payment->booking->id,
                'booking_number' => $this->resource->payment->booking->booking_number,
                'status' => $this->resource->payment->booking->status->value,
            ],
        ];
    }
}
