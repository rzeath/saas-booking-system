<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'booking_number' => $this->resource->booking_number,
            'status' => $this->resource->status->value,
            'customer' => [
                'id' => $this->resource->customer->id,
                'name' => $this->resource->customer->name,
                'is_active' => $this->resource->customer->is_active,
            ],
            'customer_snapshot' => [
                'name' => $this->resource->customer_name,
                'email' => $this->resource->customer_email,
                'phone' => $this->resource->customer_phone,
                'address' => $this->resource->customer_address,
            ],
            'event_type' => [
                'id' => $this->resource->eventType->id,
                'name' => $this->resource->eventType->name,
                'is_active' => $this->resource->eventType->is_active,
            ],
            'event_type_snapshot' => ['name' => $this->resource->event_type_name],
            'event_name' => $this->resource->event_name,
            'event_date' => $this->resource->event_date->format('Y-m-d'),
            'venue_name' => $this->resource->venue_name,
            'venue_address' => $this->resource->venue_address,
            'contact_person' => $this->resource->contact_person,
            'contact_number' => $this->resource->contact_number,
            'internal_notes' => $this->resource->internal_notes,
            'booking_services' => $this->resource->bookingServices->map(fn ($line): array => [
                'id' => $line->id,
                'service' => [
                    'id' => $line->service_id,
                    'name' => $line->service_name,
                ],
                'package' => [
                    'id' => $line->package_id,
                    'name' => $line->package_name,
                ],
                'start_at' => $line->start_at->format('Y-m-d H:i'),
                'end_at' => $line->end_at->format('Y-m-d H:i'),
                'duration_minutes' => $line->duration_minutes,
                'quantity' => $line->quantity,
                'unit_rate' => $line->unit_rate,
                'line_total' => $line->line_total,
                'sort_order' => $line->sort_order,
            ])->values(),
            'cancelled_at' => $this->resource->cancelled_at?->toISOString(),
            'cancellation_reason' => $this->resource->cancellation_reason,
            'created_at' => $this->resource->created_at?->toISOString(),
            'updated_at' => $this->resource->updated_at?->toISOString(),
        ];
    }
}
