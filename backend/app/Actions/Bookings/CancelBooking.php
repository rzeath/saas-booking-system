<?php

namespace App\Actions\Bookings;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingService;
use App\Models\Organization;
use App\Models\User;
use App\Support\Bookings\ServiceRowLocker;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelBooking
{
    public function __construct(private readonly ServiceRowLocker $serviceLocker) {}

    public function handle(
        Organization $organization,
        User $user,
        int $bookingId,
        ?string $reason,
    ): Booking {
        return DB::transaction(function () use ($organization, $user, $bookingId, $reason): Booking {
            $booking = Booking::query()
                ->where('organization_id', $organization->id)
                ->whereKey($bookingId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($booking->status !== BookingStatus::Pending) {
                throw ValidationException::withMessages([
                    'status' => 'Only pending bookings may be cancelled in this phase.',
                ]);
            }

            $serviceIds = BookingService::query()
                ->where('organization_id', $organization->id)
                ->where('booking_id', $booking->id)
                ->pluck('service_id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $this->serviceLocker->lock($organization, $serviceIds);

            $booking->update([
                'status' => BookingStatus::Cancelled,
                'cancelled_at' => now('UTC'),
                'cancelled_by' => $user->id,
                'cancellation_reason' => $reason,
            ]);

            return $booking->refresh()->load(['customer', 'eventType', 'bookingServices.assignedStaff']);
        }, 3);
    }
}
