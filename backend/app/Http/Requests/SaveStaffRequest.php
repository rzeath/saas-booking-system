<?php

namespace App\Http\Requests;

use App\Models\Staff;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

class SaveStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'phone' => trim((string) $this->input('phone')),
            'email' => $this->nullableTrimmed('email'),
            'notes' => $this->nullableTrimmed('notes'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(TenantContext $tenant): array
    {
        if ($this->route('staff') !== null) {
            Staff::query()
                ->where('organization_id', $tenant->organizationId())
                ->whereKey($this->route('staff'))
                ->firstOrFail();
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
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
