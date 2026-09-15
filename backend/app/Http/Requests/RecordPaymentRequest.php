<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordPaymentRequest extends FormRequest
{
    private const PROHIBITED_FIELDS = [
        'organization_id',
        'booking_id',
        'quotation_id',
        'billing_id',
        'billing_number',
        'status',
        'created_by',
        'voided_at',
        'voided_by',
        'void_reason',
        'currency',
        'subtotal',
        'transportation_fee',
        'crew_meal_fee',
        'discount_amount',
        'total',
        'amount_paid',
        'remaining_balance',
        'payment_status',
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $amount = $this->input('amount');

        if (is_int($amount)) {
            $this->merge(['amount' => (string) $amount]);
        } elseif (is_string($amount)) {
            $this->merge(['amount' => trim($amount)]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'amount' => ['required', 'string', 'regex:/^-?\d{1,11}(?:\.\d{1,2})?$/'],
            'paid_at' => ['required', 'string', 'date_format:Y-m-d H:i:s'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'internal_note' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];

        foreach (self::PROHIBITED_FIELDS as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }
}
