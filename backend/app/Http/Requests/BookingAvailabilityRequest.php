<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BookingAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'event_date' => ['required', 'date_format:Y-m-d'],
            'booking_services' => ['required', 'array', 'min:1', 'max:100'],
            'booking_services.*.service_id' => ['required', 'integer'],
            'booking_services.*.package_id' => ['required', 'integer'],
            'booking_services.*.local_start_time' => ['required', 'date_format:H:i'],
            'booking_services.*.duration_minutes' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'booking_services.*.quantity' => ['required', 'integer', 'min:1', 'max:4294967295'],
        ];
    }
}
