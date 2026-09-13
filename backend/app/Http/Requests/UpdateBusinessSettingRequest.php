<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBusinessSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'display_name' => $this->trimmed('display_name'),
            'email' => $this->nullableTrimmed('email'),
            'phone' => $this->nullableTrimmed('phone'),
            'address' => $this->nullableTrimmed('address'),
            'timezone' => $this->trimmed('timezone'),
            'currency' => mb_strtoupper($this->trimmed('currency')),
            'booking_prefix' => mb_strtoupper($this->trimmed('booking_prefix')),
            'quotation_prefix' => mb_strtoupper($this->trimmed('quotation_prefix')),
            'billing_prefix' => mb_strtoupper($this->trimmed('billing_prefix')),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:2000'],
            'timezone' => ['required', 'timezone', 'max:64'],
            'currency' => ['required', 'string', 'size:3', 'regex:/\A[A-Z]{3}\z/'],
            'booking_prefix' => $this->prefixRules(),
            'quotation_prefix' => $this->prefixRules(),
            'billing_prefix' => $this->prefixRules(),
        ];
    }

    /** @return array<int, string> */
    private function prefixRules(): array
    {
        return ['required', 'string', 'max:10', 'regex:/\A[A-Z0-9]+\z/'];
    }

    private function trimmed(string $key): string
    {
        return trim((string) $this->input($key));
    }

    private function nullableTrimmed(string $key): ?string
    {
        $value = $this->trimmed($key);

        return $value === '' ? null : $value;
    }
}
