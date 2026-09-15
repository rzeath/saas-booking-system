<?php

declare(strict_types=1);

use App\Actions\Bookings\UpdateBooking;
use App\Actions\Quotations\AcceptQuotation;
use App\Actions\Quotations\CreateQuotation;
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
$organization = Organization::create(['name' => "Booking Quotation Concurrency {$runId}"]);
$organization->businessSetting()->create(BusinessSetting::defaults($organization->name));
$user = $organization->user()->create([
    'name' => 'Booking Quotation Admin',
    'email' => "booking-quotation-{$runId}@example.test",
    'password' => 'Password1',
]);
$customer = $organization->customers()->create(['name' => 'Booking Quotation Customer']);
$eventType = $organization->eventTypes()->create(['name' => 'Booking Quotation Event']);
$service = $organization->services()->create([
    'name' => 'Booking Quotation Service',
    'total_units' => 10,
]);
$package = $organization->packages()->create(['name' => 'Booking Quotation Package']);
$package->services()->attach($service->id, ['organization_id' => $organization->id]);
$organization->serviceRates()->create([
    'event_type_id' => $eventType->id,
    'service_id' => $service->id,
    'package_id' => $package->id,
    'duration_minutes' => 180,
    'unit_rate' => '7500.00',
    'is_active' => true,
]);
$bookingCounter = 0;

$createBooking = function (bool $withSecondLine = false) use (
    $organization,
    $user,
    $customer,
    $eventType,
    $service,
    $package,
    $runId,
    &$bookingCounter,
): array {
    $bookingCounter++;
    $booking = $organization->bookings()->create([
        'booking_number' => "BOOKING-QUOTE-RACE-{$runId}-{$bookingCounter}",
        'customer_id' => $customer->id,
        'event_type_id' => $eventType->id,
        'customer_name' => $customer->name,
        'event_type_name' => $eventType->name,
        'event_name' => "Original Race Event {$bookingCounter}",
        'event_date' => '2027-06-15',
        'venue_name' => 'Race Hall',
        'contact_person' => 'Race Contact',
        'contact_number' => '09170000000',
        'status' => BookingStatus::Pending,
        'created_by' => $user->id,
    ]);
    $lineCount = $withSecondLine ? 2 : 1;
    $payloadLines = [];

    for ($index = 0; $index < $lineCount; $index++) {
        $startHour = 18 + ($index * 2);
        $line = $booking->bookingServices()->create([
            'organization_id' => $organization->id,
            'service_id' => $service->id,
            'package_id' => $package->id,
            'start_at' => sprintf('2027-06-15 %02d:00:00', $startHour),
            'end_at' => sprintf('2027-06-15 %02d:00:00', $startHour + 3),
            'duration_minutes' => 180,
            'quantity' => 1,
            'service_name' => $service->name,
            'package_name' => $package->name,
            'unit_rate' => '7500.00',
            'line_total' => '7500.00',
            'sort_order' => $index,
        ]);
        $payloadLines[] = [
            'id' => $line->id,
            'service_id' => $service->id,
            'package_id' => $package->id,
            'start_time' => sprintf('%02d:00', $startHour),
            'duration_minutes' => 180,
            'quantity' => 1,
            'staff_ids' => [],
        ];
    }

    return [$booking, [
        'customer_id' => $customer->id,
        'event_type_id' => $eventType->id,
        'event_name' => "Updated Race Event {$bookingCounter}",
        'event_date' => '2027-06-15',
        'venue_name' => 'Race Hall',
        'venue_address' => null,
        'contact_person' => 'Race Contact',
        'contact_number' => '09170000000',
        'internal_notes' => null,
        'booking_services' => $payloadLines,
    ]];
};

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    getenv('DB_HOST'),
    getenv('DB_PORT'),
    getenv('DB_DATABASE'),
);
$raceNumber = 0;

$runRace = function (int $bookingId, int $quotationId, array $operations, array $payload) use (
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
        $resultFile = sys_get_temp_dir()."/takdaops-booking-quotation-{$runId}-{$raceNumber}-{$attempt}.json";
        $resultFiles[] = $resultFile;
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Unable to fork a Booking quotation worker.');
        }

        if ($pid === 0) {
            DB::purge('mysql');

            try {
                $childUser = User::findOrFail($user->id);
                $childOrganization = Organization::findOrFail($organization->id);

                match ($operation) {
                    'accept' => app(AcceptQuotation::class)->handle($childUser, $quotationId),
                    'send' => app(SendQuotation::class)->handle($childUser, $quotationId),
                    'edit' => app(UpdateBooking::class)->handle(
                        $childOrganization,
                        $childUser,
                        $bookingId,
                        $payload,
                    ),
                    'remove' => app(UpdateBooking::class)->handle(
                        $childOrganization,
                        $childUser,
                        $bookingId,
                        [...$payload, 'booking_services' => [$payload['booking_services'][0]]],
                    ),
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

$validUntil = CarbonImmutable::now(config('app.timezone'))->addDay()->toDateString();

[$sameBooking, $samePayload] = $createBooking();
$samePayload['event_name'] = $sameBooking->event_name;
$sameQuotation = app(CreateQuotation::class)->handle($user, $sameBooking->id, [
    'valid_until' => $validUntil,
]);
app(UpdateBooking::class)->handle($organization, $user, $sameBooking->id, $samePayload);

[$acceptBooking, $acceptPayload] = $createBooking();
$acceptQuotation = app(CreateQuotation::class)->handle($user, $acceptBooking->id, [
    'valid_until' => $validUntil,
]);
app(SendQuotation::class)->handle($user, $acceptQuotation->id);
$acceptRace = $runRace($acceptBooking->id, $acceptQuotation->id, ['edit', 'accept'], $acceptPayload);

[$sendBooking, $sendPayload] = $createBooking();
$sendQuotation = app(CreateQuotation::class)->handle($user, $sendBooking->id, [
    'valid_until' => $validUntil,
]);
$sendRace = $runRace($sendBooking->id, $sendQuotation->id, ['edit', 'send'], $sendPayload);

[$removeBooking, $removePayload] = $createBooking(true);
$removeQuotation = app(CreateQuotation::class)->handle($user, $removeBooking->id, [
    'valid_until' => $validUntil,
]);
$removedServiceId = $removePayload['booking_services'][1]['id'];
$removeRace = $runRace($removeBooking->id, $removeQuotation->id, ['remove', 'send'], $removePayload);

$raceKinds = function (array $race): array {
    $kinds = array_column($race['workers'], 'result');
    sort($kinds);

    return $kinds;
};
$final = fn (int $quotationId, int $bookingId): array => [
    'quotation' => (array) DB::table('quotations')->where('id', $quotationId)->first(),
    'booking' => (array) DB::table('bookings')->where('id', $bookingId)->first(),
];
$acceptFinal = $final($acceptQuotation->id, $acceptBooking->id);
$sendFinal = $final($sendQuotation->id, $sendBooking->id);
$removeFinal = $final($removeQuotation->id, $removeBooking->id);
$acceptOriginalWon = $acceptFinal['quotation']['status'] === 'ACCEPTED'
    && $acceptFinal['booking']['status'] === 'QUOTED'
    && $acceptFinal['booking']['event_name'] !== $acceptPayload['event_name'];
$acceptEditWon = $acceptFinal['quotation']['status'] === 'OUTDATED'
    && $acceptFinal['booking']['status'] === 'PENDING'
    && $acceptFinal['booking']['event_name'] === $acceptPayload['event_name'];
$removedItem = (array) DB::table('quotation_items')
    ->where('quotation_id', $removeQuotation->id)
    ->where('service_name', $service->name)
    ->whereNull('booking_service_id')
    ->first();
$allExitCodes = array_merge(
    $acceptRace['exit_codes'],
    $sendRace['exit_codes'],
    $removeRace['exit_codes'],
);

$passed = $allExitCodes === array_fill(0, 6, 0)
    && DB::table('quotations')->where('id', $sameQuotation->id)->value('status') === 'DRAFT'
    && $raceKinds($acceptRace) === ($acceptOriginalWon ? ['accept', 'conflict'] : ['conflict', 'edit'])
    && ($acceptOriginalWon || $acceptEditWon)
    && in_array($raceKinds($sendRace), [['conflict', 'edit'], ['edit', 'send']], true)
    && $sendFinal['quotation']['status'] === 'OUTDATED'
    && $sendFinal['booking']['status'] === 'PENDING'
    && $sendFinal['booking']['event_name'] === $sendPayload['event_name']
    && in_array($raceKinds($removeRace), [['conflict', 'remove'], ['remove', 'send']], true)
    && $removeFinal['quotation']['status'] === 'OUTDATED'
    && $removeFinal['booking']['status'] === 'PENDING'
    && ! DB::table('booking_services')->where('id', $removedServiceId)->exists()
    && DB::table('quotation_items')->where('quotation_id', $removeQuotation->id)->count() === 2
    && $removedItem !== []
    && $removedItem['unit_rate'] === '7500.00'
    && $removedItem['line_total'] === '7500.00';

echo json_encode([
    'passed' => $passed,
    'mysql_same_value_status' => DB::table('quotations')->where('id', $sameQuotation->id)->value('status'),
    'edit_accept_race' => $acceptRace,
    'edit_send_race' => $sendRace,
    'remove_send_race' => $removeRace,
    'final_statuses' => [
        'edit_accept' => $acceptFinal,
        'edit_send' => $sendFinal,
        'remove_send' => $removeFinal,
    ],
    'historical_item_retained' => $removedItem !== [],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;

exit($passed ? 0 : 1);
