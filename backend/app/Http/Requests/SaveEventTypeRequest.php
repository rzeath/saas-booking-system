<?php

namespace App\Http\Requests;

use App\Models\EventType;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class SaveEventTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(TenantContext $tenant): array
    {
        $currentId = null;

        if ($this->route('event_type') !== null) {
            $currentId = EventType::query()
                ->where('organization_id', $tenant->organizationId())
                ->whereKey($this->route('event_type'))
                ->firstOrFail()
                ->id;
        }

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail) use ($currentId, $tenant): void {
                    $duplicate = EventType::query()
                        ->where('organization_id', $tenant->organizationId())
                        ->when($currentId, fn ($query) => $query->whereKeyNot($currentId))
                        ->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $value)])
                        ->exists();

                    if ($duplicate) {
                        $fail('An event type with this name already exists.');
                    }
                },
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
