<?php

namespace App\Support\Documents;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Billing;
use App\Models\BillingItem;
use App\Models\Payment;
use App\Support\Billings\PaymentSummaryCalculator;
use DateTimeInterface;

class BillingPdfPresenter
{
    public function __construct(
        private readonly PesoFormatter $pesoFormatter,
        private readonly PaymentSummaryCalculator $paymentSummaryCalculator,
        private readonly ManagedLogoDataUriResolver $logoResolver,
    ) {}

    /** @return array<string, mixed> */
    public function present(Billing $billing): array
    {
        $summary = $this->paymentSummaryCalculator->forBilling($billing);
        $postedPayments = $billing->payments
            ->filter(fn (Payment $payment): bool => $payment->status === PaymentStatus::Posted)
            ->map(fn (Payment $payment): array => [
                'paidDate' => $this->formatDateTime($payment->paid_at),
                'method' => $this->paymentMethodLabel($payment->payment_method),
                'reference' => $payment->reference_number,
                'amount' => $this->pesoFormatter->format($payment->amount),
            ])
            ->values()
            ->all();

        return [
            'billing' => $billing,
            'logoDataUri' => $this->logoResolver->resolve(
                $billing->business_logo_path,
                $billing->organization_id,
            ),
            'createdDate' => $this->formatDate($billing->created_at),
            'paymentStatusLabel' => $this->paymentStatusLabel($summary->paymentStatus),
            'items' => $billing->items->map(fn (BillingItem $item): array => [
                'serviceName' => $item->service_name,
                'packageName' => $item->package_name,
                'schedule' => $this->formatSchedule($item),
                'duration' => $this->formatDuration($item->duration_minutes),
                'quantity' => $item->quantity,
                'unitRate' => $this->pesoFormatter->format($item->unit_rate),
                'lineTotal' => $this->pesoFormatter->format($item->line_total),
            ])->all(),
            'money' => [
                'subtotal' => $this->pesoFormatter->format($billing->subtotal),
                'transportationFee' => $this->pesoFormatter->format($billing->transportation_fee),
                'crewMealFee' => $this->pesoFormatter->format($billing->crew_meal_fee),
                'discountAmount' => $this->pesoFormatter->format($billing->discount_amount),
                'total' => $this->pesoFormatter->format($billing->total),
                'amountPaid' => $this->pesoFormatter->format($summary->amountPaid),
                'remainingBalance' => $this->pesoFormatter->format($summary->remainingBalance),
            ],
            'postedPayments' => $postedPayments,
        ];
    }

    private function formatDate(?DateTimeInterface $date): string
    {
        return $date?->format('F j, Y') ?? 'Not specified';
    }

    private function formatDateTime(DateTimeInterface $date): string
    {
        return $date->format('M j, Y · g:i A');
    }

    private function formatSchedule(BillingItem $item): string
    {
        $start = $item->start_at;
        $end = $item->end_at;

        if ($start->isSameDay($end)) {
            return $start->format('M j, Y · g:i A').' - '.$end->format('g:i A');
        }

        return $start->format('M j, Y · g:i A').' - '.$end->format('M j, Y · g:i A');
    }

    private function formatDuration(int $minutes): string
    {
        if ($minutes % 60 === 0) {
            $hours = intdiv($minutes, 60);

            return $hours.' '.($hours === 1 ? 'hour' : 'hours');
        }

        return $minutes.' minutes';
    }

    private function paymentMethodLabel(PaymentMethod $method): string
    {
        return match ($method) {
            PaymentMethod::Cash => 'Cash',
            PaymentMethod::GCash => 'GCash',
            PaymentMethod::BankTransfer => 'Bank Transfer',
            PaymentMethod::Check => 'Check',
        };
    }

    private function paymentStatusLabel(string $status): string
    {
        return match ($status) {
            'UNPAID' => 'Unpaid',
            'PARTIALLY_PAID' => 'Partially Paid',
            'PAID' => 'Paid',
        };
    }
}
