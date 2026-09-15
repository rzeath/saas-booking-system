<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuotationSummaryResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'quotation_number' => $this->resource->quotation_number,
            'status' => $this->resource->status->value,
            'customer_name' => $this->resource->customer_name,
            'total' => $this->resource->total,
            'valid_until' => $this->resource->valid_until?->format('Y-m-d'),
            'sent_at' => $this->resource->sent_at?->toISOString(),
            'accepted_at' => $this->resource->accepted_at?->toISOString(),
            'closed_at' => $this->resource->closed_at?->toISOString(),
            'created_at' => $this->resource->created_at?->toISOString(),
        ];
    }
}
