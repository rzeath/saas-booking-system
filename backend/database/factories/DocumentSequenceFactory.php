<?php

namespace Database\Factories;

use App\Enums\DocumentType;
use App\Models\DocumentSequence;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DocumentSequence> */
class DocumentSequenceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'document_type' => DocumentType::Booking,
            'year' => 2027,
            'next_number' => 1,
        ];
    }
}
