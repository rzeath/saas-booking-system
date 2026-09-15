<?php

declare(strict_types=1);

use App\Actions\Billings\RecordPayment;
use App\Actions\Billings\VoidPayment;
use App\Enums\BookingStatus;
use App\Enums\QuotationStatus;
use App\Models\BusinessSetting;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! function_exists('pcntl_fork')) {
    fwrite(STDERR, "pcntl is required for this MySQL concurrency verification.\n");
    exit(2);
}

if (DB::connection()->getDriverName() !== 'mysql') {
    fwrite(STDERR, "This concurrency verification must run against MySQL.\n");
    exit(2);
}

$runId = getmypid();
$organization = Organization::create(['name' => "Billing Payment Concurrency {$runId}"]);
$organization->businessSetting()->create([
    ...BusinessSetting::defaults($organization->name),
    'billing_prefix' => 'INV',
]);
$user = $organization->user()->create([
    'name' => 'Billing Concurrency Admin',
    'email' => "billing-payment-concurrency-{$runId}@example.test",
    'password' => 'Password1',
]);
$customer = $organization->customers()->create(['name' => 'Billing Concurrency Customer']);
$eventType = $organization->eventTypes()->create(['name' => 'Billing Concurrency Event']);
$bookingCounter = 0;

$createAcceptedQuotation = function () use (
    $organization,
    $user,
    $customer,
    $eventType,
    $runId,
    &$bookingCounter,
) {
    $bookingCounter++;
    $booking = $organization->bookings()->create([
        'booking_number' => "PAYMENT-RACE-{$runId}-{$bookingCounter}",
        'customer_id' => $customer->id,
        'event_type_id' => $eventType->id,
        'customer_name' => $customer->name,
        'event_type_name' => $eventType->name,
        'event_name' => "Payment Race {$bookingCounter}",
        'event_date' => '2027-06-15',
        'venue_name' => 'Concurrency Hall',
        'contact_person' => 'Concurrency Contact',
        'contact_number' => '09170000000',
        'status' => BookingStatus::Quoted,
        'created_by' => $user->id,
    ]);
    $quotation = $organization->quotations()->create([
        'booking_id' => $booking->id,
        'quotation_number' => "QT-RACE-{$runId}-{$bookingCounter}",
        'status' => QuotationStatus::Accepted,
        'valid_until' => '2027-06-01',
        'sent_at' => now('UTC')->subDay(),
        'accepted_at' => now('UTC'),
        'closed_at' => now('UTC'),
        'business_display_name' => $organization->name,
        'customer_name' => $customer->name,
        'event_type_name' => $eventType->name,
        'event_name' => "Payment Race {$bookingCounter}",
        'event_date' => '2027-06-15',
        'venue_name' => 'Concurrency Hall',
        'contact_person' => 'Concurrency Contact',
        'contact_number' => '09170000000',
        'subtotal' => '8000.00',
        'transportation_fee' => '0.00',
        'crew_meal_fee' => '0.00',
        'discount_amount' => '0.00',
        'total' => '8000.00',
        'created_by' => $user->id,
    ]);
    $quotation->items()->createMany([
        [
            'booking_id' => $booking->id,
            'service_name' => 'Mirror Booth',
            'package_name' => 'Classic',
            'start_at' => '2027-06-15 18:00:00',
            'end_at' => '2027-06-15 20:00:00',
            'duration_minutes' => 120,
            'quantity' => 1,
            'unit_rate' => '5000.00',
            'line_total' => '5000.00',
            'sort_order' => 0,
        ],
        [
            'booking_id' => $booking->id,
            'service_name' => '360 Booth',
            'package_name' => 'Essential',
            'start_at' => '2027-06-15 21:00:00',
            'end_at' => '2027-06-16 00:00:00',
            'duration_minutes' => 180,
            'quantity' => 1,
            'unit_rate' => '3000.00',
            'line_total' => '3000.00',
            'sort_order' => 1,
        ],
    ]);

    return [$booking, $quotation];
};

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    getenv('DB_HOST'),
    getenv('DB_PORT'),
    getenv('DB_DATABASE'),
);
$paidAt = now(config('app.timezone'))->subMinute()->format('Y-m-d H:i:s');
$raceNumber = 0;

$runRace = function (int $bookingId, array $operations) use (
    $dsn,
    $user,
    $paidAt,
    $runId,
    &$raceNumber,
): array {
    $raceNumber++;
    $blocker = new PDO($dsn, getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $blocker->beginTransaction();
    $statement = $blocker->prepare('SELECT id FROM bookings WHERE id = ? FOR UPDATE');
    $statement->execute([$bookingId]);

    $resultFiles = [];
    $children = [];
    foreach ($operations as $attempt => $operation) {
        $resultFile = sys_get_temp_dir()."/takdaops-billing-payment-{$runId}-{$raceNumber}-{$attempt}.json";
        $resultFiles[] = $resultFile;
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Unable to fork a Billing Payment worker.');
        }

        if ($pid === 0) {
            DB::purge('mysql');

            try {
                $childUser = User::findOrFail($user->id);

                if ($operation['type'] === 'record') {
                    $result = app(RecordPayment::class)->handle(
                        $childUser,
                        $operation['quotation_id'],
                        [
                            'amount' => $operation['amount'],
                            'paid_at' => $paidAt,
                            'payment_method' => 'CASH',
                        ],
                    );
                } else {
                    $result = app(VoidPayment::class)->handle(
                        $childUser,
                        $operation['payment_id'],
                        $operation['reason'],
                    );
                }

                file_put_contents($resultFile, json_encode([
                    'result' => $operation['type'],
                    'payment_id' => $result->payment->id,
                    'billing_id' => $result->billing->id,
                    'amount_paid' => $result->summary->amountPaid,
                    'remaining_balance' => $result->summary->remainingBalance,
                ], JSON_THROW_ON_ERROR));
                exit(0);
            } catch (ValidationException $exception) {
                file_put_contents($resultFile, json_encode([
                    'result' => 'conflict',
                    'errors' => $exception->errors(),
                ], JSON_THROW_ON_ERROR));
                exit(0);
            } catch (Throwable $exception) {
                file_put_contents($resultFile, json_encode([
                    'result' => 'error',
                    'class' => $exception::class,
                    'message' => $exception->getMessage(),
                ], JSON_THROW_ON_ERROR));
                exit(1);
            }
        }

        $children[] = $pid;
    }

    usleep(500_000);
    $blocker->commit();

    $exitCodes = [];
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        $exitCodes[] = pcntl_wexitstatus($status);
    }

    DB::purge('mysql');
    $results = array_map(
        fn (string $file): array => json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR),
        $resultFiles,
    );
    foreach ($resultFiles as $file) {
        unlink($file);
    }

    return ['exit_codes' => $exitCodes, 'workers' => $results];
};

$kinds = function (array $race): array {
    $values = array_column($race['workers'], 'result');
    sort($values);

    return $values;
};
$financialState = function (int $quotationId): array {
    $billing = DB::table('billings')->where('quotation_id', $quotationId)->first();
    $payments = DB::table('payments')->where('quotation_id', $quotationId)->orderBy('id')->get();
    $postedCents = $payments
        ->where('status', 'POSTED')
        ->sum(fn (object $payment): int => (int) str_replace('.', '', $payment->amount));

    return [
        'billing' => $billing,
        'payments' => $payments,
        'posted' => sprintf('%d.%02d', intdiv($postedCents, 100), $postedCents % 100),
    ];
};

[$firstBooking, $firstQuotation] = $createAcceptedQuotation();
$firstRace = $runRace($firstBooking->id, [
    ['type' => 'record', 'quotation_id' => $firstQuotation->id, 'amount' => '3000.00'],
    ['type' => 'record', 'quotation_id' => $firstQuotation->id, 'amount' => '3000.00'],
]);
$firstState = $financialState($firstQuotation->id);

[$finalBooking, $finalQuotation] = $createAcceptedQuotation();
$initialFinalPayment = app(RecordPayment::class)->handle($user, $finalQuotation->id, [
    'amount' => '5000.00',
    'paid_at' => $paidAt,
    'payment_method' => 'CASH',
]);
$finalRace = $runRace($finalBooking->id, [
    ['type' => 'record', 'quotation_id' => $finalQuotation->id, 'amount' => '3000.00'],
    ['type' => 'record', 'quotation_id' => $finalQuotation->id, 'amount' => '3000.00'],
]);
$finalState = $financialState($finalQuotation->id);

[$paymentVoidBooking, $paymentVoidQuotation] = $createAcceptedQuotation();
$paymentToVoid = app(RecordPayment::class)->handle($user, $paymentVoidQuotation->id, [
    'amount' => '3000.00',
    'paid_at' => $paidAt,
    'payment_method' => 'CASH',
])->payment;
$paymentVoidRace = $runRace($paymentVoidBooking->id, [
    ['type' => 'record', 'quotation_id' => $paymentVoidQuotation->id, 'amount' => '5000.00'],
    ['type' => 'void', 'payment_id' => $paymentToVoid->id, 'reason' => 'Concurrent correction.'],
]);
$paymentVoidState = $financialState($paymentVoidQuotation->id);

[$voidBooking, $voidQuotation] = $createAcceptedQuotation();
$voidTarget = app(RecordPayment::class)->handle($user, $voidQuotation->id, [
    'amount' => '1000.00',
    'paid_at' => $paidAt,
    'payment_method' => 'CASH',
])->payment;
$voidRace = $runRace($voidBooking->id, [
    ['type' => 'void', 'payment_id' => $voidTarget->id, 'reason' => 'First correction.'],
    ['type' => 'void', 'payment_id' => $voidTarget->id, 'reason' => 'Second correction.'],
]);
$voidState = $financialState($voidQuotation->id);

$sequence = DB::table('document_sequences')
    ->where('organization_id', $organization->id)
    ->where('document_type', 'BILLING')
    ->first();
$billingNumbers = DB::table('billings')
    ->where('organization_id', $organization->id)
    ->pluck('billing_number');
$allExitCodes = array_merge(
    $firstRace['exit_codes'],
    $finalRace['exit_codes'],
    $paymentVoidRace['exit_codes'],
    $voidRace['exit_codes'],
);
$passed = $allExitCodes === array_fill(0, 8, 0)
    && $kinds($firstRace) === ['record', 'record']
    && DB::table('billings')->where('quotation_id', $firstQuotation->id)->count() === 1
    && DB::table('billing_items')->where('quotation_id', $firstQuotation->id)->count() === 2
    && $firstState['payments']->count() === 2
    && $firstState['posted'] === '6000.00'
    && DB::table('bookings')->where('id', $firstBooking->id)->value('status') === 'CONFIRMED'
    && $initialFinalPayment->summary->amountPaid === '5000.00'
    && $kinds($finalRace) === ['conflict', 'record']
    && $finalState['payments']->count() === 2
    && $finalState['posted'] === '8000.00'
    && $kinds($paymentVoidRace) === ['record', 'void']
    && $paymentVoidState['payments']->count() === 2
    && $paymentVoidState['posted'] === '5000.00'
    && DB::table('bookings')->where('id', $paymentVoidBooking->id)->value('status') === 'CONFIRMED'
    && $kinds($voidRace) === ['conflict', 'void']
    && $voidState['payments']->count() === 1
    && $voidState['posted'] === '0.00'
    && DB::table('bookings')->where('id', $voidBooking->id)->value('status') === 'CONFIRMED'
    && $billingNumbers->count() === 4
    && $billingNumbers->unique()->count() === 4
    && $sequence !== null
    && (int) $sequence->next_number === 5;

echo json_encode([
    'passed' => $passed,
    'first_payment_race' => $firstRace,
    'final_payment_race' => $finalRace,
    'payment_void_race' => $paymentVoidRace,
    'void_replay_race' => $voidRace,
    'final_financial_states' => [
        'first_payment' => $firstState['posted'],
        'final_payment' => $finalState['posted'],
        'payment_void' => $paymentVoidState['posted'],
        'void_replay' => $voidState['posted'],
    ],
    'billing_numbers' => $billingNumbers->all(),
    'sequence_next_number' => $sequence?->next_number,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;

exit($passed ? 0 : 1);
