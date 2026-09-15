<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'billing' => $this->whenLoaded('billing', fn (): array => [
                'id' => $this->resource->billing->id,
                'billing_number' => $this->resource->billing->billing_number,
            ]),
            'quotation' => $this->whenLoaded('quotation', fn (): array => [
                'id' => $this->resource->quotation->id,
                'quotation_number' => $this->resource->quotation->quotation_number,
            ]),
            'booking' => $this->whenLoaded('booking', fn (): array => [
                'id' => $this->resource->booking->id,
                'booking_number' => $this->resource->booking->booking_number,
                'status' => $this->resource->booking->status->value,
            ]),
            'amount' => $this->resource->amount,
            'paid_at' => $this->resource->paid_at->format('Y-m-d H:i:s'),
            'payment_method' => $this->resource->payment_method->value,
            'reference_number' => $this->resource->reference_number,
            'internal_note' => $this->resource->internal_note,
            'status' => $this->resource->status->value,
            'created_by' => $this->whenLoaded('creator', fn (): array => [
                'id' => $this->resource->creator->id,
                'name' => $this->resource->creator->name,
            ]),
            'voided_at' => $this->resource->voided_at?->toISOString(),
            'void_reason' => $this->resource->void_reason,
            'voided_by' => $this->whenLoaded('voider', fn (): ?array => $this->resource->voider === null
                ? null
                : [
                    'id' => $this->resource->voider->id,
                    'name' => $this->resource->voider->name,
                ]),
            'created_at' => $this->resource->created_at?->toISOString(),
        ];
    }
}
