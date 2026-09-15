<?php

namespace App\Support\Bookings;

use App\Enums\BookingStatus;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StaffAvailabilityChecker
{
    /**
     * @param  list<int>  $staffIds
     * @return list<int>
     */
    public function conflictingStaffIds(
        int $organizationId,
        array $staffIds,
        DateTimeInterface $startAt,
        DateTimeInterface $endAt,
        ?int $excludeBookingServiceId = null,
        bool $lockReservations = false,
    ): array {
        if ($staffIds === []) {
            return [];
        }

        $query = $this->reservationQuery($organizationId, $staffIds)
            ->where('booking_services.start_at', '<', $endAt)
            ->where('booking_services.end_at', '>', $startAt)
            ->when(
                $excludeBookingServiceId !== null,
                fn (Builder $query) => $query->where('booking_services.id', '!=', $excludeBookingServiceId),
            );

        if ($lockReservations) {
            $query->lockForUpdate();
        }

        return $query->distinct()
            ->pluck('assignments.staff_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /** @param list<BookingServiceCandidate> $candidates */
    public function ensureAvailable(
        int $organizationId,
        array $candidates,
        ?int $excludeBookingId = null,
        bool $lockReservations = false,
    ): void {
        $staffIds = array_values(array_unique(array_merge(...array_map(
            fn (BookingServiceCandidate $candidate): array => $candidate->staffIds,
            $candidates,
        ))));

        if ($staffIds === []) {
            return;
        }

        $minimumStart = min(array_map(
            fn (BookingServiceCandidate $candidate): DateTimeInterface => $candidate->startAt,
            $candidates,
        ));
        $maximumEnd = max(array_map(
            fn (BookingServiceCandidate $candidate): DateTimeInterface => $candidate->endAt,
            $candidates,
        ));

        $query = $this->reservationQuery($organizationId, $staffIds)
            ->addSelect([
                'booking_services.id as booking_service_id',
                'booking_services.start_at',
                'booking_services.end_at',
            ])
            ->where('booking_services.start_at', '<', $maximumEnd)
            ->where('booking_services.end_at', '>', $minimumStart)
            ->when(
                $excludeBookingId !== null,
                fn (Builder $query) => $query->where('booking_services.booking_id', '!=', $excludeBookingId),
            );

        if ($lockReservations) {
            $query->lockForUpdate();
        }

        $existing = $query->get()->groupBy('staff_id');
        $candidateIntervals = [];

        foreach ($candidates as $index => $candidate) {
            foreach ($candidate->staffIds as $staffId) {
                foreach ($existing->get($staffId, collect()) as $reservation) {
                    if ($this->overlaps(
                        $candidate->startAt,
                        $candidate->endAt,
                        (string) $reservation->start_at,
                        (string) $reservation->end_at,
                    )) {
                        $this->throwConflict($index);
                    }
                }

                foreach ($candidateIntervals[$staffId] ?? [] as [$startAt, $endAt]) {
                    if ($this->overlaps($candidate->startAt, $candidate->endAt, $startAt, $endAt)) {
                        $this->throwConflict($index);
                    }
                }

                $candidateIntervals[$staffId][] = [$candidate->startAt, $candidate->endAt];
            }
        }
    }

    /** @param list<int> $staffIds */
    private function reservationQuery(int $organizationId, array $staffIds): Builder
    {
        return DB::table('booking_service_staff_assignments as assignments')
            ->join('booking_services', function ($join): void {
                $join->on('booking_services.id', '=', 'assignments.booking_service_id')
                    ->on('booking_services.organization_id', '=', 'assignments.organization_id');
            })
            ->join('bookings', function ($join): void {
                $join->on('bookings.id', '=', 'booking_services.booking_id')
                    ->on('bookings.organization_id', '=', 'booking_services.organization_id');
            })
            ->select('assignments.staff_id')
            ->where('assignments.organization_id', $organizationId)
            ->whereIn('assignments.staff_id', $staffIds)
            ->whereIn('bookings.status', BookingStatus::capacityReservingValues());
    }

    private function overlaps(
        DateTimeInterface $candidateStart,
        DateTimeInterface $candidateEnd,
        DateTimeInterface|string $otherStart,
        DateTimeInterface|string $otherEnd,
    ): bool {
        $start = $candidateStart->format('Y-m-d H:i:s.u');
        $end = $candidateEnd->format('Y-m-d H:i:s.u');
        $otherStart = $otherStart instanceof DateTimeInterface
            ? $otherStart->format('Y-m-d H:i:s.u')
            : $otherStart;
        $otherEnd = $otherEnd instanceof DateTimeInterface
            ? $otherEnd->format('Y-m-d H:i:s.u')
            : $otherEnd;

        return $start < $otherEnd && $end > $otherStart;
    }

    private function throwConflict(int $index): never
    {
        throw ValidationException::withMessages([
            "booking_services.{$index}.staff_ids" => 'One or more selected staff are already assigned during this schedule.',
        ]);
    }
}
