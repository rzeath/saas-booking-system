<?php

namespace App\Actions\Quotations;

use App\Enums\BookingStatus;
use App\Enums\QuotationStatus;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Support\Quotations\QuotationExpiry;
use App\Support\Quotations\QuotationMoneyCalculator;
use App\Support\Quotations\QuotationTransitionLocker;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SendQuotation
{
    public function __construct(
        private readonly QuotationTransitionLocker $locker,
        private readonly QuotationExpiry $expiry,
        private readonly QuotationMoneyCalculator $money,
    ) {}

    public function handle(User $user, int $quotationId): Quotation
    {
        return DB::transaction(function () use ($user, $quotationId): Quotation {
            $organization = Organization::query()->findOrFail($user->organization_id);
            [$quotation, $booking] = $this->locker->lock($organization, $quotationId);

            if ($quotation->status !== QuotationStatus::Draft) {
                throw ValidationException::withMessages([
                    'status' => 'Only Draft quotations may be sent.',
                ]);
            }

            if ($booking->status !== BookingStatus::Pending) {
                throw ValidationException::withMessages([
                    'booking_status' => 'The Booking must be pending before its quotation can be sent.',
                ]);
            }

            if ($quotation->valid_until === null) {
                throw ValidationException::withMessages([
                    'valid_until' => 'A valid-until date is required before sending.',
                ]);
            }

            if ($this->expiry->isExpired($quotation->valid_until)) {
                throw ValidationException::withMessages([
                    'valid_until' => 'The valid-until date cannot be earlier than today in Asia/Manila.',
                ]);
            }

            $hasItems = QuotationItem::query()
                ->where('quotation_id', $quotation->id)
                ->lockForUpdate()
                ->limit(1)
                ->get(['id'])
                ->isNotEmpty();

            if (! $hasItems) {
                throw ValidationException::withMessages([
                    'items' => 'A quotation must contain at least one item before sending.',
                ]);
            }

            $this->money->ensurePositive($quotation->total);
            $sentAt = now('UTC');
            $quotation->update([
                'status' => QuotationStatus::Sent,
                'sent_at' => $sentAt,
            ]);
            $booking->update(['status' => BookingStatus::Quoted]);

            return $quotation->refresh()->load(['booking', 'items']);
        }, 3);
    }
}
