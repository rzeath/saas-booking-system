<?php

namespace App\Support\Bookings;

use App\Models\Staff;
use DateTimeInterface;

class StaffAvailabilityFinder
{
    public function __construct(private readonly StaffAvailabilityChecker $availability) {}

    /** @return list<array{id: int, name: string, available: bool}> */
    public function forSchedule(
        int $organizationId,
        DateTimeInterface $startAt,
        DateTimeInterface $endAt,
        ?int $excludeBookingServiceId = null,
    ): array {
        $staff = Staff::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
        $conflictingIds = $this->availability->conflictingStaffIds(
            $organizationId,
            $staff->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            $startAt,
            $endAt,
            $excludeBookingServiceId,
        );

        return $staff->map(fn (Staff $member): array => [
            'id' => $member->id,
            'name' => $member->name,
            'available' => ! in_array($member->id, $conflictingIds, true),
        ])->values()->all();
    }
}
