<?php

namespace App\Support\Bookings;

use App\Models\BookingService;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class StaffAssignmentValidator
{
    /**
     * @param  list<BookingServiceCandidate>  $candidates
     * @param  Collection<int, Staff>  $staff
     * @param  Collection<int, BookingService>|null  $existingLines
     */
    public function validate(
        array $candidates,
        Collection $staff,
        ?Collection $existingLines = null,
    ): void {
        foreach ($candidates as $index => $candidate) {
            if (count($candidate->staffIds) !== count(array_unique($candidate->staffIds))) {
                throw ValidationException::withMessages([
                    "booking_services.{$index}.staff_ids" => 'The same staff member cannot be assigned more than once.',
                ]);
            }

            $retainedIds = $candidate->id === null
                ? []
                : $existingLines?->get($candidate->id)?->assignedStaff
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->all() ?? [];

            $this->validateIds(
                $candidate->staffIds,
                $staff,
                $retainedIds,
                "booking_services.{$index}.staff_ids",
            );
        }
    }

    /**
     * @param  list<int>  $staffIds
     * @param  Collection<int, Staff>  $staff
     * @param  list<int>  $retainedIds
     */
    public function validateIds(
        array $staffIds,
        Collection $staff,
        array $retainedIds,
        string $attribute,
    ): void {
        foreach ($staffIds as $staffId) {
            $member = $staff->get($staffId);

            if (! $member instanceof Staff) {
                throw ValidationException::withMessages([
                    $attribute => 'One or more selected staff are invalid.',
                ]);
            }

            if (! $member->is_active && ! in_array($staffId, $retainedIds, true)) {
                throw ValidationException::withMessages([
                    $attribute => 'Inactive staff cannot receive a new assignment.',
                ]);
            }
        }
    }
}
