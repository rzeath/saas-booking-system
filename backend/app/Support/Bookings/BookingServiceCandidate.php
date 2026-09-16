<?php

namespace App\Support\Bookings;

use App\Models\Package;
use App\Models\Service;
use DateTimeImmutable;

final readonly class BookingServiceCandidate
{
    public function __construct(
        public ?int $id,
        public Service $service,
        public Package $package,
        public DateTimeImmutable $startAt,
        public DateTimeImmutable $endAt,
        public int $durationMinutes,
        public int $quantity,
        /** @var list<int> */
        public array $staffIds,
        public ?string $unitRate,
        public ?string $lineTotal,
        public int $sortOrder,
    ) {}

    /** @return array<string, mixed> */
    public function persistenceAttributes(int $organizationId): array
    {
        return [
            'organization_id' => $organizationId,
            'service_id' => $this->service->id,
            'package_id' => $this->package->id,
            'duration_minutes' => $this->durationMinutes,
            'quantity' => $this->quantity,
            'service_name' => $this->service->name,
            'package_name' => $this->package->name,
            'unit_rate' => $this->unitRate,
            'line_total' => $this->lineTotal,
            'sort_order' => $this->sortOrder,
        ];
    }
}
