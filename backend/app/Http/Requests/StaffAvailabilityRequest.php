<?php

namespace App\Http\Requests;

use App\Models\BookingService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

class StaffAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'start_time' => trim((string) $this->input('start_time')),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(TenantContext $tenant): array
    {
        if ($this->filled('booking_service_id')) {
            BookingService::query()
                ->where('organization_id', $tenant->organizationId())
                ->whereKey($this->input('booking_service_id'))
                ->firstOrFail();
        }

        return [
            'booking_service_id' => ['sometimes', 'integer'],
            'event_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:4294967295'],
        ];
    }
}
