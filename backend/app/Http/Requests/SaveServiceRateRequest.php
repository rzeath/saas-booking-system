<?php

namespace App\Http\Requests;

use App\Models\EventType;
use App\Models\Package;
use App\Models\ServiceRate;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class SaveServiceRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(TenantContext $tenant): array
    {
        $currentId = null;

        if ($this->route('service_rate') !== null) {
            $currentId = ServiceRate::query()
                ->where('organization_id', $tenant->organizationId())
                ->whereKey($this->route('service_rate'))
                ->firstOrFail()
                ->id;
        }

        return [
            'event_type_id' => [
                'required', 'integer',
                function (string $attribute, mixed $value, Closure $fail) use ($tenant): void {
                    if (! EventType::query()->where('organization_id', $tenant->organizationId())->whereKey($value)->exists()) {
                        $fail('The selected event type is invalid.');
                    }
                },
            ],
            'package_id' => [
                'required', 'integer',
                function (string $attribute, mixed $value, Closure $fail) use ($tenant): void {
                    if (! Package::query()->where('organization_id', $tenant->organizationId())->whereKey($value)->exists()) {
                        $fail('The selected package is invalid.');
                    }
                },
            ],
            'duration_minutes' => [
                'required', 'integer', 'min:1',
                function (string $attribute, mixed $value, Closure $fail) use ($currentId, $tenant): void {
                    if (! $this->filled('event_type_id') || ! $this->filled('package_id')) {
                        return;
                    }

                    $exists = ServiceRate::query()
                        ->where('organization_id', $tenant->organizationId())
                        ->where('event_type_id', $this->integer('event_type_id'))
                        ->where('package_id', $this->integer('package_id'))
                        ->where('duration_minutes', (int) $value)
                        ->when($currentId, fn ($query) => $query->whereKeyNot($currentId))
                        ->exists();

                    if ($exists) {
                        $fail('A rate already exists for this event type, package, and duration.');
                    }
                },
            ],
            'unit_rate' => ['required', 'decimal:0,2', 'min:0', 'max:99999999999.99'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
