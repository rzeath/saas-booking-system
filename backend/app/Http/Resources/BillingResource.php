<?php

namespace App\Http\Resources;

use App\Support\Billings\PaymentSummaryCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BillingResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $summary = app(PaymentSummaryCalculator::class)->forBilling($this->resource);

        return [
            'id' => $this->resource->id,
            'billing_number' => $this->resource->billing_number,
            'quotation' => [
                'id' => $this->resource->quotation_id,
                'quotation_number' => $this->resource->quotation_number,
            ],
            'booking' => $this->whenLoaded('booking', fn (): array => [
                'id' => $this->resource->booking->id,
                'booking_number' => $this->resource->booking->booking_number,
                'status' => $this->resource->booking->status->value,
            ]),
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
            'payment_summary' => [
                'amount_paid' => $summary->amountPaid,
                'remaining_balance' => $summary->remainingBalance,
                'payment_status' => $summary->paymentStatus,
            ],
            'items' => BillingItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->resource->created_at?->toISOString(),
        ];
    }
}
