<?php

namespace Database\Factories;

use App\Enums\QuotationStatus;
use App\Models\Booking;
use App\Models\Quotation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Quotation> */
class QuotationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'organization_id' => fn (array $attributes): int => $this->booking($attributes)->organization_id,
            'quotation_number' => fake()->unique()->numerify('QT-2027-######'),
            'status' => QuotationStatus::Draft,
            'valid_until' => '2027-05-31',
            'sent_at' => null,
            'accepted_at' => null,
            'closed_at' => null,
            'business_display_name' => fake()->company(),
            'business_email' => fake()->companyEmail(),
            'business_phone' => fake()->phoneNumber(),
            'business_address' => fake()->address(),
            'business_logo_path' => null,
            'customer_name' => fn (array $attributes): string => $this->booking($attributes)->customer_name,
            'customer_email' => fn (array $attributes): ?string => $this->booking($attributes)->customer_email,
            'customer_phone' => fn (array $attributes): ?string => $this->booking($attributes)->customer_phone,
            'customer_address' => fn (array $attributes): ?string => $this->booking($attributes)->customer_address,
            'event_type_name' => fn (array $attributes): string => $this->booking($attributes)->event_type_name,
            'event_name' => fn (array $attributes): string => $this->booking($attributes)->event_name,
            'event_date' => fn (array $attributes): string => $this->booking($attributes)->event_date->format('Y-m-d'),
            'venue_name' => fn (array $attributes): string => $this->booking($attributes)->venue_name,
            'venue_address' => fn (array $attributes): ?string => $this->booking($attributes)->venue_address,
            'contact_person' => fn (array $attributes): string => $this->booking($attributes)->contact_person,
            'contact_number' => fn (array $attributes): string => $this->booking($attributes)->contact_number,
            'subtotal' => '7500.00',
            'transportation_fee' => '500.00',
            'crew_meal_fee' => '250.00',
            'discount_amount' => '250.00',
            'total' => '8000.00',
            'created_by' => fn (array $attributes): int => $this->booking($attributes)->created_by,
        ];
    }

    public function forBooking(Booking $booking): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $booking->organization_id,
            'booking_id' => $booking->id,
            'customer_name' => $booking->customer_name,
            'customer_email' => $booking->customer_email,
            'customer_phone' => $booking->customer_phone,
            'customer_address' => $booking->customer_address,
            'event_type_name' => $booking->event_type_name,
            'event_name' => $booking->event_name,
            'event_date' => $booking->event_date->format('Y-m-d'),
            'venue_name' => $booking->venue_name,
            'venue_address' => $booking->venue_address,
            'contact_person' => $booking->contact_person,
            'contact_number' => $booking->contact_number,
            'created_by' => $booking->created_by,
        ]);
    }

    public function sent(): static
    {
        return $this->state(fn (): array => [
            'status' => QuotationStatus::Sent,
            'sent_at' => '2027-05-01 09:00:00',
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn (): array => [
            'status' => QuotationStatus::Accepted,
            'sent_at' => '2027-05-01 09:00:00',
            'accepted_at' => '2027-05-02 10:00:00',
        ]);
    }

    public function rejected(): static
    {
        return $this->closed(QuotationStatus::Rejected);
    }

    public function cancelled(): static
    {
        return $this->closed(QuotationStatus::Cancelled);
    }

    public function expired(): static
    {
        return $this->closed(QuotationStatus::Expired);
    }

    public function outdated(): static
    {
        return $this->closed(QuotationStatus::Outdated);
    }

    private function booking(array $attributes): Booking
    {
        return Booking::query()->findOrFail($attributes['booking_id']);
    }

    private function closed(QuotationStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'sent_at' => '2027-05-01 09:00:00',
            'closed_at' => '2027-05-02 10:00:00',
        ]);
    }
}
