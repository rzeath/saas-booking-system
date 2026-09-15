<?php

namespace App\Actions\Billings;

use App\Enums\PaymentStatus;
use App\Models\Billing;
use App\Models\Booking;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\User;
use App\Support\Billings\PaymentMutationResult;
use App\Support\Billings\PaymentSummaryCalculator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VoidPayment
{
    public function __construct(
        private readonly PaymentSummaryCalculator $summaryCalculator,
    ) {}

    public function handle(User $user, int $paymentId, string $reason): PaymentMutationResult
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'void_reason' => 'A void reason is required.',
            ]);
        }

        return DB::transaction(function () use ($user, $paymentId, $reason): PaymentMutationResult {
            $organization = Organization::query()->findOrFail($user->organization_id);
            $reference = Payment::query()
                ->where('organization_id', $organization->id)
                ->whereKey($paymentId)
                ->firstOrFail(['booking_id', 'quotation_id', 'billing_id']);
            $booking = Booking::query()
                ->where('organization_id', $organization->id)
                ->whereKey($reference->booking_id)
                ->lockForUpdate()
                ->firstOrFail();
            $quotation = Quotation::query()
                ->where('organization_id', $organization->id)
                ->where('booking_id', $booking->id)
                ->whereKey($reference->quotation_id)
                ->lockForUpdate()
                ->firstOrFail();
            $billing = Billing::query()
                ->where('organization_id', $organization->id)
                ->where('booking_id', $booking->id)
                ->where('quotation_id', $quotation->id)
                ->whereKey($reference->billing_id)
                ->lockForUpdate()
                ->firstOrFail();
            $payments = Payment::query()
                ->where('organization_id', $organization->id)
                ->where('booking_id', $booking->id)
                ->where('quotation_id', $quotation->id)
                ->where('billing_id', $billing->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $payment = $payments->firstWhere('id', $paymentId);

            if ($payment === null) {
                throw (new ModelNotFoundException)->setModel(Payment::class, [$paymentId]);
            }

            if ($payment->status !== PaymentStatus::Posted) {
                throw ValidationException::withMessages([
                    'payment' => 'Only a Posted Payment may be voided.',
                ]);
            }

            $payment->update([
                'status' => PaymentStatus::Voided,
                'voided_at' => now('UTC'),
                'voided_by' => $user->id,
                'void_reason' => $reason,
            ]);

            return new PaymentMutationResult(
                payment: $payment->refresh()->load(['billing', 'quotation', 'booking', 'creator', 'voider']),
                billing: $billing,
                summary: $this->summaryCalculator->calculate($billing, $payments),
            );
        }, 5);
    }
}
