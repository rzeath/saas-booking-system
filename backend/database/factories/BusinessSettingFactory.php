<?php

namespace Database\Factories;

use App\Models\BusinessSetting;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BusinessSetting> */
class BusinessSettingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            ...BusinessSetting::defaults(fake()->company()),
        ];
    }
}
