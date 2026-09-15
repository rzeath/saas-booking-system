<?php

namespace App\Support\Bookings;

use App\Models\Booking;
use App\Models\BookingService;
use App\Models\Customer;
use App\Models\EventType;
use Illuminate\Database\Eloquent\Collection;

class BookingCommercialChangeDetector
{
    /**
     * @param  Collection<int, BookingService>  $existingLines
     * @param  list<BookingServiceCandidate>  $candidates
     * @param  array<string, mixed>  $data
     */
    public function hasChanges(
        Booking $booking,
        Collection $existingLines,
        Customer $customer,
        EventType $eventType,
        array $candidates,
        array $data,
    ): bool {
        return $this->currentHeader($booking) !== $this->proposedHeader($customer, $eventType, $data)
            || $this->currentLines($existingLines) !== $this->proposedLines($candidates);
    }

    /** @return array<string, int|string|null> */
    private function currentHeader(Booking $booking): array
    {
        return [
            'customer_id' => (int) $booking->customer_id,
            'event_type_id' => (int) $booking->event_type_id,
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
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, int|string|null>
     */
    private function proposedHeader(Customer $customer, EventType $eventType, array $data): array
    {
        return [
            'customer_id' => $customer->id,
            'event_type_id' => $eventType->id,
            'customer_name' => $customer->name,
            'customer_email' => $customer->email,
            'customer_phone' => $customer->phone,
            'customer_address' => $customer->address,
            'event_type_name' => $eventType->name,
            'event_name' => $data['event_name'],
            'event_date' => $data['event_date'],
            'venue_name' => $data['venue_name'],
            'venue_address' => $data['venue_address'] ?? null,
            'contact_person' => $data['contact_person'],
            'contact_number' => $data['contact_number'],
        ];
    }

    /**
     * @param  Collection<int, BookingService>  $lines
     * @return list<array<string, int|string|null>>
     */
    private function currentLines(Collection $lines): array
    {
        return $lines
            ->sortBy([
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])
            ->values()
            ->map(fn (BookingService $line): array => [
                'id' => $line->id,
                'service_id' => (int) $line->service_id,
                'package_id' => (int) $line->package_id,
                'service_name' => $line->service_name,
                'package_name' => $line->package_name,
                'start_at' => $this->wallClock($line->getRawOriginal('start_at')),
                'end_at' => $this->wallClock($line->getRawOriginal('end_at')),
                'duration_minutes' => $line->duration_minutes,
                'quantity' => $line->quantity,
                'unit_rate' => $line->unit_rate,
                'line_total' => $line->line_total,
                'sort_order' => $line->sort_order,
            ])
            ->all();
    }

    /**
     * @param  list<BookingServiceCandidate>  $candidates
     * @return list<array<string, int|string|null>>
     */
    private function proposedLines(array $candidates): array
    {
        return array_map(fn (BookingServiceCandidate $candidate): array => [
            'id' => $candidate->id,
            'service_id' => $candidate->service->id,
            'package_id' => $candidate->package->id,
            'service_name' => $candidate->service->name,
            'package_name' => $candidate->package->name,
            'start_at' => $candidate->startAt->format('Y-m-d H:i:s'),
            'end_at' => $candidate->endAt->format('Y-m-d H:i:s'),
            'duration_minutes' => $candidate->durationMinutes,
            'quantity' => $candidate->quantity,
            'unit_rate' => $candidate->unitRate,
            'line_total' => $candidate->lineTotal,
            'sort_order' => $candidate->sortOrder,
        ], $candidates);
    }

    private function wallClock(mixed $value): string
    {
        return substr((string) $value, 0, 19);
    }
}
