<?php

namespace App\Http\Requests;

use App\Models\Booking;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

class CancelBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $reason = trim((string) $this->input('reason'));
        $this->merge(['reason' => $reason === '' ? null : $reason]);
    }

    /** @return array<string, mixed> */
    public function rules(TenantContext $tenant): array
    {
        Booking::query()
            ->where('organization_id', $tenant->organizationId())
            ->whereKey($this->route('booking'))
            ->firstOrFail();

        return [
            'reason' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
