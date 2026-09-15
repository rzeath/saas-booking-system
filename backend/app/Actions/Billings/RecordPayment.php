<?php

namespace App\Actions\Billings;

use App\Enums\BookingStatus;
use App\Enums\DocumentType;
use App\Enums\PaymentStatus;
use App\Enums\QuotationStatus;
use App\Models\Billing;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Support\Billings\BillingSnapshotBuilder;
use App\Support\Billings\PaymentInput;
use App\Support\Billings\PaymentMutationResult;
use App\Support\Billings\PaymentSummaryCalculator;
use App\Support\DocumentNumbers\DocumentNumberAllocator;
use App\Support\Quotations\QuotationTransitionLocker;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordPayment
{
    public function __construct(
        private readonly QuotationTransitionLocker $locker,
        private readonly DocumentNumberAllocator $numberAllocator,
        private readonly BillingSnapshotBuilder $snapshots,
        private readonly PaymentSummaryCalculator $summaryCalculator,
        private readonly PaymentInput $paymentInput,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(User $user, int $quotationId, array $data): PaymentMutationResult
    {
        $input = $this->paymentInput->validate($data);

        try {
            return DB::transaction(function () use ($user, $quotationId, $input): PaymentMutationResult {
                $organization = Organization::query()->findOrFail($user->organization_id);
                [$quotation, $booking] = $this->locker->lock($organization, $quotationId);

                if ($quotation->status !== QuotationStatus::Accepted) {
                    throw ValidationException::withMessages([
                        'quotation' => 'Payments may only be recorded against an Accepted quotation.',
                    ]);
                }

                $totalCents = $this->summaryCalculator->toCents($quotation->total, 'quotation.total');

                if ($totalCents === 0) {
                    throw ValidationException::withMessages([
                        'quotation' => 'The Accepted quotation must have a positive total.',
                    ]);
                }

                if ($input['paid_at'] > now((string) config('app.timezone'))->toImmutable()) {
                    throw ValidationException::withMessages([
                        'paid_at' => 'The paid at date and time cannot be in the future in Asia/Manila.',
                    ]);
                }

                $billing = Billing::query()
                    ->where('organization_id', $organization->id)
                    ->where('booking_id', $booking->id)
                    ->where('quotation_id', $quotation->id)
                    ->lockForUpdate()
                    ->first();
                $isFirstPayment = $billing === null;
                $this->ensureBookingState($booking->status, $isFirstPayment);

                if ($billing === null) {
                    $billing = $this->createBilling($organization, $user, $quotation);
                }

                $payments = $this->lockPayments($billing);
                $summary = $this->summaryCalculator->calculate($billing, $payments);
                $amountCents = $this->summaryCalculator->toCents($input['amount']);

                if ($amountCents === 0) {
                    throw ValidationException::withMessages([
                        'amount' => 'The payment amount must be greater than zero.',
                    ]);
                }

                if ($amountCents > $summary->remainingCents) {
                    throw ValidationException::withMessages([
                        'amount' => $summary->remainingCents === 0
                            ? 'This Billing is already fully paid.'
                            : 'The payment amount cannot exceed the remaining balance.',
                    ]);
                }

                $payment = $billing->payments()->create([
                    'organization_id' => $organization->id,
                    'booking_id' => $booking->id,
                    'quotation_id' => $quotation->id,
                    'amount' => $this->summaryCalculator->format($amountCents),
                    'paid_at' => $input['paid_at'],
                    'payment_method' => $input['payment_method'],
                    'reference_number' => $input['reference_number'],
                    'internal_note' => $input['internal_note'],
                    'status' => PaymentStatus::Posted,
                    'created_by' => $user->id,
                ]);

                if ($isFirstPayment) {
                    $booking->update(['status' => BookingStatus::Confirmed]);
                }

                $payments->push($payment);

                return new PaymentMutationResult(
                    payment: $payment->load(['billing', 'quotation', 'booking', 'creator']),
                    billing: $billing->load('items'),
                    summary: $this->summaryCalculator->calculate($billing, $payments),
                );
            }, 5);
        } catch (QueryException $exception) {
            if ($this->isBillingConflict($exception)) {
                throw ValidationException::withMessages([
                    'billing' => 'The Billing could not be created because its number or Quotation is already in use.',
                ]);
            }

            throw $exception;
        }
    }

    private function createBilling(
        Organization $organization,
        User $user,
        Quotation $quotation,
    ): Billing {
        $quotationItems = QuotationItem::query()
            ->where('quotation_id', $quotation->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($quotationItems->isEmpty()) {
            throw ValidationException::withMessages([
                'quotation' => 'An Accepted quotation must contain at least one item before payment.',
            ]);
        }

        $createdAt = now('UTC');
        $billing = $organization->billings()->create([
            'booking_id' => $quotation->booking_id,
            'quotation_id' => $quotation->id,
            'billing_number' => $this->numberAllocator->allocate(
                $organization,
                DocumentType::Billing,
                $createdAt,
            ),
            ...$this->snapshots->header($quotation),
            'created_by' => $user->id,
        ]);

        $billing->items()->createMany(
            $this->snapshots->items($quotation, $quotationItems),
        );

        return $billing;
    }

    /** @return Collection<int, Payment> */
    private function lockPayments(Billing $billing): Collection
    {
        return Payment::query()
            ->where('organization_id', $billing->organization_id)
            ->where('booking_id', $billing->booking_id)
            ->where('quotation_id', $billing->quotation_id)
            ->where('billing_id', $billing->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function ensureBookingState(BookingStatus $status, bool $isFirstPayment): void
    {
        if ($status === BookingStatus::Cancelled) {
            throw ValidationException::withMessages([
                'booking' => 'Payments cannot be recorded for a cancelled Booking.',
            ]);
        }

        $valid = $isFirstPayment
            ? $status === BookingStatus::Quoted
            : in_array($status, [BookingStatus::Confirmed, BookingStatus::Completed], true);

        if (! $valid) {
            throw ValidationException::withMessages([
                'booking' => $isFirstPayment
                    ? 'The first Payment requires a quoted Booking with an Accepted quotation.'
                    : 'Additional Payments require a confirmed or completed Booking.',
            ]);
        }
    }

    private function isBillingConflict(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'billings_quotation_unique')
            || str_contains($message, 'billings_tenant_number_unique')
            || str_contains($message, 'billings.quotation_id')
            || str_contains($message, 'billings.organization_id, billings.billing_number');
    }
}
