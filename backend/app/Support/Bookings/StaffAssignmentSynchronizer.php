<?php

namespace App\Support\Bookings;

use App\Models\BookingService;
use App\Models\User;

class StaffAssignmentSynchronizer
{
    /** @param list<int> $staffIds */
    public function sync(BookingService $bookingService, array $staffIds, User $user): void
    {
        $existing = $bookingService->staffAssignments()
            ->get()
            ->keyBy('staff_id');

        $bookingService->staffAssignments()
            ->whereNotIn('staff_id', $staffIds)
            ->delete();

        $assignedAt = now('UTC');
        foreach ($staffIds as $staffId) {
            if ($existing->has($staffId)) {
                continue;
            }

            $bookingService->staffAssignments()->create([
                'organization_id' => $bookingService->organization_id,
                'staff_id' => $staffId,
                'assigned_by' => $user->id,
                'assigned_at' => $assignedAt,
            ]);
        }
    }
}
