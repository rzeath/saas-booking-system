<?php

namespace App\Actions\Quotations;

use App\Enums\QuotationStatus;
use App\Models\Booking;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Support\Quotations\DraftQuotationInput;
use App\Support\Quotations\QuotationMoneyCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateDraftQuotation
{
    public function __construct(
        private readonly DraftQuotationInput $draftInput,
        private readonly QuotationMoneyCalculator $money,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(User $user, int $quotationId, array $data): Quotation
    {
        $input = $this->draftInput->validate($data);

        return DB::transaction(function () use ($user, $quotationId, $input): Quotation {
            $organization = Organization::query()->findOrFail($user->organization_id);
            $quotationReference = Quotation::query()
                ->where('organization_id', $organization->id)
                ->whereKey($quotationId)
                ->firstOrFail(['booking_id']);

            Booking::query()
                ->where('organization_id', $organization->id)
                ->whereKey($quotationReference->booking_id)
                ->lockForUpdate()
                ->firstOrFail();
            $quotation = Quotation::query()
                ->where('organization_id', $organization->id)
                ->whereKey($quotationId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($quotation->status !== QuotationStatus::Draft) {
                throw ValidationException::withMessages([
                    'status' => 'Only Draft quotations may be edited.',
                ]);
            }

            $lineTotals = QuotationItem::query()
                ->where('quotation_id', $quotation->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('line_total');
            $totals = $this->money->calculate(
                $lineTotals,
                $input['transportation_fee'] ?? $quotation->transportation_fee,
                $input['crew_meal_fee'] ?? $quotation->crew_meal_fee,
                $input['discount_amount'] ?? $quotation->discount_amount,
            );
            $attributes = $totals;

            if (array_key_exists('valid_until', $input)) {
                $attributes['valid_until'] = $input['valid_until'];
            }

            $quotation->update($attributes);

            return $quotation->refresh()->load('items');
        }, 3);
    }
}
