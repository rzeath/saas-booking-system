<?php

declare(strict_types=1);

use App\Actions\Quotations\AcceptQuotation;
use App\Actions\Quotations\CancelQuotation;
use App\Actions\Quotations\CreateQuotation;
use App\Actions\Quotations\ExpireQuotations;
use App\Actions\Quotations\RejectQuotation;
use App\Actions\Quotations\SendQuotation;
use App\Enums\BookingStatus;
use App\Models\BusinessSetting;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
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
$organization = Organization::create(['name' => "Quotation Lifecycle Concurrency {$runId}"]);
$organization->businessSetting()->create(BusinessSetting::defaults($organization->name));
$user = $organization->user()->create([
    'name' => 'Quotation Lifecycle Admin',
    'email' => "quotation-lifecycle-{$runId}@example.test",
    'password' => 'Password1',
]);
$customer = $organization->customers()->create(['name' => 'Quotation Lifecycle Customer']);
$eventType = $organization->eventTypes()->create(['name' => 'Quotation Lifecycle Event']);
$service = $organization->services()->create([
    'name' => 'Quotation Lifecycle Service',
    'total_units' => 1,
]);
$package = $organization->packages()->create(['name' => 'Quotation Lifecycle Package']);
$package->services()->attach($service->id, ['organization_id' => $organization->id]);
$bookingCounter = 0;

$createBooking = function () use (
    $organization,
    $user,
    $customer,
    $eventType,
    $service,
    $package,
    $runId,
    &$bookingCounter,
) {
    $bookingCounter++;
    $booking = $organization->bookings()->create([
        'booking_number' => "LIFECYCLE-{$runId}-{$bookingCounter}",
        'customer_id' => $customer->id,
        'event_type_id' => $eventType->id,
        'customer_name' => $customer->name,
        'event_type_name' => $eventType->name,
        'event_name' => "Lifecycle Race {$bookingCounter}",
        'event_date' => '2027-06-15',
        'venue_name' => 'Lifecycle Hall',
        'contact_person' => 'Lifecycle Contact',
        'contact_number' => '09170000000',
        'status' => BookingStatus::Pending,
        'created_by' => $user->id,
    ]);
    $booking->bookingServices()->create([
        'organization_id' => $organization->id,
        'service_id' => $service->id,
        'package_id' => $package->id,
        'start_at' => '2027-06-15 18:00:00',
        'end_at' => '2027-06-15 21:00:00',
        'duration_minutes' => 180,
        'quantity' => 1,
        'service_name' => $service->name,
        'package_name' => $package->name,
        'unit_rate' => '7500.00',
        'line_total' => '7500.00',
        'sort_order' => 0,
    ]);

    return $booking;
};

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    getenv('DB_HOST'),
    getenv('DB_PORT'),
    getenv('DB_DATABASE'),
);
$raceNumber = 0;

$runRace = function (int $bookingId, int $quotationId, array $operations) use (
    $dsn,
    $organization,
    $user,
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
        $resultFile = sys_get_temp_dir()."/takdaops-quotation-lifecycle-{$runId}-{$raceNumber}-{$attempt}.json";
        $resultFiles[] = $resultFile;
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Unable to fork a quotation lifecycle worker.');
        }

        if ($pid === 0) {
            DB::purge('mysql');

            try {
                $childUser = User::findOrFail($user->id);
                $childOrganization = Organization::findOrFail($organization->id);

                match ($operation) {
                    'send' => app(SendQuotation::class)->handle($childUser, $quotationId),
                    'accept' => app(AcceptQuotation::class)->handle($childUser, $quotationId),
                    'reject' => app(RejectQuotation::class)->handle($childUser, $quotationId),
                    'cancel' => app(CancelQuotation::class)->handle($childUser, $quotationId),
                    'expire' => app(ExpireQuotations::class)->handle($childOrganization),
                };
                file_put_contents($resultFile, json_encode([
                    'result' => $operation,
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

$today = CarbonImmutable::now(config('app.timezone'));
$validUntil = $today->addDay()->toDateString();

$sendBooking = $createBooking();
$sendQuotation = app(CreateQuotation::class)->handle($user, $sendBooking->id, [
    'valid_until' => $validUntil,
]);
$sendRace = $runRace($sendBooking->id, $sendQuotation->id, ['send', 'send']);

$acceptRejectBooking = $createBooking();
$acceptRejectQuotation = app(CreateQuotation::class)->handle($user, $acceptRejectBooking->id, [
    'valid_until' => $validUntil,
]);
app(SendQuotation::class)->handle($user, $acceptRejectQuotation->id);
$acceptRejectRace = $runRace(
    $acceptRejectBooking->id,
    $acceptRejectQuotation->id,
    ['accept', 'reject'],
);

$acceptCancelBooking = $createBooking();
$acceptCancelQuotation = app(CreateQuotation::class)->handle($user, $acceptCancelBooking->id, [
    'valid_until' => $validUntil,
]);
app(SendQuotation::class)->handle($user, $acceptCancelQuotation->id);
$acceptCancelRace = $runRace(
    $acceptCancelBooking->id,
    $acceptCancelQuotation->id,
    ['accept', 'cancel'],
);

$expireBooking = $createBooking();
$expireQuotation = app(CreateQuotation::class)->handle($user, $expireBooking->id, [
    'valid_until' => $validUntil,
]);
app(SendQuotation::class)->handle($user, $expireQuotation->id);
DB::table('quotations')->where('id', $expireQuotation->id)->update([
    'valid_until' => $today->subDay()->toDateString(),
]);
$expireAcceptRace = $runRace($expireBooking->id, $expireQuotation->id, ['expire', 'accept']);

$raceKinds = function (array $race): array {
    $kinds = array_column($race['workers'], 'result');
    sort($kinds);

    return $kinds;
};
$final = fn (int $quotationId, int $bookingId): array => [
    'quotation' => (array) DB::table('quotations')->where('id', $quotationId)->first(),
    'booking' => (array) DB::table('bookings')->where('id', $bookingId)->first(),
];
$sendFinal = $final($sendQuotation->id, $sendBooking->id);
$acceptRejectFinal = $final($acceptRejectQuotation->id, $acceptRejectBooking->id);
$acceptCancelFinal = $final($acceptCancelQuotation->id, $acceptCancelBooking->id);
$expireFinal = $final($expireQuotation->id, $expireBooking->id);
$sequence = DB::table('document_sequences')
    ->where('organization_id', $organization->id)
    ->where('document_type', 'QUOTATION')
    ->first();
$numbers = DB::table('quotations')
    ->where('organization_id', $organization->id)
    ->pluck('quotation_number');

$acceptRejectConsistent = ($acceptRejectFinal['quotation']['status'] === 'ACCEPTED'
        && $acceptRejectFinal['booking']['status'] === 'QUOTED')
    || ($acceptRejectFinal['quotation']['status'] === 'REJECTED'
        && $acceptRejectFinal['booking']['status'] === 'PENDING');
$acceptCancelConsistent = ($acceptCancelFinal['quotation']['status'] === 'ACCEPTED'
        && $acceptCancelFinal['booking']['status'] === 'QUOTED')
    || ($acceptCancelFinal['quotation']['status'] === 'CANCELLED'
        && $acceptCancelFinal['booking']['status'] === 'PENDING');
$allExitCodes = array_merge(
    $sendRace['exit_codes'],
    $acceptRejectRace['exit_codes'],
    $acceptCancelRace['exit_codes'],
    $expireAcceptRace['exit_codes'],
);

$passed = $allExitCodes === array_fill(0, 8, 0)
    && $raceKinds($sendRace) === ['conflict', 'send']
    && in_array($raceKinds($acceptRejectRace), [['accept', 'conflict'], ['conflict', 'reject']], true)
    && in_array($raceKinds($acceptCancelRace), [['accept', 'conflict'], ['cancel', 'conflict']], true)
    && $raceKinds($expireAcceptRace) === ['conflict', 'expire']
    && $sendFinal['quotation']['status'] === 'SENT'
    && $sendFinal['booking']['status'] === 'QUOTED'
    && $acceptRejectConsistent
    && $acceptCancelConsistent
    && $expireFinal['quotation']['status'] === 'EXPIRED'
    && $expireFinal['booking']['status'] === 'PENDING'
    && $numbers->count() === 4
    && $numbers->unique()->count() === 4
    && $sequence !== null
    && (int) $sequence->next_number === 5;

echo json_encode([
    'passed' => $passed,
    'send_race' => $sendRace,
    'accept_reject_race' => $acceptRejectRace,
    'accept_cancel_race' => $acceptCancelRace,
    'expire_accept_race' => $expireAcceptRace,
    'final_statuses' => [
        'send' => $sendFinal,
        'accept_reject' => $acceptRejectFinal,
        'accept_cancel' => $acceptCancelFinal,
        'expire_accept' => $expireFinal,
    ],
    'quotation_numbers' => $numbers->all(),
    'sequence_next_number' => $sequence?->next_number,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;

exit($passed ? 0 : 1);
