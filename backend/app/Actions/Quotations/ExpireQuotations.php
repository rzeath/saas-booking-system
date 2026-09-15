<?php

namespace App\Actions\Quotations;

use App\Enums\BookingStatus;
use App\Enums\QuotationStatus;
use App\Models\Organization;
use App\Models\Quotation;
use App\Support\Quotations\QuotationExpiry;
use App\Support\Quotations\QuotationTransitionLocker;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpireQuotations
{
    public function __construct(
        private readonly QuotationTransitionLocker $locker,
        private readonly QuotationExpiry $expiry,
    ) {}

    public function handle(Organization $organization): int
    {
        $quotationIds = Quotation::query()
            ->where('organization_id', $organization->id)
            ->where('status', QuotationStatus::Sent)
            ->whereNotNull('valid_until')
            ->where('valid_until', '<', $this->expiry->today())
            ->orderBy('booking_id')
            ->orderBy('id')
            ->pluck('id');
        $expired = 0;

        foreach ($quotationIds as $quotationId) {
            $expired += DB::transaction(function () use ($organization, $quotationId): int {
                [$quotation, $booking] = $this->locker->lock($organization, (int) $quotationId);

                if ($quotation->status !== QuotationStatus::Sent
                    || ! $this->expiry->isExpired($quotation->valid_until)) {
                    return 0;
                }

                if ($booking->status !== BookingStatus::Quoted) {
                    throw ValidationException::withMessages([
                        'booking_status' => 'The Booking must be quoted before its quotation can expire.',
                    ]);
                }

                $quotation->update([
                    'status' => QuotationStatus::Expired,
                    'closed_at' => now('UTC'),
                ]);
                $booking->update(['status' => BookingStatus::Pending]);

                return 1;
            }, 3);
        }

        return $expired;
    }
}
