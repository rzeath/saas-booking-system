<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Bookings\UpdateBookingServiceStaffAssignments;
use App\Http\Controllers\Controller;
use App\Http\Requests\StaffAvailabilityRequest;
use App\Http\Requests\UpdateBookingServiceStaffAssignmentsRequest;
use App\Http\Resources\StaffResource;
use App\Models\BookingService;
use App\Support\Bookings\ManilaSchedule;
use App\Support\Bookings\StaffAvailabilityFinder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BookingServiceStaffController extends Controller
{
    public function candidateAvailability(
        StaffAvailabilityRequest $request,
        TenantContext $tenant,
        ManilaSchedule $schedule,
        StaffAvailabilityFinder $availability,
    ): JsonResponse {
        $validated = $request->validated();
        $startAt = $schedule->startAt(
            $validated['event_date'],
            $validated['start_time'],
            'start_time',
        );
        $endAt = $startAt->modify("+{$validated['duration_minutes']} minutes");

        return response()->json([
            'staff' => $availability->forSchedule(
                $tenant->organizationId(),
                $startAt,
                $endAt,
                isset($validated['booking_service_id']) ? (int) $validated['booking_service_id'] : null,
            ),
        ]);
    }

    public function availability(
        int $bookingService,
        TenantContext $tenant,
        StaffAvailabilityFinder $availability,
    ): JsonResponse {
        $resolved = $this->resolve($bookingService, $tenant);

        return response()->json([
            'staff' => $availability->forSchedule(
                $tenant->organizationId(),
                $resolved->start_at,
                $resolved->end_at,
                $resolved->id,
            ),
        ]);
    }

    public function index(
        int $bookingService,
        TenantContext $tenant,
    ): AnonymousResourceCollection {
        return StaffResource::collection(
            $this->resolve($bookingService, $tenant)
                ->assignedStaff()
                ->orderBy('staff.name')
                ->get(),
        );
    }

    public function update(
        UpdateBookingServiceStaffAssignmentsRequest $request,
        int $bookingService,
        TenantContext $tenant,
        UpdateBookingServiceStaffAssignments $updateAssignments,
    ): AnonymousResourceCollection {
        $updated = $updateAssignments->handle(
            $tenant->organization(),
            $tenant->user(),
            $bookingService,
            $request->validated('staff_ids'),
        );

        return StaffResource::collection($updated->assignedStaff);
    }

    private function resolve(int $bookingService, TenantContext $tenant): BookingService
    {
        return BookingService::query()
            ->where('organization_id', $tenant->organizationId())
            ->whereKey($bookingService)
            ->firstOrFail();
    }
}
