<?php

namespace App\Support\Quotations;

use App\Models\Booking;
use App\Models\Organization;
use App\Models\Quotation;

class QuotationTransitionLocker
{
    /** @return array{Quotation, Booking} */
    public function lock(Organization $organization, int $quotationId): array
    {
        $reference = Quotation::query()
            ->where('organization_id', $organization->id)
            ->whereKey($quotationId)
            ->firstOrFail(['booking_id']);
        $booking = Booking::query()
            ->where('organization_id', $organization->id)
            ->whereKey($reference->booking_id)
            ->lockForUpdate()
            ->firstOrFail();
        $quotation = Quotation::query()
            ->where('organization_id', $organization->id)
            ->whereKey($quotationId)
            ->lockForUpdate()
            ->firstOrFail();

        return [$quotation, $booking];
    }
}
