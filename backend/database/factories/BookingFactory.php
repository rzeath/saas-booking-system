<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\EventType;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Booking> */
class BookingFactory extends Factory
{
    public function definition(): array
    {
        $customerName = fake()->name();
        $eventTypeName = fake()->randomElement(['Wedding', 'Birthday', 'Corporate Event']);

        return [
            'organization_id' => Organization::factory(),
            'booking_number' => fake()->unique()->numerify('BK-2027-######'),
            'customer_id' => fn (array $attributes) => Customer::factory()->create([
                'organization_id' => $attributes['organization_id'],
                'name' => $customerName,
            ])->id,
            'event_type_id' => fn (array $attributes) => EventType::factory()->create([
                'organization_id' => $attributes['organization_id'],
                'name' => $eventTypeName,
            ])->id,
            'customer_name' => $customerName,
            'customer_email' => fake()->safeEmail(),
            'customer_phone' => fake()->phoneNumber(),
            'customer_address' => fake()->address(),
            'event_type_name' => $eventTypeName,
            'event_name' => fake()->words(3, true),
            'event_date' => '2027-06-15',
            'venue_name' => fake()->company(),
            'venue_address' => fake()->address(),
            'contact_person' => fake()->name(),
            'contact_number' => fake()->phoneNumber(),
            'status' => BookingStatus::Pending,
            'internal_notes' => null,
            'created_by' => fn (array $attributes) => User::factory()->create([
                'organization_id' => $attributes['organization_id'],
            ])->id,
        ];
    }

    public function cancelled(User $user): static
    {
        return $this->state(fn (): array => [
            'status' => BookingStatus::Cancelled,
            'cancelled_at' => '2027-01-01 00:00:00',
            'cancelled_by' => $user->id,
        ]);
    }

    public function completed(User $user): static
    {
        return $this->state(fn (): array => [
            'status' => BookingStatus::Completed,
            'completed_at' => '2027-01-01 00:00:00',
            'completed_by' => $user->id,
        ]);
    }
}
