<?php

namespace App\Support\Billings;

use App\Enums\PaymentMethod;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PaymentInput
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{amount: mixed, paid_at: DateTimeImmutable, payment_method: PaymentMethod, reference_number: string|null, internal_note: string|null}
     */
    public function validate(array $data): array
    {
        $validated = Validator::make($data, [
            'amount' => ['required'],
            'paid_at' => ['required', 'string', 'date_format:Y-m-d H:i:s'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'internal_note' => ['sometimes', 'nullable', 'string'],
        ])->validated();

        $method = $validated['payment_method'] instanceof PaymentMethod
            ? $validated['payment_method']
            : PaymentMethod::from((string) $validated['payment_method']);
        $reference = $this->nullableTrimmed($validated['reference_number'] ?? null);

        if ($method !== PaymentMethod::Cash && $reference === null) {
            throw ValidationException::withMessages([
                'reference_number' => "A reference number is required for {$this->methodLabel($method)} payments.",
            ]);
        }

        $paidAt = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $validated['paid_at'],
            new DateTimeZone((string) config('app.timezone')),
        );

        if ($paidAt === false) {
            throw ValidationException::withMessages([
                'paid_at' => 'The paid at date and time is invalid.',
            ]);
        }

        return [
            'amount' => $validated['amount'],
            'paid_at' => $paidAt,
            'payment_method' => $method,
            'reference_number' => $reference,
            'internal_note' => $this->nullableTrimmed($validated['internal_note'] ?? null),
        ];
    }

    private function nullableTrimmed(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function methodLabel(PaymentMethod $method): string
    {
        return match ($method) {
            PaymentMethod::GCash => 'GCash',
            PaymentMethod::BankTransfer => 'Bank Transfer',
            PaymentMethod::Check => 'Check',
            PaymentMethod::Cash => 'Cash',
        };
    }
}
