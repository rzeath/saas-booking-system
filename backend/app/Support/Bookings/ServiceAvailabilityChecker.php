<?php

namespace App\Support\Bookings;

use App\Enums\BookingStatus;
use App\Models\BookingService;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ServiceAvailabilityChecker
{
    public function peakReservedQuantity(
        int $organizationId,
        int $serviceId,
        bool $lockReservations = false,
    ): int {
        $query = BookingService::query()
            ->select('booking_services.*')
            ->join('bookings', function ($join): void {
                $join->on('bookings.id', '=', 'booking_services.booking_id')
                    ->on('bookings.organization_id', '=', 'booking_services.organization_id');
            })
            ->where('booking_services.organization_id', $organizationId)
            ->where('booking_services.service_id', $serviceId)
            ->whereIn('bookings.status', BookingStatus::capacityReservingValues());

        if ($lockReservations) {
            $query->lockForUpdate();
        }

        $events = [];
        foreach ($query->get() as $reservation) {
            $this->addInterval(
                $events,
                $reservation->start_at_utc,
                $reservation->end_at_utc,
                $reservation->quantity,
                'existing',
            );
        }

        ksort($events, SORT_STRING);
        $reserved = 0;
        $peak = 0;
        foreach ($events as $event) {
            $reserved -= $event['existing_end'];
            $reserved += $event['existing_start'];
            $peak = max($peak, $reserved);
        }

        return $peak;
    }

    /**
     * @param  list<BookingServiceCandidate>  $candidates
     * @return array{available: bool, services: list<array{service_id: int, available: bool, total_units: int, requested_quantity: int, required_quantity: int, over_capacity_by: int}>}
     */
    public function check(
        int $organizationId,
        array $candidates,
        ?int $excludeBookingId = null,
        bool $lockReservations = false,
    ): array {
        $serviceIds = array_values(array_unique(array_map(
            fn (BookingServiceCandidate $candidate): int => (int) $candidate->service->id,
            $candidates,
        )));
        sort($serviceIds, SORT_NUMERIC);

        $minimumStart = min(array_map(
            fn (BookingServiceCandidate $candidate): DateTimeInterface => $candidate->startAtUtc,
            $candidates,
        ));
        $maximumEnd = max(array_map(
            fn (BookingServiceCandidate $candidate): DateTimeInterface => $candidate->endAtUtc,
            $candidates,
        ));

        $query = BookingService::query()
            ->select('booking_services.*')
            ->join('bookings', function ($join): void {
                $join->on('bookings.id', '=', 'booking_services.booking_id')
                    ->on('bookings.organization_id', '=', 'booking_services.organization_id');
            })
            ->where('booking_services.organization_id', $organizationId)
            ->whereIn('booking_services.service_id', $serviceIds)
            ->whereIn('bookings.status', BookingStatus::capacityReservingValues())
            ->where('booking_services.start_at_utc', '<', $maximumEnd)
            ->where('booking_services.end_at_utc', '>', $minimumStart)
            ->when($excludeBookingId !== null, fn (Builder $query) => $query->where('booking_services.booking_id', '!=', $excludeBookingId));

        if ($lockReservations) {
            // This is a current/locking read so a waiter sees reservations committed
            // by the previous holder of the Service-row mutex under MySQL REPEATABLE READ.
            $query->lockForUpdate();
        }

        $existing = $query->get()->groupBy('service_id');
        $reports = [];

        foreach ($serviceIds as $serviceId) {
            $serviceCandidates = array_values(array_filter(
                $candidates,
                fn (BookingServiceCandidate $candidate): bool => $candidate->service->id === $serviceId,
            ));
            $events = [];

            foreach ($existing->get($serviceId, collect()) as $reservation) {
                $this->addInterval(
                    $events,
                    $reservation->start_at_utc,
                    $reservation->end_at_utc,
                    $reservation->quantity,
                    'existing',
                );
            }

            foreach ($serviceCandidates as $candidate) {
                $this->addInterval(
                    $events,
                    $candidate->startAtUtc,
                    $candidate->endAtUtc,
                    $candidate->quantity,
                    'candidate',
                );
            }

            ksort($events, SORT_STRING);
            $existingQuantity = 0;
            $candidateQuantity = 0;
            $peakRequested = 0;
            $peakRequired = 0;

            foreach ($events as $event) {
                // Ends are applied before starts at a shared boundary, preserving [start, end).
                $existingQuantity -= $event['existing_end'];
                $candidateQuantity -= $event['candidate_end'];
                $existingQuantity += $event['existing_start'];
                $candidateQuantity += $event['candidate_start'];

                if ($candidateQuantity > 0) {
                    $peakRequested = max($peakRequested, $candidateQuantity);
                    $peakRequired = max($peakRequired, $existingQuantity + $candidateQuantity);
                }
            }

            $totalUnits = (int) $serviceCandidates[0]->service->total_units;
            $reports[] = [
                'service_id' => $serviceId,
                'available' => $peakRequired <= $totalUnits,
                'total_units' => $totalUnits,
                'requested_quantity' => $peakRequested,
                'required_quantity' => $peakRequired,
                'over_capacity_by' => max(0, $peakRequired - $totalUnits),
            ];
        }

        return [
            'available' => ! in_array(false, array_column($reports, 'available'), true),
            'services' => $reports,
        ];
    }

    /** @param list<BookingServiceCandidate> $candidates */
    public function ensureAvailable(
        int $organizationId,
        array $candidates,
        ?int $excludeBookingId = null,
        bool $lockReservations = false,
    ): void {
        $result = $this->check($organizationId, $candidates, $excludeBookingId, $lockReservations);

        if (! $result['available']) {
            throw ValidationException::withMessages([
                'booking_services' => 'The requested schedule exceeds available service capacity.',
            ]);
        }
    }

    /**
     * @param  array<string, array{existing_start: int, existing_end: int, candidate_start: int, candidate_end: int}>  $events
     */
    private function addInterval(
        array &$events,
        DateTimeInterface $start,
        DateTimeInterface $end,
        int $quantity,
        string $kind,
    ): void {
        $startKey = $start->format('U.u');
        $endKey = $end->format('U.u');
        $empty = ['existing_start' => 0, 'existing_end' => 0, 'candidate_start' => 0, 'candidate_end' => 0];
        $events[$startKey] ??= $empty;
        $events[$endKey] ??= $empty;
        $events[$startKey]["{$kind}_start"] += $quantity;
        $events[$endKey]["{$kind}_end"] += $quantity;
    }
}
