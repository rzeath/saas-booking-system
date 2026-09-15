<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BillingItemResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'service_name' => $this->resource->service_name,
            'package_name' => $this->resource->package_name,
            'start_at' => $this->resource->start_at->format('Y-m-d H:i'),
            'end_at' => $this->resource->end_at->format('Y-m-d H:i'),
            'duration_minutes' => $this->resource->duration_minutes,
            'quantity' => $this->resource->quantity,
            'unit_rate' => $this->resource->unit_rate,
            'line_total' => $this->resource->line_total,
            'sort_order' => $this->resource->sort_order,
        ];
    }
}
