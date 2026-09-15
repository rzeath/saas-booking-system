<?php

namespace App\Support\Quotations;

use App\Models\Booking;
use App\Models\BookingService;
use App\Models\BusinessSetting;

class QuotationSnapshotBuilder
{
    /** @return array<string, mixed> */
    public function header(Booking $booking, BusinessSetting $settings): array
    {
        return [
            'business_display_name' => $settings->display_name,
            'business_email' => $settings->email,
            'business_phone' => $settings->phone,
            'business_address' => $settings->address,
            'business_logo_path' => $settings->logo_path,
            'customer_name' => $booking->customer_name,
            'customer_email' => $booking->customer_email,
            'customer_phone' => $booking->customer_phone,
            'customer_address' => $booking->customer_address,
            'event_type_name' => $booking->event_type_name,
            'event_name' => $booking->event_name,
            'event_date' => $booking->getRawOriginal('event_date'),
            'venue_name' => $booking->venue_name,
            'venue_address' => $booking->venue_address,
            'contact_person' => $booking->contact_person,
            'contact_number' => $booking->contact_number,
            'currency' => $settings->currency,
        ];
    }

    /**
     * @param  iterable<BookingService>  $bookingServices
     * @return list<array<string, mixed>>
     */
    public function items(iterable $bookingServices): array
    {
        $items = [];

        foreach ($bookingServices as $bookingService) {
            $items[] = [
                'booking_id' => $bookingService->booking_id,
                'booking_service_id' => $bookingService->id,
                'service_name' => $bookingService->service_name,
                'package_name' => $bookingService->package_name,
                'start_at' => $bookingService->getRawOriginal('start_at'),
                'end_at' => $bookingService->getRawOriginal('end_at'),
                'duration_minutes' => $bookingService->duration_minutes,
                'quantity' => $bookingService->quantity,
                'unit_rate' => $bookingService->unit_rate,
                'line_total' => $bookingService->line_total,
                'sort_order' => $bookingService->sort_order,
            ];
        }

        return $items;
    }
}
