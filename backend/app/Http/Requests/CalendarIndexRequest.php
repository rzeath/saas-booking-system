<?php

namespace App\Http\Requests;

use App\Enums\BookingStatus;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CalendarIndexRequest extends FormRequest
{
    public const int MAX_RANGE_DAYS = 62;

    /** @var list<string> */
    private const array DEFAULT_STATUSES = [
        BookingStatus::Pending->value,
        BookingStatus::Quoted->value,
        BookingStatus::Confirmed->value,
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(TenantContext $tenant): array
    {
        return [
            'start' => ['required', 'date_format:Y-m-d'],
            'end' => ['required', 'date_format:Y-m-d', 'after:start'],
            'statuses' => ['sometimes', 'array', 'min:1'],
            'statuses.*' => ['required', 'string', 'distinct', Rule::enum(BookingStatus::class)],
            'service_id' => [
                'sometimes',
                'integer',
                Rule::exists('services', 'id')->where('organization_id', $tenant->organizationId()),
            ],
            'staff_id' => [
                'sometimes',
                'integer',
                Rule::prohibitedIf($this->filled('staff')),
                Rule::exists('staff', 'id')->where('organization_id', $tenant->organizationId()),
            ],
            'staff' => [
                'sometimes',
                'string',
                Rule::prohibitedIf($this->filled('staff_id')),
                Rule::in(['unassigned']),
            ],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['start', 'end'])) {
                return;
            }

            if ($this->rangeStart()->diffInDays($this->rangeEnd()) > self::MAX_RANGE_DAYS) {
                $validator->errors()->add(
                    'end',
                    sprintf('The calendar range may not exceed %d days.', self::MAX_RANGE_DAYS),
                );
            }
        }];
    }

    public function rangeStart(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(
            '!Y-m-d',
            (string) $this->validated('start'),
            (string) config('app.timezone'),
        );
    }

    public function rangeEnd(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(
            '!Y-m-d',
            (string) $this->validated('end'),
            (string) config('app.timezone'),
        );
    }

    /** @return list<string> */
    public function statuses(): array
    {
        return array_values($this->validated('statuses', self::DEFAULT_STATUSES));
    }

    public function serviceId(): ?int
    {
        $serviceId = $this->validated('service_id');

        return $serviceId === null ? null : (int) $serviceId;
    }

    public function staffId(): ?int
    {
        $staffId = $this->validated('staff_id');

        return $staffId === null ? null : (int) $staffId;
    }

    public function isUnassigned(): bool
    {
        return $this->validated('staff') === 'unassigned';
    }
}
