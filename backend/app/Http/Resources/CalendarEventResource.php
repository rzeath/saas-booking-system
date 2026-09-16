<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CalendarEventResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $startAt = $this->resource->start_at;
        $services = $this->resource->bookingServices;
        $maximumDuration = (int) $services->max('duration_minutes');

        return [
            'id' => $this->resource->id,
            'booking_number' => $this->resource->booking_number,
            'status' => $this->resource->status->value,
            'start_at' => $startAt->format('Y-m-d H:i'),
            'end_at' => $startAt->addMinutes($maximumDuration)->format('Y-m-d H:i'),
            'customer_name' => $this->resource->customer_name,
            'event_name' => $this->resource->event_name,
            'event_type_name' => $this->resource->event_type_name,
            'venue_name' => $this->resource->venue_name,
            'services' => $services->map(fn ($service): array => [
                'id' => $service->id,
                'service_name' => $service->service_name,
                'package_name' => $service->package_name,
                'duration_minutes' => $service->duration_minutes,
                'quantity' => $service->quantity,
                'start_at' => $startAt->format('Y-m-d H:i'),
                'end_at' => $startAt->addMinutes($service->duration_minutes)->format('Y-m-d H:i'),
            ])->values(),
        ];
    }
}
