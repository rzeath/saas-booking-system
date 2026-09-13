<?php

namespace App\Actions\Services;

use App\Models\Organization;
use App\Models\Service;
use App\Support\Bookings\ServiceAvailabilityChecker;
use App\Support\Bookings\ServiceRowLocker;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateService
{
    public function __construct(
        private readonly ServiceRowLocker $serviceLocker,
        private readonly ServiceAvailabilityChecker $availability,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(Organization $organization, int $serviceId, array $data): Service
    {
        return DB::transaction(function () use ($organization, $serviceId, $data): Service {
            $service = $this->serviceLocker->lock($organization, [$serviceId])->get($serviceId);
            $reservedPeak = $this->availability->peakReservedQuantity(
                $organization->id,
                $serviceId,
                lockReservations: true,
            );

            if ((int) $data['total_units'] < $reservedPeak) {
                throw ValidationException::withMessages([
                    'total_units' => "Total units cannot be lower than the currently reserved peak of {$reservedPeak}.",
                ]);
            }

            $service->update($data);

            return $service->refresh();
        }, 3);
    }
}
