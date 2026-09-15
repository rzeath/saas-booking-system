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
            'package_id' => function (array $attributes) use ($packageName): int {
                $package = Package::factory()->create([
                    'organization_id' => $attributes['organization_id'],
                    'name' => $packageName,
                ]);
                $package->services()->attach($attributes['service_id'], [
                    'organization_id' => $attributes['organization_id'],
                ]);

                return $package->id;
            },
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

    public function forPackage(Package $package, ?Service $service = null): static
    {
        $service ??= $package->services()->firstOrFail();

        return $this->state(fn (): array => [
            'organization_id' => $package->organization_id,
            'service_id' => $service->id,
            'package_id' => $package->id,
            'service_name' => $service->name,
            'package_name' => $package->name,
        ]);
    }
}
