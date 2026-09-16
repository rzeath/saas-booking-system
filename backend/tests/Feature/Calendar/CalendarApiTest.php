<?php

namespace Tests\Feature\Calendar;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingService;
use App\Models\Customer;
use App\Models\EventType;
use App\Models\Organization;
use App\Models\Package;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CalendarApiTest extends TestCase
{
    use RefreshDatabase;

    private int $bookingSequence = 0;

    public function test_calendar_requires_authentication_and_validates_its_bounded_range(): void
    {
        $this->getJson($this->calendarUrl())->assertUnauthorized();

        [$admin] = $this->admin();
        $this->actingAs($admin);

        $this->getJson('/api/v1/calendar')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start', 'end']);
        $this->getJson('/api/v1/calendar?start=wrong&end=also-wrong')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start', 'end']);
        $this->getJson('/api/v1/calendar?start=2027-06-01&end=2027-06-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end');
        $this->getJson('/api/v1/calendar?start=2027-06-01&end=2027-08-03')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end');
        $this->getJson('/api/v1/calendar?start=2027-06-01&end=2027-08-02')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson($this->calendarUrl(['statuses' => ['INVALID']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('statuses.0');
        $this->getJson($this->calendarUrl(['staff' => 'available']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('staff');
    }

    public function test_calendar_uses_half_open_effective_booking_intervals_for_all_range_shapes(): void
    {
        [$admin, $organization] = $this->admin();
        $this->actingAs($admin);

        $normal = $this->booking($organization, $admin, '2027-06-10 10:00:00');
        $this->serviceLine($normal, 60);
        $overnight = $this->booking($organization, $admin, '2027-06-09 23:00:00');
        $this->serviceLine($overnight, 120);
        $multiDay = $this->booking($organization, $admin, '2027-06-09 12:00:00');
        $this->serviceLine($multiDay, 3000);
        $startsBefore = $this->booking($organization, $admin, '2027-06-09 23:30:00');
        $this->serviceLine($startsBefore, 90);
        $endsAfter = $this->booking($organization, $admin, '2027-06-10 23:00:00');
        $this->serviceLine($endsAfter, 180);
        $spansRange = $this->booking($organization, $admin, '2027-06-09 00:00:00');
        $this->serviceLine($spansRange, 4320);
        $endsAtStart = $this->booking($organization, $admin, '2027-06-09 23:00:00');
        $this->serviceLine($endsAtStart, 60);
        $startsAtEnd = $this->booking($organization, $admin, '2027-06-11 00:00:00');
        $this->serviceLine($startsAtEnd, 60);

        $response = $this->getJson($this->calendarUrl([
            'start' => '2027-06-10',
            'end' => '2027-06-11',
        ]))->assertOk()->assertJsonCount(6, 'data');

        $ids = $response->json('data.*.id');
        $this->assertSame([
            $spansRange->id,
            $multiDay->id,
            $overnight->id,
            $startsBefore->id,
            $normal->id,
            $endsAfter->id,
        ], $ids);
        $this->assertNotContains($endsAtStart->id, $ids);
        $this->assertNotContains($startsAtEnd->id, $ids);
    }

    public function test_longest_service_sets_booking_end_and_payload_uses_derived_service_schedules(): void
    {
        [$admin, $organization] = $this->admin();
        $booking = $this->booking($organization, $admin, '2027-06-15 18:00:00', overrides: [
            'customer_name' => 'Snapshot Customer',
            'event_name' => 'Wedding Reception',
            'event_type_name' => 'Wedding',
            'venue_name' => 'Bai Hotel',
            'internal_notes' => 'Private note',
        ]);
        $short = $this->serviceLine($booking, 120, sortOrder: 2, overrides: [
            'service_name' => 'Mirror Booth',
            'package_name' => 'Classic',
            'quantity' => 2,
        ]);
        $long = $this->serviceLine($booking, 240, sortOrder: 1, overrides: [
            'service_name' => '360 Booth',
            'package_name' => 'Premium',
        ]);
        $zoe = Staff::factory()->for($organization)->create(['name' => 'Zoe Santos']);
        $ana = Staff::factory()->for($organization)->create(['name' => 'Ana Reyes']);
        $this->assign($long, $zoe, $admin);
        $this->assign($long, $ana, $admin);

        $this->actingAs($admin)->getJson($this->calendarUrl())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $booking->id)
            ->assertJsonPath('data.0.status', 'PENDING')
            ->assertJsonPath('data.0.start_at', '2027-06-15 18:00')
            ->assertJsonPath('data.0.end_at', '2027-06-15 22:00')
            ->assertJsonPath('data.0.customer_name', 'Snapshot Customer')
            ->assertJsonPath('data.0.event_name', 'Wedding Reception')
            ->assertJsonPath('data.0.event_type_name', 'Wedding')
            ->assertJsonPath('data.0.venue_name', 'Bai Hotel')
            ->assertJsonCount(2, 'data.0.services')
            ->assertJsonPath('data.0.services.0.id', $long->id)
            ->assertJsonPath('data.0.services.0.start_at', '2027-06-15 18:00')
            ->assertJsonPath('data.0.services.0.end_at', '2027-06-15 22:00')
            ->assertJsonPath('data.0.services.0.staff.0.name', 'Ana Reyes')
            ->assertJsonPath('data.0.services.0.staff.1.name', 'Zoe Santos')
            ->assertJsonPath('data.0.services.1.id', $short->id)
            ->assertJsonPath('data.0.services.1.start_at', '2027-06-15 18:00')
            ->assertJsonPath('data.0.services.1.end_at', '2027-06-15 20:00')
            ->assertJsonMissingPath('data.0.internal_notes')
            ->assertJsonMissingPath('data.0.customer_email')
            ->assertJsonMissingPath('data.0.services.0.unit_rate')
            ->assertJsonMissingPath('data.0.quotations')
            ->assertJsonMissingPath('data.0.billings')
            ->assertJsonMissingPath('data.0.payments');
    }

    public function test_default_and_explicit_status_filters_follow_booking_statuses(): void
    {
        [$admin, $organization] = $this->admin();

        foreach (BookingStatus::cases() as $index => $status) {
            $booking = $this->booking(
                $organization,
                $admin,
                sprintf('2027-06-15 %02d:00:00', 10 + $index),
                $status,
            );
            $this->serviceLine($booking, 60);
        }

        $default = $this->actingAs($admin)->getJson($this->calendarUrl())
            ->assertOk()
            ->assertJsonCount(3, 'data');
        $this->assertSame(['PENDING', 'QUOTED', 'CONFIRMED'], $default->json('data.*.status'));

        $this->getJson($this->calendarUrl(['statuses' => ['COMPLETED']]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'COMPLETED');
        $this->getJson($this->calendarUrl(['statuses' => ['CANCELLED']]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'CANCELLED');
    }

    public function test_service_staff_and_unassigned_filters_match_relevant_lines_without_duplicates(): void
    {
        [$admin, $organization] = $this->admin();
        $serviceA = Service::factory()->for($organization)->create(['name' => 'Service A']);
        $serviceB = Service::factory()->for($organization)->create(['name' => 'Service B']);
        $assignedStaff = Staff::factory()->for($organization)->create();
        $otherStaff = Staff::factory()->for($organization)->create();
        $matching = $this->booking($organization, $admin, '2027-06-15 10:00:00');
        $assignedLine = $this->serviceLine($matching, 120, service: $serviceA);
        $unassignedLine = $this->serviceLine($matching, 180, sortOrder: 2, service: $serviceB);
        $this->assign($assignedLine, $assignedStaff, $admin);
        $this->assign($assignedLine, $otherStaff, $admin);
        $other = $this->booking($organization, $admin, '2027-06-16 10:00:00');
        $otherLine = $this->serviceLine($other, 120, service: $serviceA);
        $this->assign($otherLine, $otherStaff, $admin);

        $this->actingAs($admin)->getJson($this->calendarUrl(['service_id' => $serviceB->id]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matching->id)
            ->assertJsonCount(2, 'data.0.services');
        $this->getJson($this->calendarUrl(['staff_id' => $assignedStaff->id]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matching->id);
        $this->getJson($this->calendarUrl(['staff' => 'unassigned']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matching->id);
        $this->getJson($this->calendarUrl([
            'service_id' => $serviceA->id,
            'staff' => 'unassigned',
        ]))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->calendarUrl([
            'service_id' => $serviceB->id,
            'staff' => 'unassigned',
        ]))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.services.1.id', $unassignedLine->id);
        $this->getJson($this->calendarUrl([
            'staff_id' => $assignedStaff->id,
            'staff' => 'unassigned',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['staff_id', 'staff']);
    }

    public function test_calendar_and_filter_ids_are_strictly_tenant_scoped(): void
    {
        [$admin, $organization] = $this->admin();
        [$foreignAdmin, $foreignOrganization] = $this->admin();
        $own = $this->booking($organization, $admin, '2027-06-15 10:00:00');
        $ownLine = $this->serviceLine($own, 120);
        $foreign = $this->booking($foreignOrganization, $foreignAdmin, '2027-06-15 09:00:00');
        $foreignLine = $this->serviceLine($foreign, 120);
        $foreignStaff = Staff::factory()->for($foreignOrganization)->create();
        $this->assign($foreignLine, $foreignStaff, $foreignAdmin);

        $this->actingAs($admin)->getJson($this->calendarUrl())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id);
        $this->getJson($this->calendarUrl(['service_id' => $foreignLine->service_id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('service_id');
        $this->getJson($this->calendarUrl(['staff_id' => $foreignStaff->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('staff_id');

        $this->assertNotSame($ownLine->service_id, $foreignLine->service_id);
    }

    public function test_calendar_query_count_is_bounded_and_ordering_is_deterministic(): void
    {
        [$admin, $organization] = $this->admin();
        $staff = Staff::factory()->for($organization)->create();
        $created = [];

        foreach (['2027-06-16 10:00:00', '2027-06-15 10:00:00', '2027-06-15 10:00:00'] as $startAt) {
            $booking = $this->booking($organization, $admin, $startAt);
            $created[] = $booking;
            $line = $this->serviceLine($booking, 120);
            $this->serviceLine($booking, 180, sortOrder: 2);
            $this->assign($line, $staff, $admin);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($admin)->getJson($this->calendarUrl())
            ->assertOk()
            ->assertJsonCount(3, 'data');
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $ids = $response->json('data.*.id');
        $this->assertSame([$created[1]->id, $created[2]->id, $created[0]->id], $ids);
        $this->assertSame(3, $queryCount);
    }

    /** @return array{User, Organization} */
    private function admin(): array
    {
        $organization = Organization::factory()->create();

        return [User::factory()->for($organization)->create(), $organization];
    }

    /** @param array<string, mixed> $overrides */
    private function booking(
        Organization $organization,
        User $admin,
        string $startAt,
        BookingStatus $status = BookingStatus::Pending,
        array $overrides = [],
    ): Booking {
        $this->bookingSequence++;
        $customer = Customer::factory()->for($organization)->create([
            'name' => "Calendar Customer {$this->bookingSequence}",
        ]);
        $eventType = EventType::factory()->for($organization)->create([
            'name' => "Calendar Event Type {$this->bookingSequence}",
        ]);
        $state = [
            'organization_id' => $organization->id,
            'created_by' => $admin->id,
            'customer_id' => $customer->id,
            'event_type_id' => $eventType->id,
            'customer_name' => $customer->name,
            'event_type_name' => $eventType->name,
            'booking_number' => sprintf('BK-CALENDAR-%06d', $this->bookingSequence),
            'start_at' => $startAt,
            'status' => $status,
            ...$overrides,
        ];

        if ($status === BookingStatus::Completed) {
            $state['completed_at'] = '2027-06-01 00:00:00';
            $state['completed_by'] = $admin->id;
        }

        if ($status === BookingStatus::Cancelled) {
            $state['cancelled_at'] = '2027-06-01 00:00:00';
            $state['cancelled_by'] = $admin->id;
        }

        return Booking::factory()->create($state);
    }

    /** @param array<string, mixed> $overrides */
    private function serviceLine(
        Booking $booking,
        int $durationMinutes,
        int $sortOrder = 1,
        ?Service $service = null,
        array $overrides = [],
    ): BookingService {
        $service ??= Service::factory()->for($booking->organization)->create();
        $package = Package::factory()->forService($service)->create();
        $quantity = (int) ($overrides['quantity'] ?? 1);

        return BookingService::factory()
            ->forBooking($booking)
            ->forPackage($package, $service)
            ->create([
                'duration_minutes' => $durationMinutes,
                'quantity' => $quantity,
                'line_total' => number_format(7500 * $quantity, 2, '.', ''),
                'sort_order' => $sortOrder,
                ...$overrides,
            ]);
    }

    private function assign(BookingService $line, Staff $staff, User $admin): void
    {
        $line->assignedStaff()->attach($staff->id, [
            'organization_id' => $line->organization_id,
            'assigned_by' => $admin->id,
            'assigned_at' => '2027-06-01 00:00:00',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function calendarUrl(array $overrides = []): string
    {
        return '/api/v1/calendar?'.http_build_query([
            ...[
                'start' => '2027-06-01',
                'end' => '2027-07-01',
            ],
            ...$overrides,
        ]);
    }
}
