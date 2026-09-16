<?php

namespace App\Support\Calendar;

use App\Models\Booking;
use App\Support\Bookings\BookingScheduleQuery;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CalendarBookingQuery
{
    public function __construct(private readonly BookingScheduleQuery $scheduleQuery) {}

    /**
     * @param  list<string>  $statuses
     * @return Collection<int, Booking>
     */
    public function get(
        int $organizationId,
        DateTimeInterface $rangeStart,
        DateTimeInterface $rangeEnd,
        array $statuses,
        ?int $serviceId = null,
        ?int $staffId = null,
        bool $unassigned = false,
    ): Collection {
        $query = Booking::query()
            ->select([
                'bookings.id',
                'bookings.booking_number',
                'bookings.status',
                'bookings.start_at',
                'bookings.customer_name',
                'bookings.event_name',
                'bookings.event_type_name',
                'bookings.venue_name',
            ])
            ->where('bookings.organization_id', $organizationId)
            ->whereIn('bookings.status', $statuses)
            ->where('bookings.start_at', '<', $rangeEnd->format('Y-m-d H:i:s'))
            ->whereHas('bookingServices', function (Builder $serviceQuery) use (
                $organizationId,
                $rangeStart,
            ): void {
                $serviceQuery->where('booking_services.organization_id', $organizationId);
                $this->scheduleQuery->whereEndsAfter($serviceQuery, $rangeStart);
            });

        if ($serviceId !== null || $staffId !== null || $unassigned) {
            $query->whereHas('bookingServices', function (Builder $serviceQuery) use (
                $organizationId,
                $serviceId,
                $staffId,
                $unassigned,
            ): void {
                $serviceQuery
                    ->where('booking_services.organization_id', $organizationId)
                    ->when(
                        $serviceId !== null,
                        fn (Builder $query) => $query->where('booking_services.service_id', $serviceId),
                    )
                    ->when(
                        $staffId !== null,
                        fn (Builder $query) => $query->whereHas(
                            'assignedStaff',
                            fn (Builder $staffQuery) => $staffQuery
                                ->where('staff.organization_id', $organizationId)
                                ->where('booking_service_staff_assignments.organization_id', $organizationId)
                                ->whereKey($staffId),
                        ),
                    )
                    ->when(
                        $unassigned,
                        fn (Builder $query) => $query->whereDoesntHave(
                            'assignedStaff',
                            fn (Builder $staffQuery) => $staffQuery->where(
                                'booking_service_staff_assignments.organization_id',
                                $organizationId,
                            ),
                        ),
                    );
            });
        }

        return $query
            ->with([
                'bookingServices' => fn (HasMany $serviceQuery) => $serviceQuery->select([
                    'booking_services.id',
                    'booking_services.organization_id',
                    'booking_services.booking_id',
                    'booking_services.service_id',
                    'booking_services.service_name',
                    'booking_services.package_name',
                    'booking_services.duration_minutes',
                    'booking_services.quantity',
                    'booking_services.sort_order',
                ]),
                'bookingServices.assignedStaff' => fn (BelongsToMany $staffQuery) => $staffQuery
                    ->select(['staff.id', 'staff.name'])
                    ->wherePivot('organization_id', $organizationId)
                    ->orderBy('staff.name')
                    ->orderBy('staff.id'),
            ])
            ->orderBy('bookings.start_at')
            ->orderBy('bookings.id')
            ->get();
    }
}
