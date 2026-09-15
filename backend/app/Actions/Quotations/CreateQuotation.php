<?php

namespace App\Actions\Quotations;

use App\Enums\BookingStatus;
use App\Enums\DocumentType;
use App\Enums\QuotationStatus;
use App\Models\Booking;
use App\Models\BookingService;
use App\Models\BusinessSetting;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\User;
use App\Support\DocumentNumbers\DocumentNumberAllocator;
use App\Support\Quotations\DraftQuotationInput;
use App\Support\Quotations\QuotationMoneyCalculator;
use App\Support\Quotations\QuotationSnapshotBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateQuotation
{
    public function __construct(
        private readonly DocumentNumberAllocator $numberAllocator,
        private readonly DraftQuotationInput $draftInput,
        private readonly QuotationSnapshotBuilder $snapshots,
        private readonly QuotationMoneyCalculator $money,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(User $user, int $bookingId, array $data = []): Quotation
    {
        $input = $this->draftInput->validate($data);

        try {
            return DB::transaction(function () use ($user, $bookingId, $input): Quotation {
                $organization = Organization::query()->findOrFail($user->organization_id);
                $booking = Booking::query()
                    ->where('organization_id', $organization->id)
                    ->whereKey($bookingId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->ensureEligible($booking);

                $bookingServices = BookingService::query()
                    ->where('organization_id', $organization->id)
                    ->where('booking_id', $booking->id)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($bookingServices->isEmpty()) {
                    throw ValidationException::withMessages([
                        'booking_services' => 'A Booking must contain at least one service before it can be quoted.',
                    ]);
                }

                $settings = BusinessSetting::query()
                    ->where('organization_id', $organization->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $itemSnapshots = $this->snapshots->items($bookingServices);
                $totals = $this->money->calculate(
                    array_column($itemSnapshots, 'line_total'),
                    $input['transportation_fee'] ?? '0.00',
                    $input['crew_meal_fee'] ?? '0.00',
                    $input['discount_amount'] ?? '0.00',
                );
                $createdAt = now('UTC');

                $quotation = $organization->quotations()->create([
                    'booking_id' => $booking->id,
                    'quotation_number' => $this->numberAllocator->allocate(
                        $organization,
                        DocumentType::Quotation,
                        $createdAt,
                    ),
                    'status' => QuotationStatus::Draft,
                    'valid_until' => $input['valid_until'] ?? null,
                    ...$this->snapshots->header($booking, $settings),
                    ...$totals,
                    'created_by' => $user->id,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);

                $quotation->items()->createMany($itemSnapshots);

                return $quotation->load('items');
            }, 3);
        } catch (QueryException $exception) {
            if ($this->isQuotationSlotConflict($exception)) {
                throw ValidationException::withMessages([
                    'quotation' => 'This Booking already has an active or accepted quotation.',
                ]);
            }

            throw $exception;
        }
    }

    private function ensureEligible(Booking $booking): void
    {
        if ($booking->status !== BookingStatus::Pending) {
            throw ValidationException::withMessages([
                'status' => 'Only pending bookings may have a Draft quotation created.',
            ]);
        }

        $statuses = Quotation::query()
            ->where('organization_id', $booking->organization_id)
            ->where('booking_id', $booking->id)
            ->whereIn('status', [
                QuotationStatus::Draft->value,
                QuotationStatus::Sent->value,
                QuotationStatus::Accepted->value,
            ])
            ->lockForUpdate()
            ->pluck('status');

        if ($statuses->contains(QuotationStatus::Accepted)) {
            throw ValidationException::withMessages([
                'quotation' => 'This Booking already has an accepted quotation.',
            ]);
        }

        if ($statuses->isNotEmpty()) {
            throw ValidationException::withMessages([
                'quotation' => 'This Booking already has an active quotation.',
            ]);
        }
    }

    private function isQuotationSlotConflict(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'quotations_booking_active_unique')
            || str_contains($message, 'quotations_booking_accepted_unique')
            || str_contains($message, 'quotations.booking_id, quotations.active_slot')
            || str_contains($message, 'quotations.booking_id, quotations.accepted_slot');
    }
}
