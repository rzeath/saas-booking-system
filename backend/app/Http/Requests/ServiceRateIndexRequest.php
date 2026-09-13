<?php

namespace App\Http\Requests;

class ServiceRateIndexRequest extends MasterDataIndexRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'service_id' => ['sometimes', 'integer', 'min:1'],
            'package_id' => ['sometimes', 'integer', 'min:1'],
            'event_type_id' => ['sometimes', 'integer', 'min:1'],
            'duration_minutes' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
