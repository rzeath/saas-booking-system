<?php

namespace Database\Factories;

use App\Models\Billing;
use App\Models\BillingItem;
use App\Models\QuotationItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use InvalidArgumentException;

/** @extends Factory<BillingItem> */
class BillingItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'billing_id' => Billing::factory(),
            'organization_id' => fn (array $attributes): int => $this->billing($attributes)->organization_id,
            'booking_id' => fn (array $attributes): int => $this->billing($attributes)->booking_id,
            'quotation_id' => fn (array $attributes): int => $this->billing($attributes)->quotation_id,
            'quotation_item_id' => null,
            'service_name' => '360 Video Booth',
            'package_name' => 'Premium',
            'start_at' => '2027-06-15 18:00:00',
            'end_at' => '2027-06-15 21:00:00',
            'duration_minutes' => 180,
            'quantity' => 1,
            'unit_rate' => '7500.00',
            'line_total' => '7500.00',
            'sort_order' => 0,
        ];
    }

    public function forBilling(Billing $billing): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $billing->organization_id,
            'booking_id' => $billing->booking_id,
            'quotation_id' => $billing->quotation_id,
            'billing_id' => $billing->id,
        ]);
    }

    public function fromQuotationItem(Billing $billing, QuotationItem $quotationItem): static
    {
        if ($billing->quotation_id !== $quotationItem->quotation_id) {
            throw new InvalidArgumentException('The source Quotation Item must belong to the billed Quotation.');
        }

        return $this->forBilling($billing)->state(fn (): array => [
            'quotation_item_id' => $quotationItem->id,
            'service_name' => $quotationItem->service_name,
            'package_name' => $quotationItem->package_name,
            'start_at' => $quotationItem->start_at,
            'end_at' => $quotationItem->end_at,
            'duration_minutes' => $quotationItem->duration_minutes,
            'quantity' => $quotationItem->quantity,
            'unit_rate' => $quotationItem->unit_rate,
            'line_total' => $quotationItem->line_total,
            'sort_order' => $quotationItem->sort_order,
        ]);
    }

    private function billing(array $attributes): Billing
    {
        return Billing::query()->findOrFail($attributes['billing_id']);
    }
}
