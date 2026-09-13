<?php

namespace App\Http\Requests;

use App\Enums\BookingStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BookingIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $search = trim((string) $this->input('search'));
        $this->merge(['search' => $search === '' ? null : $search]);
    }

    /** @return array<string, mixed> */
    public function rules(TenantContext $tenant): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(BookingStatus::class)],
            'event_date_from' => ['nullable', 'date_format:Y-m-d'],
            'event_date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:event_date_from'],
            'customer_id' => [
                'nullable',
                'integer',
                Rule::exists('customers', 'id')->where('organization_id', $tenant->organizationId()),
            ],
            'event_type_id' => [
                'nullable',
                'integer',
                Rule::exists('event_types', 'id')->where('organization_id', $tenant->organizationId()),
            ],
        ];
    }
}
