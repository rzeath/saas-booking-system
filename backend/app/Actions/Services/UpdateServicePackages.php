<?php

namespace App\Actions\Services;

use App\Models\BookingService;
use App\Models\Service;
use App\Models\ServiceRate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateServicePackages
{
    /** @param list<int> $packageIds */
    public function handle(Service $service, array $packageIds): Service
    {
        return DB::transaction(function () use ($service, $packageIds): Service {
            $lockedService = Service::query()->whereKey($service->id)->lockForUpdate()->firstOrFail();
            $currentIds = $lockedService->packages()->pluck('packages.id')->all();
            $removedIds = array_values(array_diff($currentIds, $packageIds));

            if ($removedIds !== [] && (
                ServiceRate::query()
                    ->where('organization_id', $lockedService->organization_id)
                    ->where('service_id', $lockedService->id)
                    ->whereIn('package_id', $removedIds)
                    ->exists()
                || BookingService::query()
                    ->where('organization_id', $lockedService->organization_id)
                    ->where('service_id', $lockedService->id)
                    ->whereIn('package_id', $removedIds)
                    ->exists()
            )) {
                throw ValidationException::withMessages([
                    'package_ids' => 'Packages used by rates or bookings cannot be unassigned from this service.',
                ]);
            }

            $mappings = collect($packageIds)->mapWithKeys(fn (int $packageId): array => [
                $packageId => ['organization_id' => $lockedService->organization_id],
            ]);
            $lockedService->packages()->sync($mappings);

            return $lockedService->refresh()->load('packages.services');
        }, 3);
    }
}
