<?php

namespace Database\Factories;

use App\Models\EventType;
use App\Models\Organization;
use App\Models\Package;
use App\Models\Service;
use App\Models\ServiceRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ServiceRate> */
class ServiceRateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'event_type_id' => fn (array $attributes) => EventType::factory()->create([
                'organization_id' => $attributes['organization_id'],
            ])->id,
            'service_id' => fn (array $attributes) => Service::factory()->create([
                'organization_id' => $attributes['organization_id'],
            ])->id,
            'package_id' => function (array $attributes): int {
                return Package::factory()
                    ->forService(Service::query()->findOrFail($attributes['service_id']))
                    ->create(['organization_id' => $attributes['organization_id']])
                    ->id;
            },
            'duration_minutes' => fake()->randomElement([120, 180, 240]),
            'unit_rate' => fake()->randomElement(['5000.00', '7500.00', '10000.00']),
            'is_active' => true,
        ];
    }

    public function forCombination(EventType $eventType, Service $service, Package $package): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $eventType->organization_id,
            'event_type_id' => $eventType->id,
            'service_id' => $service->id,
            'package_id' => $package->id,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
