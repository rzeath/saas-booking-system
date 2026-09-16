<?php

namespace App\Http\Requests;

use App\Models\Booking;
use App\Models\BookingService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

class BookingAvailabilityRequest extends FormRequest
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
        if ($this->filled('booking_id')) {
            Booking::query()
                ->where('organization_id', $tenant->organizationId())
                ->whereKey($this->input('booking_id'))
                ->firstOrFail();
        }

        return [
            'booking_id' => ['sometimes', 'integer'],
            'event_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'start_at' => ['prohibited'],
            'end_at' => ['prohibited'],
            'booking_services' => ['required', 'array', 'min:1', 'max:100'],
            'booking_services.*.service_id' => ['required', 'integer'],
            'booking_services.*.package_id' => ['required', 'integer'],
            'booking_services.*.start_time' => ['prohibited'],
            'booking_services.*.start_at' => ['prohibited'],
            'booking_services.*.end_at' => ['prohibited'],
            'booking_services.*.duration_minutes' => [
                'required',
                'integer',
                'min:'.BookingService::MIN_DURATION_MINUTES,
                'max:'.BookingService::MAX_DURATION_MINUTES,
            ],
            'booking_services.*.quantity' => ['required', 'integer', 'min:1', 'max:4294967295'],
        ];
    }
}
