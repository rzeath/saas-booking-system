<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuotationResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'quotation_number' => $this->resource->quotation_number,
            'status' => $this->resource->status->value,
            'booking' => [
                'id' => $this->resource->booking->id,
                'booking_number' => $this->resource->booking->booking_number,
                'status' => $this->resource->booking->status->value,
            ],
            'valid_until' => $this->resource->valid_until?->format('Y-m-d'),
            'sent_at' => $this->resource->sent_at?->toISOString(),
            'accepted_at' => $this->resource->accepted_at?->toISOString(),
            'closed_at' => $this->resource->closed_at?->toISOString(),
            'seller_snapshot' => [
                'display_name' => $this->resource->business_display_name,
                'email' => $this->resource->business_email,
                'phone' => $this->resource->business_phone,
                'address' => $this->resource->business_address,
                'logo_path' => $this->resource->business_logo_path,
            ],
            'customer_snapshot' => [
                'name' => $this->resource->customer_name,
                'email' => $this->resource->customer_email,
                'phone' => $this->resource->customer_phone,
                'address' => $this->resource->customer_address,
            ],
            'event_snapshot' => [
                'event_type_name' => $this->resource->event_type_name,
                'event_name' => $this->resource->event_name,
                'event_date' => $this->resource->event_date->format('Y-m-d'),
                'venue_name' => $this->resource->venue_name,
                'venue_address' => $this->resource->venue_address,
                'contact_person' => $this->resource->contact_person,
                'contact_number' => $this->resource->contact_number,
            ],
            'subtotal' => $this->resource->subtotal,
            'transportation_fee' => $this->resource->transportation_fee,
            'crew_meal_fee' => $this->resource->crew_meal_fee,
            'discount_amount' => $this->resource->discount_amount,
            'total' => $this->resource->total,
            'items' => QuotationItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->resource->created_at?->toISOString(),
            'updated_at' => $this->resource->updated_at?->toISOString(),
        ];
    }
}
