<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Billing;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Payment> */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'billing_id' => Billing::factory(),
            'organization_id' => fn (array $attributes): int => $this->billing($attributes)->organization_id,
            'booking_id' => fn (array $attributes): int => $this->billing($attributes)->booking_id,
            'quotation_id' => fn (array $attributes): int => $this->billing($attributes)->quotation_id,
            'amount' => '1000.00',
            'paid_at' => '2027-05-03 14:30:00',
            'payment_method' => PaymentMethod::Cash,
            'reference_number' => null,
            'internal_note' => null,
            'status' => PaymentStatus::Posted,
            'created_by' => fn (array $attributes): int => $this->billing($attributes)->created_by,
            'voided_at' => null,
            'voided_by' => null,
            'void_reason' => null,
        ];
    }

    public function forBilling(Billing $billing): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $billing->organization_id,
            'booking_id' => $billing->booking_id,
            'quotation_id' => $billing->quotation_id,
            'billing_id' => $billing->id,
            'created_by' => $billing->created_by,
        ]);
    }

    public function voided(?User $voider = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PaymentStatus::Voided,
            'voided_at' => '2027-05-04 09:00:00',
            'voided_by' => $voider?->id ?? $this->billing($attributes)->created_by,
            'void_reason' => 'Duplicate payment entry.',
        ]);
    }

    private function billing(array $attributes): Billing
    {
        return Billing::query()->findOrFail($attributes['billing_id']);
    }
}
