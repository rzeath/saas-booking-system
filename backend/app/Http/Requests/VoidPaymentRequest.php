<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VoidPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $reason = trim((string) $this->input('void_reason'));
        $this->merge(['void_reason' => $reason === '' ? null : $reason]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'void_reason' => ['required', 'string', 'max:5000'],
            'status' => ['prohibited'],
            'voided_at' => ['prohibited'],
            'voided_by' => ['prohibited'],
            'currency' => ['prohibited'],
        ];
    }
}
