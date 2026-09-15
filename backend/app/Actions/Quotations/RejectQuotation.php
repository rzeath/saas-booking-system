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

class RejectQuotation
{
    public function __construct(private readonly QuotationTransitionLocker $locker) {}

    public function handle(User $user, int $quotationId): Quotation
    {
        return DB::transaction(function () use ($user, $quotationId): Quotation {
            $organization = Organization::query()->findOrFail($user->organization_id);
            [$quotation, $booking] = $this->locker->lock($organization, $quotationId);

            if ($quotation->status !== QuotationStatus::Sent) {
                throw ValidationException::withMessages([
                    'status' => 'Only Sent quotations may be rejected.',
                ]);
            }

            if ($booking->status !== BookingStatus::Quoted) {
                throw ValidationException::withMessages([
                    'booking_status' => 'The Booking must be quoted before rejecting its quotation.',
                ]);
            }

            $quotation->update([
                'status' => QuotationStatus::Rejected,
                'closed_at' => now('UTC'),
            ]);
            $booking->update(['status' => BookingStatus::Pending]);

            return $quotation->refresh()->load(['booking', 'items']);
        }, 3);
    }
}
