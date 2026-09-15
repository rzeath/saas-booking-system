<?php

namespace App\Actions\Bookings;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingService;
use App\Models\Organization;
use App\Models\User;
use App\Support\Bookings\StaffAssignmentSynchronizer;
use App\Support\Bookings\StaffAssignmentValidator;
use App\Support\Bookings\StaffAvailabilityChecker;
use App\Support\Bookings\StaffRowLocker;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateBookingServiceStaffAssignments
{
    public function __construct(
        private readonly StaffRowLocker $staffLocker,
        private readonly StaffAssignmentValidator $staffValidator,
        private readonly StaffAvailabilityChecker $availability,
        private readonly StaffAssignmentSynchronizer $synchronizer,
    ) {}

    /** @param list<int> $staffIds */
    public function handle(
        Organization $organization,
        User $user,
        int $bookingServiceId,
        array $staffIds,
    ): BookingService {
        return DB::transaction(function () use ($organization, $user, $bookingServiceId, $staffIds): BookingService {
            $identity = BookingService::query()
                ->where('organization_id', $organization->id)
                ->whereKey($bookingServiceId)
                ->firstOrFail(['id', 'booking_id']);
            $booking = Booking::query()
                ->where('organization_id', $organization->id)
                ->whereKey($identity->booking_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($booking->status !== BookingStatus::Pending) {
                throw ValidationException::withMessages([
                    'status' => 'Only pending bookings may be edited.',
                ]);
            }

            $bookingService = BookingService::query()
                ->with('assignedStaff')
                ->where('organization_id', $organization->id)
                ->where('booking_id', $booking->id)
                ->whereKey($bookingServiceId)
                ->lockForUpdate()
                ->firstOrFail();
            $retainedIds = $bookingService->assignedStaff
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $staff = $this->staffLocker->lock(
                $organization,
                [...$retainedIds, ...$staffIds],
            );
            $this->staffValidator->validateIds($staffIds, $staff, $retainedIds, 'staff_ids');
            $conflicts = $this->availability->conflictingStaffIds(
                $organization->id,
                $staffIds,
                $bookingService->start_at,
                $bookingService->end_at,
                $bookingService->id,
                lockReservations: true,
            );

            if ($conflicts !== []) {
                throw ValidationException::withMessages([
                    'staff_ids' => 'One or more selected staff are already assigned during this schedule.',
                ]);
            }

            $this->synchronizer->sync($bookingService, $staffIds, $user);

            return $bookingService->refresh()->load('assignedStaff');
        }, 3);
    }
}
