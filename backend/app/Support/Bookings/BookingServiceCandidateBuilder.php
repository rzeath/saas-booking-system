<?php

namespace App\Support\Bookings;

use App\Models\Organization;
use App\Models\Package;
use App\Models\Service;
use App\Support\Pricing\ActiveServiceRateResolver;
use DateInterval;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class BookingServiceCandidateBuilder
{
    public function __construct(
        private readonly ManilaSchedule $schedule,
        private readonly ActiveServiceRateResolver $rateResolver,
        private readonly ExactMoney $money,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  Collection<int, Service>|null  $resolvedServices
     * @return list<BookingServiceCandidate>
     */
    public function build(
        Organization $organization,
        string $eventDate,
        array $lines,
        ?int $eventTypeId = null,
        ?Collection $resolvedServices = null,
    ): array {
        $candidates = [];

        foreach ($lines as $index => $line) {
            $serviceId = (int) $line['service_id'];
            $service = $resolvedServices?->get($serviceId)
                ?? Service::query()
                    ->where('organization_id', $organization->id)
                    ->whereKey($serviceId)
                    ->first();

            if (! $service instanceof Service || ! $service->is_active) {
                throw ValidationException::withMessages([
                    "booking_services.{$index}.service_id" => 'The selected service is invalid or inactive.',
                ]);
            }

            $package = Package::query()
                ->where('organization_id', $organization->id)
                ->where('service_id', $service->id)
                ->whereKey((int) $line['package_id'])
                ->where('is_active', true)
                ->first();

            if (! $package instanceof Package) {
                throw ValidationException::withMessages([
                    "booking_services.{$index}.package_id" => 'The selected package is invalid, inactive, or does not belong to the service.',
                ]);
            }

            $durationMinutes = (int) $line['duration_minutes'];
            $startAt = $this->schedule->startAt(
                $eventDate,
                (string) $line['start_time'],
                "booking_services.{$index}.start_time",
            );
            $endAt = $startAt->add(new DateInterval("PT{$durationMinutes}M"));
            $unitRate = null;
            $lineTotal = null;

            if ($eventTypeId !== null) {
                $rate = $this->rateResolver->resolve(
                    $organization,
                    $eventTypeId,
                    $package->id,
                    $durationMinutes,
                );

                if ($rate === null) {
                    throw ValidationException::withMessages([
                        "booking_services.{$index}.duration_minutes" => 'No active rate exists for this event type, package, and duration.',
                    ]);
                }

                $unitRate = $rate->unit_rate;
                $lineTotal = $this->money->multiply(
                    $unitRate,
                    (int) $line['quantity'],
                    "booking_services.{$index}.quantity",
                );
            }

            $candidates[] = new BookingServiceCandidate(
                isset($line['id']) ? (int) $line['id'] : null,
                $service,
                $package,
                $startAt,
                $endAt,
                $durationMinutes,
                (int) $line['quantity'],
                $unitRate,
                $lineTotal,
                $index,
            );
        }

        return $candidates;
    }
}
