<?php

namespace App\Support\Bookings;

use App\Models\Organization;
use App\Models\Service;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class ServiceRowLocker
{
    /** @param list<int> $serviceIds
     * @return Collection<int, Service>
     */
    public function lock(Organization $organization, array $serviceIds): Collection
    {
        $ids = array_values(array_unique($serviceIds));
        sort($ids, SORT_NUMERIC);

        $services = Service::query()
            ->where('organization_id', $organization->id)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($services->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'booking_services' => 'One or more selected services are invalid.',
            ]);
        }

        return $services;
    }
}
