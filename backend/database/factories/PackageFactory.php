<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Package;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Package> */
class PackageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->unique()->words(2, true),
            'is_active' => true,
        ];
    }

    public function forService(Service $service): static
    {
        return $this
            ->state(fn (): array => ['organization_id' => $service->organization_id])
            ->afterCreating(fn (Package $package) => $package->services()->attach($service->id, [
                'organization_id' => $service->organization_id,
            ]));
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
