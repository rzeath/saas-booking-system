<?php

declare(strict_types=1);

use App\Actions\Bookings\CreateBooking;
use App\Actions\Services\UpdateService;
use App\Models\BusinessSetting;
use App\Models\Organization;
use App\Models\Package;
use App\Models\ServiceRate;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
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

$organization = Organization::create(['name' => 'Phase 6A Concurrency Verification']);
$organization->businessSetting()->create(BusinessSetting::defaults($organization->name));
$user = $organization->user()->create([
    'name' => 'Concurrency Admin',
    'email' => 'phase6a-concurrency@example.test',
    'password' => 'Password1',
]);
$customer = $organization->customers()->create(['name' => 'Concurrency Customer']);
$eventType = $organization->eventTypes()->create(['name' => 'Concurrency Event']);
$service = $organization->services()->create([
    'name' => 'Concurrency Booth',
    'total_units' => 3,
]);
$package = new Package(['name' => 'Concurrency Package']);
$package->organization()->associate($organization);
$package->service()->associate($service);
$package->save();
$rate = new ServiceRate([
    'duration_minutes' => 180,
    'unit_rate' => '7500.00',
]);
$rate->organization()->associate($organization);
$rate->eventType()->associate($eventType);
$rate->package()->associate($package);
$rate->save();

$payload = [
    'customer_id' => $customer->id,
    'event_type_id' => $eventType->id,
    'event_name' => 'Concurrent Booking',
    'event_date' => '2027-06-15',
    'venue_name' => 'Concurrency Hall',
    'contact_person' => 'Concurrency Contact',
    'contact_number' => '09170000000',
    'booking_services' => [[
        'service_id' => $service->id,
        'package_id' => $package->id,
        'start_time' => '18:00',
        'duration_minutes' => 180,
        'quantity' => 2,
    ]],
];

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    getenv('DB_HOST'),
    getenv('DB_PORT'),
    getenv('DB_DATABASE'),
);
$blocker = new PDO($dsn, getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$blocker->beginTransaction();
$statement = $blocker->prepare('SELECT id FROM services WHERE id = ? FOR UPDATE');
$statement->execute([$service->id]);

$resultFiles = [];
$children = [];
for ($attempt = 1; $attempt <= 2; $attempt++) {
    $resultFile = sys_get_temp_dir()."/takdaops-phase6a-concurrency-{$attempt}-".getmypid().'.json';
    $resultFiles[] = $resultFile;
    $pid = pcntl_fork();

    if ($pid === -1) {
        throw new RuntimeException('Unable to fork the concurrency worker.');
    }

    if ($pid === 0) {
        DB::purge('mysql');

        try {
            $childOrganization = Organization::findOrFail($organization->id);
            $childUser = User::findOrFail($user->id);
            $booking = app(CreateBooking::class)->handle($childOrganization, $childUser, $payload);
            file_put_contents($resultFile, json_encode([
                'result' => 'created',
                'booking_id' => $booking->id,
                'booking_number' => $booking->booking_number,
            ], JSON_THROW_ON_ERROR));
            exit(0);
        } catch (ValidationException $exception) {
            file_put_contents($resultFile, json_encode([
                'result' => 'unavailable',
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

$childExitCodes = [];
foreach ($children as $pid) {
    pcntl_waitpid($pid, $status);
    $childExitCodes[] = pcntl_wexitstatus($status);
}

DB::purge('mysql');
$results = array_map(
    fn (string $file): array => json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR),
    $resultFiles,
);
foreach ($resultFiles as $file) {
    unlink($file);
}

$resultKinds = array_column($results, 'result');
sort($resultKinds);
$bookingCount = DB::table('bookings')->where('organization_id', $organization->id)->count();
$lineCount = DB::table('booking_services')->where('organization_id', $organization->id)->count();
$reservedQuantity = (int) DB::table('booking_services')
    ->where('organization_id', $organization->id)
    ->sum('quantity');
$sequence = DB::table('document_sequences')
    ->where('organization_id', $organization->id)
    ->where('document_type', 'BOOKING')
    ->first();

$rejects = function (callable $operation): bool {
    try {
        $operation();
    } catch (QueryException) {
        return true;
    }

    return false;
};

$storedLine = (array) DB::table('booking_services')->where('organization_id', $organization->id)->first();
unset($storedLine['id']);
$invalidInterval = $storedLine;
$invalidInterval['end_at'] = $invalidInterval['start_at'];
$invalidTotal = $storedLine;
$invalidTotal['start_at'] = '2027-06-16 18:00:00';
$invalidTotal['end_at'] = '2027-06-16 21:00:00';
$invalidTotal['line_total'] = '0.01';
$storedBooking = (array) DB::table('bookings')->where('organization_id', $organization->id)->first();
unset($storedBooking['id']);
$storedBooking['booking_number'] = 'INVALID-STATUS';
$storedBooking['status'] = 'INVALID';
$otherOrganization = Organization::create(['name' => 'Foreign Constraint Verification']);
$tenantMismatch = $storedLine;
$tenantMismatch['organization_id'] = $otherOrganization->id;

$constraintChecks = [
    'interval_order' => $rejects(fn () => DB::table('booking_services')->insert($invalidInterval)),
    'exact_line_total' => $rejects(fn () => DB::table('booking_services')->insert($invalidTotal)),
    'booking_status' => $rejects(fn () => DB::table('bookings')->insert($storedBooking)),
    'tenant_relationship' => $rejects(fn () => DB::table('booking_services')->insert($tenantMismatch)),
];
$capacityReductionRejected = false;
try {
    app(UpdateService::class)->handle($organization, $service->id, [
        'name' => $service->name,
        'total_units' => 1,
        'is_active' => true,
    ]);
} catch (ValidationException) {
    $capacityReductionRejected = true;
}

$passed = $childExitCodes === [0, 0]
    && $resultKinds === ['created', 'unavailable']
    && $bookingCount === 1
    && $lineCount === 1
    && $reservedQuantity === 2
    && $sequence !== null
    && (int) $sequence->next_number === 2
    && ! in_array(false, $constraintChecks, true)
    && $capacityReductionRejected
    && (int) $service->fresh()->total_units === 3;

echo json_encode([
    'passed' => $passed,
    'worker_results' => $results,
    'booking_count' => $bookingCount,
    'booking_service_count' => $lineCount,
    'reserved_quantity' => $reservedQuantity,
    'sequence_next_number' => $sequence?->next_number,
    'constraint_checks' => $constraintChecks,
    'capacity_reduction_rejected' => $capacityReductionRejected,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;

exit($passed ? 0 : 1);
