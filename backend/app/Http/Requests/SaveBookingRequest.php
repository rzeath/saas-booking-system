<?php

namespace App\Http\Requests;

use App\Models\Booking;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $lines = array_map(function (mixed $line): mixed {
            if (! is_array($line)) {
                return $line;
            }

            if (array_key_exists('start_time', $line)) {
                $line['start_time'] = trim((string) $line['start_time']);
            }

            return $line;
        }, $this->input('booking_services', []));

        $this->merge([
            'event_name' => trim((string) $this->input('event_name')),
            'venue_name' => trim((string) $this->input('venue_name')),
            'venue_address' => $this->nullableTrimmed('venue_address'),
            'contact_person' => trim((string) $this->input('contact_person')),
            'contact_number' => trim((string) $this->input('contact_number')),
            'internal_notes' => $this->nullableTrimmed('internal_notes'),
            'booking_services' => $lines,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(TenantContext $tenant): array
    {
        $isUpdate = $this->route('booking') !== null;

        if ($isUpdate) {
            Booking::query()
                ->where('organization_id', $tenant->organizationId())
                ->whereKey($this->route('booking'))
                ->firstOrFail();
        }

        return [
            'customer_id' => ['required', 'integer'],
            'event_type_id' => ['required', 'integer'],
            'event_name' => ['required', 'string', 'max:255'],
            'event_date' => ['required', 'date_format:Y-m-d'],
            'venue_name' => ['required', 'string', 'max:255'],
            'venue_address' => ['nullable', 'string', 'max:5000'],
            'contact_person' => ['required', 'string', 'max:255'],
            'contact_number' => ['required', 'string', 'max:50'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['prohibited'],
            'booking_services' => ['required', 'array', 'min:1', 'max:100'],
            'booking_services.*.id' => [
                Rule::prohibitedIf(! $isUpdate),
                'sometimes',
                'integer',
                'distinct',
            ],
            'booking_services.*.service_id' => ['required', 'integer'],
            'booking_services.*.package_id' => ['required', 'integer'],
            'booking_services.*.start_time' => ['required', 'date_format:H:i'],
            'booking_services.*.duration_minutes' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'booking_services.*.quantity' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'booking_services.*.staff_ids' => ['sometimes', 'array', 'max:100'],
            'booking_services.*.staff_ids.*' => ['required', 'integer'],
        ];
    }

    private function nullableTrimmed(string $key): ?string
    {
        $value = trim((string) $this->input($key));

        return $value === '' ? null : $value;
    }
}
