<?php

declare(strict_types=1);

use App\Actions\Quotations\CreateQuotation;
use App\Enums\BookingStatus;
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
$organization = Organization::create(['name' => "Quotation Concurrency Verification {$runId}"]);
$organization->businessSetting()->create(BusinessSetting::defaults($organization->name));
$user = $organization->user()->create([
    'name' => 'Quotation Concurrency Admin',
    'email' => "quotation-concurrency-{$runId}@example.test",
    'password' => 'Password1',
]);
$customer = $organization->customers()->create(['name' => 'Quotation Concurrency Customer']);
$eventType = $organization->eventTypes()->create(['name' => 'Quotation Concurrency Event']);
$service = $organization->services()->create([
    'name' => 'Quotation Concurrency Service',
    'total_units' => 1,
]);
$package = $organization->packages()->create(['name' => 'Quotation Concurrency Package']);
$package->services()->attach($service->id, ['organization_id' => $organization->id]);
$booking = $organization->bookings()->create([
    'booking_number' => "CONCURRENCY-{$runId}",
    'customer_id' => $customer->id,
    'event_type_id' => $eventType->id,
    'customer_name' => $customer->name,
    'event_type_name' => $eventType->name,
    'event_name' => 'Concurrent Quotation Event',
    'event_date' => '2027-06-15',
    'venue_name' => 'Concurrency Hall',
    'contact_person' => 'Concurrency Contact',
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
$statement = $blocker->prepare('SELECT id FROM bookings WHERE id = ? FOR UPDATE');
$statement->execute([$booking->id]);

$resultFiles = [];
$children = [];
for ($attempt = 1; $attempt <= 2; $attempt++) {
    $resultFile = sys_get_temp_dir()."/takdaops-quotation-concurrency-{$attempt}-{$runId}.json";
    $resultFiles[] = $resultFile;
    $pid = pcntl_fork();

    if ($pid === -1) {
        throw new RuntimeException('Unable to fork the quotation concurrency worker.');
    }

    if ($pid === 0) {
        DB::purge('mysql');

        try {
            $childUser = User::findOrFail($user->id);
            $quotation = app(CreateQuotation::class)->handle($childUser, $booking->id);
            file_put_contents($resultFile, json_encode([
                'result' => 'created',
                'quotation_id' => $quotation->id,
                'quotation_number' => $quotation->quotation_number,
            ], JSON_THROW_ON_ERROR));
            exit(0);
        } catch (ValidationException $exception) {
            file_put_contents($resultFile, json_encode([
                'result' => 'rejected',
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
$quotation = DB::table('quotations')->where('booking_id', $booking->id)->first();
$quotationCount = DB::table('quotations')->where('booking_id', $booking->id)->count();
$itemCount = $quotation === null
    ? 0
    : DB::table('quotation_items')->where('quotation_id', $quotation->id)->count();
$sequence = DB::table('document_sequences')
    ->where('organization_id', $organization->id)
    ->where('document_type', 'QUOTATION')
    ->first();
$expectedYear = now()->setTimezone(config('app.timezone'))->format('Y');

$passed = $childExitCodes === [0, 0]
    && $resultKinds === ['created', 'rejected']
    && $quotationCount === 1
    && $itemCount === 1
    && $quotation !== null
    && $quotation->quotation_number === "QT-{$expectedYear}-000001"
    && $sequence !== null
    && (int) $sequence->next_number === 2;

echo json_encode([
    'passed' => $passed,
    'worker_results' => $results,
    'quotation_count' => $quotationCount,
    'quotation_item_count' => $itemCount,
    'quotation_number' => $quotation?->quotation_number,
    'sequence_next_number' => $sequence?->next_number,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;

exit($passed ? 0 : 1);
