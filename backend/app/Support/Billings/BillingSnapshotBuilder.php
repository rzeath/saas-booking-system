<?php

namespace App\Support\Billings;

use App\Models\Quotation;
use App\Models\QuotationItem;

class BillingSnapshotBuilder
{
    /** @return array<string, mixed> */
    public function header(Quotation $quotation): array
    {
        return [
            'quotation_number' => $quotation->quotation_number,
            'business_display_name' => $quotation->business_display_name,
            'business_email' => $quotation->business_email,
            'business_phone' => $quotation->business_phone,
            'business_address' => $quotation->business_address,
            'business_logo_path' => $quotation->business_logo_path,
            'customer_name' => $quotation->customer_name,
            'customer_email' => $quotation->customer_email,
            'customer_phone' => $quotation->customer_phone,
            'customer_address' => $quotation->customer_address,
            'event_type_name' => $quotation->event_type_name,
            'event_name' => $quotation->event_name,
            'event_date' => $quotation->getRawOriginal('event_date'),
            'venue_name' => $quotation->venue_name,
            'venue_address' => $quotation->venue_address,
            'contact_person' => $quotation->contact_person,
            'contact_number' => $quotation->contact_number,
            'subtotal' => $quotation->subtotal,
            'transportation_fee' => $quotation->transportation_fee,
            'crew_meal_fee' => $quotation->crew_meal_fee,
            'discount_amount' => $quotation->discount_amount,
            'total' => $quotation->total,
        ];
    }

    /**
     * @param  iterable<QuotationItem>  $quotationItems
     * @return list<array<string, mixed>>
     */
    public function items(Quotation $quotation, iterable $quotationItems): array
    {
        $items = [];

        foreach ($quotationItems as $quotationItem) {
            $items[] = [
                'organization_id' => $quotation->organization_id,
                'booking_id' => $quotation->booking_id,
                'quotation_id' => $quotation->id,
                'quotation_item_id' => $quotationItem->id,
                'service_name' => $quotationItem->service_name,
                'package_name' => $quotationItem->package_name,
                'start_at' => $quotationItem->getRawOriginal('start_at'),
                'end_at' => $quotationItem->getRawOriginal('end_at'),
                'duration_minutes' => $quotationItem->duration_minutes,
                'quantity' => $quotationItem->quantity,
                'unit_rate' => $quotationItem->unit_rate,
                'line_total' => $quotationItem->line_total,
                'sort_order' => $quotationItem->sort_order,
            ];
        }

        return $items;
    }
}
