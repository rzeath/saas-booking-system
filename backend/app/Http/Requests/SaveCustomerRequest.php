<?php

namespace App\Http\Requests;

use App\Models\Customer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

class SaveCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'email' => $this->nullableTrimmed('email'),
            'phone' => $this->nullableTrimmed('phone'),
            'address' => $this->nullableTrimmed('address'),
            'notes' => $this->nullableTrimmed('notes'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(TenantContext $tenant): array
    {
        if ($this->route('customer') !== null) {
            Customer::query()
                ->where('organization_id', $tenant->organizationId())
                ->whereKey($this->route('customer'))
                ->firstOrFail();
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function nullableTrimmed(string $key): ?string
    {
        $value = trim((string) $this->input($key));

        return $value === '' ? null : $value;
    }
}
