<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\BookingService;
use App\Models\Organization;
use App\Models\Package;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BookingService> */
class BookingServiceFactory extends Factory
{
    public function definition(): array
    {
        $serviceName = fake()->words(2, true);
        $packageName = fake()->randomElement(['Basic', 'Premium', 'Deluxe']);

        return [
            'organization_id' => Organization::factory(),
            'booking_id' => fn (array $attributes) => Booking::factory()->create([
                'organization_id' => $attributes['organization_id'],
            ])->id,
            'service_id' => fn (array $attributes) => Service::factory()->create([
                'organization_id' => $attributes['organization_id'],
                'name' => $serviceName,
            ])->id,
            'package_id' => fn (array $attributes) => Package::factory()->create([
                'organization_id' => $attributes['organization_id'],
                'service_id' => $attributes['service_id'],
                'name' => $packageName,
            ])->id,
            'start_at' => '2027-06-15 18:00:00',
            'end_at' => '2027-06-15 21:00:00',
            'duration_minutes' => 180,
            'quantity' => 1,
            'service_name' => $serviceName,
            'package_name' => $packageName,
            'unit_rate' => '7500.00',
            'line_total' => '7500.00',
            'sort_order' => 0,
        ];
    }

    public function forBooking(Booking $booking): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $booking->organization_id,
            'booking_id' => $booking->id,
        ]);
    }

    public function forPackage(Package $package): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $package->organization_id,
            'service_id' => $package->service_id,
            'package_id' => $package->id,
            'service_name' => $package->service->name,
            'package_name' => $package->name,
        ]);
    }
}
