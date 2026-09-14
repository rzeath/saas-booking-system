<?php

namespace App\Http\Requests;

use App\Models\Package;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class UpdateServicePackagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(TenantContext $tenant): array
    {
        return [
            'package_ids' => ['required', 'array'],
            'package_ids.*' => [
                'integer',
                'distinct',
                function (string $attribute, mixed $value, Closure $fail) use ($tenant): void {
                    if (! Package::query()
                        ->where('organization_id', $tenant->organizationId())
                        ->whereKey($value)
                        ->exists()) {
                        $fail('The selected package is invalid.');
                    }
                },
            ],
        ];
    }
}
