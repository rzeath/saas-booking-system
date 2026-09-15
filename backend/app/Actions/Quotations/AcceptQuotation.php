<?php

namespace App\Actions\Quotations;

use App\Enums\BookingStatus;
use App\Enums\QuotationStatus;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\User;
use App\Support\Quotations\QuotationExpiry;
use App\Support\Quotations\QuotationTransitionLocker;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcceptQuotation
{
    public function __construct(
        private readonly QuotationTransitionLocker $locker,
        private readonly QuotationExpiry $expiry,
    ) {}

    public function handle(User $user, int $quotationId): Quotation
    {
        try {
            return DB::transaction(function () use ($user, $quotationId): Quotation {
                $organization = Organization::query()->findOrFail($user->organization_id);
                [$quotation, $booking] = $this->locker->lock($organization, $quotationId);

                if ($quotation->status !== QuotationStatus::Sent) {
                    throw ValidationException::withMessages([
                        'status' => 'Only Sent quotations may be accepted.',
                    ]);
                }

                if ($booking->status !== BookingStatus::Quoted) {
                    throw ValidationException::withMessages([
                        'booking_status' => 'The Booking must remain quoted before acceptance.',
                    ]);
                }

                if ($quotation->valid_until === null || $this->expiry->isExpired($quotation->valid_until)) {
                    throw ValidationException::withMessages([
                        'valid_until' => 'An expired quotation cannot be accepted.',
                    ]);
                }

                $acceptedAt = now('UTC');
                $quotation->update([
                    'status' => QuotationStatus::Accepted,
                    'accepted_at' => $acceptedAt,
                    'closed_at' => $acceptedAt,
                ]);

                return $quotation->refresh()->load(['booking', 'items']);
            }, 3);
        } catch (QueryException $exception) {
            if (str_contains($exception->getMessage(), 'quotations_booking_accepted_unique')
                || str_contains($exception->getMessage(), 'quotations.booking_id, quotations.accepted_slot')) {
                throw ValidationException::withMessages([
                    'quotation' => 'This Booking already has an accepted quotation.',
                ]);
            }

            throw $exception;
        }
    }
}
