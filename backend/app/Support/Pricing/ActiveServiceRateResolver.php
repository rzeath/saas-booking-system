<?php

namespace App\Support\Pricing;

use App\Models\Organization;
use App\Models\ServiceRate;

class ActiveServiceRateResolver
{
    public function resolve(
        Organization $organization,
        int $eventTypeId,
        int $packageId,
        int $durationMinutes,
    ): ?ServiceRate {
        return ServiceRate::query()
            ->where('organization_id', $organization->id)
            ->where('event_type_id', $eventTypeId)
            ->where('package_id', $packageId)
            ->where('duration_minutes', $durationMinutes)
            ->where('is_active', true)
            ->whereHas('eventType', fn ($query) => $query->where('organization_id', $organization->id)->where('is_active', true))
            ->whereHas('package', fn ($query) => $query
                ->where('organization_id', $organization->id)
                ->where('is_active', true)
                ->whereHas('service', fn ($query) => $query
                    ->where('organization_id', $organization->id)
                    ->where('is_active', true)))
            ->first();
    }
}
