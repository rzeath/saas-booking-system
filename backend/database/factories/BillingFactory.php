<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Models\Billing;
use App\Models\Booking;
use App\Models\Quotation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Billing> */
class BillingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'quotation_id' => Quotation::factory()->accepted()->state([
                'booking_id' => Booking::factory()->state(['status' => BookingStatus::Quoted]),
                'closed_at' => '2027-05-02 10:00:00',
            ]),
            'organization_id' => fn (array $attributes): int => $this->quotation($attributes)->organization_id,
            'booking_id' => fn (array $attributes): int => $this->quotation($attributes)->booking_id,
            'billing_number' => fake()->unique()->numerify('INV-2027-######'),
            'quotation_number' => fn (array $attributes): string => $this->quotation($attributes)->quotation_number,
            'business_display_name' => fn (array $attributes): string => $this->quotation($attributes)->business_display_name,
            'business_email' => fn (array $attributes): ?string => $this->quotation($attributes)->business_email,
            'business_phone' => fn (array $attributes): ?string => $this->quotation($attributes)->business_phone,
            'business_address' => fn (array $attributes): ?string => $this->quotation($attributes)->business_address,
            'business_logo_path' => fn (array $attributes): ?string => $this->quotation($attributes)->business_logo_path,
            'customer_name' => fn (array $attributes): string => $this->quotation($attributes)->customer_name,
            'customer_email' => fn (array $attributes): ?string => $this->quotation($attributes)->customer_email,
            'customer_phone' => fn (array $attributes): ?string => $this->quotation($attributes)->customer_phone,
            'customer_address' => fn (array $attributes): ?string => $this->quotation($attributes)->customer_address,
            'event_type_name' => fn (array $attributes): string => $this->quotation($attributes)->event_type_name,
            'event_name' => fn (array $attributes): string => $this->quotation($attributes)->event_name,
            'event_date' => fn (array $attributes): string => $this->quotation($attributes)->event_date->format('Y-m-d'),
            'venue_name' => fn (array $attributes): string => $this->quotation($attributes)->venue_name,
            'venue_address' => fn (array $attributes): ?string => $this->quotation($attributes)->venue_address,
            'contact_person' => fn (array $attributes): string => $this->quotation($attributes)->contact_person,
            'contact_number' => fn (array $attributes): string => $this->quotation($attributes)->contact_number,
            'subtotal' => fn (array $attributes): string => $this->quotation($attributes)->subtotal,
            'transportation_fee' => fn (array $attributes): string => $this->quotation($attributes)->transportation_fee,
            'crew_meal_fee' => fn (array $attributes): string => $this->quotation($attributes)->crew_meal_fee,
            'discount_amount' => fn (array $attributes): string => $this->quotation($attributes)->discount_amount,
            'total' => fn (array $attributes): string => $this->quotation($attributes)->total,
            'created_by' => fn (array $attributes): int => $this->quotation($attributes)->created_by,
        ];
    }

    public function forQuotation(Quotation $quotation): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $quotation->organization_id,
            'booking_id' => $quotation->booking_id,
            'quotation_id' => $quotation->id,
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
            'event_date' => $quotation->event_date->format('Y-m-d'),
            'venue_name' => $quotation->venue_name,
            'venue_address' => $quotation->venue_address,
            'contact_person' => $quotation->contact_person,
            'contact_number' => $quotation->contact_number,
            'subtotal' => $quotation->subtotal,
            'transportation_fee' => $quotation->transportation_fee,
            'crew_meal_fee' => $quotation->crew_meal_fee,
            'discount_amount' => $quotation->discount_amount,
            'total' => $quotation->total,
            'created_by' => $quotation->created_by,
        ]);
    }

    private function quotation(array $attributes): Quotation
    {
        return Quotation::query()->findOrFail($attributes['quotation_id']);
    }
}
