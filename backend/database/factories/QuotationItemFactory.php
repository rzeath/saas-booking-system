<?php

namespace Database\Factories;

use App\Models\BookingService;
use App\Models\Quotation;
use App\Models\QuotationItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use InvalidArgumentException;

/** @extends Factory<QuotationItem> */
class QuotationItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'quotation_id' => Quotation::factory(),
            'booking_id' => fn (array $attributes): int => $this->quotation($attributes)->booking_id,
            'booking_service_id' => null,
            'service_name' => '360 Video Booth',
            'package_name' => 'Premium',
            'start_at' => '2027-06-15 18:00:00',
            'end_at' => '2027-06-15 21:00:00',
            'duration_minutes' => 180,
            'quantity' => 1,
            'unit_rate' => '7500.00',
            'line_total' => '7500.00',
            'sort_order' => 0,
        ];
    }

    public function forQuotation(Quotation $quotation): static
    {
        return $this->state(fn (): array => [
            'quotation_id' => $quotation->id,
            'booking_id' => $quotation->booking_id,
        ]);
    }

    public function fromBookingService(Quotation $quotation, BookingService $bookingService): static
    {
        if ($quotation->booking_id !== $bookingService->booking_id) {
            throw new InvalidArgumentException('The source Booking Service must belong to the quoted Booking.');
        }

        $startAt = $bookingService->booking->start_at;

        return $this->forQuotation($quotation)->state(fn (): array => [
            'booking_service_id' => $bookingService->id,
            'service_name' => $bookingService->service_name,
            'package_name' => $bookingService->package_name,
            'start_at' => $startAt,
            'end_at' => $startAt->addMinutes($bookingService->duration_minutes),
            'duration_minutes' => $bookingService->duration_minutes,
            'quantity' => $bookingService->quantity,
            'unit_rate' => $bookingService->unit_rate,
            'line_total' => $bookingService->line_total,
            'sort_order' => $bookingService->sort_order,
        ]);
    }

    private function quotation(array $attributes): Quotation
    {
        return Quotation::query()->findOrFail($attributes['quotation_id']);
    }
}
