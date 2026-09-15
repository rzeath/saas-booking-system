<?php

namespace App\Http\Requests;

use App\Enums\ThemeAccent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'theme_accent' => ['required', Rule::enum(ThemeAccent::class)],
            'logo' => ['sometimes', 'nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_logo' => ['sometimes', 'boolean'],
            'booking_prefix' => $this->prefixRules(),
            'quotation_prefix' => $this->prefixRules(),
            'billing_prefix' => $this->prefixRules(),
            'organization_id' => ['prohibited'],
            'currency' => ['prohibited'],
            'timezone' => ['prohibited'],
            'logo_path' => ['prohibited'],
            'logo_url' => ['prohibited'],
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
