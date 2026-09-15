<?php

namespace App\Actions\Bookings;

use App\Actions\Quotations\CancelQuotation;
use App\Enums\BookingStatus;
use App\Enums\QuotationStatus;
use App\Models\Booking;
use App\Models\BookingService;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\User;
use App\Support\Bookings\ServiceRowLocker;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelBooking
{
    public function __construct(
        private readonly ServiceRowLocker $serviceLocker,
        private readonly CancelQuotation $cancelQuotation,
    ) {}

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

            $quotations = Quotation::query()
                ->where('organization_id', $organization->id)
                ->where('booking_id', $booking->id)
                ->whereIn('status', [
                    QuotationStatus::Draft,
                    QuotationStatus::Sent,
                    QuotationStatus::Accepted,
                ])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $activeQuotation = $quotations->first(fn (Quotation $quotation): bool => in_array(
                $quotation->status,
                [QuotationStatus::Draft, QuotationStatus::Sent],
                true,
            ));
            $acceptedQuotation = $quotations->firstWhere('status', QuotationStatus::Accepted);
            $isConsistentPending = $booking->status === BookingStatus::Pending
                && $acceptedQuotation === null
                && ($activeQuotation === null || $activeQuotation->status === QuotationStatus::Draft);
            $isConsistentQuoted = $booking->status === BookingStatus::Quoted
                && (($activeQuotation?->status === QuotationStatus::Sent) xor ($acceptedQuotation !== null));

            if (! $isConsistentPending && ! $isConsistentQuoted) {
                throw ValidationException::withMessages([
                    'status' => 'Only pending or consistently quoted bookings may be cancelled.',
                ]);
            }

            if ($activeQuotation !== null) {
                $this->cancelQuotation->handleLocked($activeQuotation, $booking);
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
