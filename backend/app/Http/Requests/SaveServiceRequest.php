<?php

namespace App\Http\Requests;

use App\Models\Service;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class SaveServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name'))]);
    }

    /** @return array<string, mixed> */
    public function rules(TenantContext $tenant): array
    {
        $currentId = null;

        if ($this->route('service') !== null) {
            $currentId = Service::query()
                ->where('organization_id', $tenant->organizationId())
                ->whereKey($this->route('service'))
                ->firstOrFail()
                ->id;
        }

        return [
            'name' => [
                'required', 'string', 'max:255',
                function (string $attribute, mixed $value, Closure $fail) use ($currentId, $tenant): void {
                    $exists = Service::query()
                        ->where('organization_id', $tenant->organizationId())
                        ->when($currentId, fn ($query) => $query->whereKeyNot($currentId))
                        ->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $value)])
                        ->exists();

                    if ($exists) {
                        $fail('A service with this name already exists.');
                    }
                },
            ],
            'total_units' => ['required', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
