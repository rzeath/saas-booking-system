<?php

namespace App\Http\Requests;

use App\Models\Booking;
use App\Models\Quotation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

class SaveDraftQuotationRequest extends FormRequest
{
    private const PROHIBITED_FIELDS = [
        'organization_id',
        'booking_id',
        'quotation_number',
        'status',
        'items',
        'quotation_items',
        'subtotal',
        'total',
        'seller_snapshot',
        'customer_snapshot',
        'event_snapshot',
        'business_display_name',
        'business_email',
        'business_phone',
        'business_address',
        'business_logo_path',
        'customer_name',
        'customer_email',
        'customer_phone',
        'customer_address',
        'event_type_name',
        'event_name',
        'event_date',
        'venue_name',
        'venue_address',
        'contact_person',
        'contact_number',
        'currency',
        'service_id',
        'package_id',
        'unit_rate',
        'line_total',
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['transportation_fee', 'crew_meal_fee', 'discount_amount'] as $field) {
            $value = $this->input($field);

            if (is_int($value)) {
                $this->merge([$field => (string) $value]);
            } elseif (is_string($value)) {
                $this->merge([$field => trim($value)]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(TenantContext $tenant): array
    {
        if ($this->route('booking') !== null) {
            Booking::query()
                ->where('organization_id', $tenant->organizationId())
                ->whereKey($this->route('booking'))
                ->firstOrFail();
        }

        if ($this->route('quotation') !== null) {
            Quotation::query()
                ->where('organization_id', $tenant->organizationId())
                ->whereKey($this->route('quotation'))
                ->firstOrFail();
        }

        $rules = [
            'transportation_fee' => ['sometimes', 'string', 'regex:/^\d{1,11}(?:\.\d{1,2})?$/'],
            'crew_meal_fee' => ['sometimes', 'string', 'regex:/^\d{1,11}(?:\.\d{1,2})?$/'],
            'discount_amount' => ['sometimes', 'string', 'regex:/^\d{1,11}(?:\.\d{1,2})?$/'],
            'valid_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];

        foreach (self::PROHIBITED_FIELDS as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }
}
