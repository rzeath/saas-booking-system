<?php

namespace App\Actions\Quotations;

use App\Enums\BookingStatus;
use App\Enums\QuotationStatus;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\User;
use App\Support\Quotations\QuotationTransitionLocker;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelQuotation
{
    public function __construct(private readonly QuotationTransitionLocker $locker) {}

    public function handle(User $user, int $quotationId): Quotation
    {
        return DB::transaction(function () use ($user, $quotationId): Quotation {
            $organization = Organization::query()->findOrFail($user->organization_id);
            [$quotation, $booking] = $this->locker->lock($organization, $quotationId);

            if (! in_array($quotation->status, [QuotationStatus::Draft, QuotationStatus::Sent], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Only Draft or Sent quotations may be cancelled.',
                ]);
            }

            $expectedBookingStatus = $quotation->status === QuotationStatus::Draft
                ? BookingStatus::Pending
                : BookingStatus::Quoted;

            if ($booking->status !== $expectedBookingStatus) {
                throw ValidationException::withMessages([
                    'booking_status' => 'The Booking status is inconsistent with this quotation.',
                ]);
            }

            $wasSent = $quotation->status === QuotationStatus::Sent;
            $quotation->update([
                'status' => QuotationStatus::Cancelled,
                'closed_at' => now('UTC'),
            ]);

            if ($wasSent) {
                $booking->update(['status' => BookingStatus::Pending]);
            }

            return $quotation->refresh()->load(['booking', 'items']);
        }, 3);
    }
}
