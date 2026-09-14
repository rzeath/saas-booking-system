<?php

namespace App\Support\Bookings;

use App\Models\Organization;
use App\Models\Staff;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class StaffRowLocker
{
    /**
     * @param  list<int>  $staffIds
     * @return Collection<int, Staff>
     */
    public function lock(Organization $organization, array $staffIds): Collection
    {
        $ids = array_values(array_unique($staffIds));
        sort($ids, SORT_NUMERIC);

        if ($ids === []) {
            return new Collection;
        }

        $staff = Staff::query()
            ->where('organization_id', $organization->id)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($staff->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'booking_services' => 'One or more selected staff are invalid.',
            ]);
        }

        return $staff;
    }
}
