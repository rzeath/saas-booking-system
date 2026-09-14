<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceRateResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $package = $this->resource->package;
        $service = $this->resource->service;

        return [
            'id' => $this->resource->id,
            'event_type' => [
                'id' => $this->resource->eventType->id,
                'name' => $this->resource->eventType->name,
                'is_active' => $this->resource->eventType->is_active,
            ],
            'service' => [
                'id' => $service->id,
                'name' => $service->name,
                'is_active' => $service->is_active,
            ],
            'package' => [
                'id' => $package->id,
                'name' => $package->name,
                'is_active' => $package->is_active,
            ],
            'duration_minutes' => $this->resource->duration_minutes,
            'unit_rate' => $this->resource->unit_rate,
            'is_active' => $this->resource->is_active,
            'is_available' => $this->resource->is_active
                && $this->resource->eventType->is_active
                && $package->is_active
                && $service->is_active,
            'created_at' => $this->resource->created_at?->toISOString(),
            'updated_at' => $this->resource->updated_at?->toISOString(),
        ];
    }
}
