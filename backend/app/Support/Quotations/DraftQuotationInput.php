<?php

namespace App\Support\Quotations;

use Illuminate\Support\Facades\Validator;

class DraftQuotationInput
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function validate(array $data): array
    {
        return Validator::make($data, [
            'transportation_fee' => ['sometimes'],
            'crew_meal_fee' => ['sometimes'],
            'discount_amount' => ['sometimes'],
            'valid_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ])->validated();
    }
}
