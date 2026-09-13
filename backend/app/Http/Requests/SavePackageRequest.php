<?php

namespace App\Http\Requests;

use App\Models\Package;
use App\Models\Service;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class SavePackageRequest extends FormRequest
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
        $serviceId = null;
        $currentId = null;

        if ($this->route('service') !== null) {
            $serviceId = Service::query()
                ->where('organization_id', $tenant->organizationId())
                ->whereKey($this->route('service'))
                ->firstOrFail()
                ->id;
        }

        if ($this->route('package') !== null) {
            $package = Package::query()
                ->where('organization_id', $tenant->organizationId())
                ->whereKey($this->route('package'))
                ->firstOrFail();
            $currentId = $package->id;
            $serviceId = $package->service_id;
        }

        return [
            'name' => [
                'required', 'string', 'max:255',
                function (string $attribute, mixed $value, Closure $fail) use ($currentId, $serviceId, $tenant): void {
                    $exists = Package::query()
                        ->where('organization_id', $tenant->organizationId())
                        ->where('service_id', $serviceId)
                        ->when($currentId, fn ($query) => $query->whereKeyNot($currentId))
                        ->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $value)])
                        ->exists();

                    if ($exists) {
                        $fail('A package with this name already exists for the service.');
                    }
                },
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
