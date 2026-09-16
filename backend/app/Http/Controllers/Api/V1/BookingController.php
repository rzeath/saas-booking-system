<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Bookings\CancelBooking;
use App\Actions\Bookings\CreateBooking;
use App\Actions\Bookings\UpdateBooking;
use App\Http\Controllers\Controller;
use App\Http\Requests\BookingAvailabilityRequest;
use App\Http\Requests\BookingIndexRequest;
use App\Http\Requests\CancelBookingRequest;
use App\Http\Requests\SaveBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Support\Bookings\BookingServiceCandidateBuilder;
use App\Support\Bookings\ManilaSchedule;
use App\Support\Bookings\ServiceAvailabilityChecker;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BookingController extends Controller
{
    public function index(
        BookingIndexRequest $request,
        TenantContext $tenant,
        ManilaSchedule $schedule,
    ): AnonymousResourceCollection {
        $validated = $request->validated();
        $rangeStart = isset($validated['event_date_from'])
            ? $schedule->startAt($validated['event_date_from'], '00:00', 'event_date_from')
            : null;
        $rangeEnd = isset($validated['event_date_to'])
            ? $schedule->endAt(
                $schedule->startAt($validated['event_date_to'], '00:00', 'event_date_to'),
                1440,
            )
            : null;

        $bookings = Booking::query()
            ->with(['customer', 'eventType', 'bookingServices.assignedStaff'])
            ->where('organization_id', $tenant->organizationId())
            ->when($validated['search'] ?? null, fn ($query, string $term) => $query->where(function ($query) use ($term): void {
                $query->where('booking_number', 'like', "%{$term}%")
                    ->orWhere('event_name', 'like', "%{$term}%")
                    ->orWhere('customer_name', 'like', "%{$term}%")
                    ->orWhere('contact_person', 'like', "%{$term}%")
                    ->orWhere('contact_number', 'like', "%{$term}%")
                    ->orWhere('venue_name', 'like', "%{$term}%");
            }))
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($rangeStart, fn ($query) => $query->where('start_at', '>=', $rangeStart))
            ->when($rangeEnd, fn ($query) => $query->where('start_at', '<', $rangeEnd))
            ->when($validated['customer_id'] ?? null, fn ($query, int $id) => $query->where('customer_id', $id))
            ->when($validated['event_type_id'] ?? null, fn ($query, int $id) => $query->where('event_type_id', $id))
            ->orderByDesc('start_at')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return BookingResource::collection($bookings);
    }

    public function store(
        SaveBookingRequest $request,
        TenantContext $tenant,
        CreateBooking $createBooking,
    ): BookingResource {
        $booking = $createBooking->handle(
            $tenant->organization(),
            $tenant->user(),
            $request->validated(),
        );

        return new BookingResource($booking);
    }

    public function show(int $booking, TenantContext $tenant): BookingResource
    {
        return new BookingResource($this->resolve($booking, $tenant));
    }

    public function update(
        SaveBookingRequest $request,
        int $booking,
        TenantContext $tenant,
        UpdateBooking $updateBooking,
    ): BookingResource {
        return new BookingResource($updateBooking->handle(
            $tenant->organization(),
            $tenant->user(),
            $booking,
            $request->validated(),
        ));
    }

    public function cancel(
        CancelBookingRequest $request,
        int $booking,
        TenantContext $tenant,
        CancelBooking $cancelBooking,
    ): BookingResource {
        return new BookingResource($cancelBooking->handle(
            $tenant->organization(),
            $tenant->user(),
            $booking,
            $request->validated('reason'),
        ));
    }

    public function availability(
        BookingAvailabilityRequest $request,
        TenantContext $tenant,
        ManilaSchedule $schedule,
        BookingServiceCandidateBuilder $candidateBuilder,
        ServiceAvailabilityChecker $availability,
    ): JsonResponse {
        $organization = $tenant->organization();
        $validated = $request->validated();
        $startAt = $schedule->startAt(
            $validated['event_date'],
            $validated['start_time'],
            'start_time',
        );
        $candidates = $candidateBuilder->build(
            $organization,
            $startAt,
            $validated['booking_services'],
        );

        return response()->json($availability->check(
            $organization->id,
            $candidates,
            isset($validated['booking_id']) ? (int) $validated['booking_id'] : null,
        ));
    }

    private function resolve(int $booking, TenantContext $tenant): Booking
    {
        return Booking::query()
            ->with([
                'customer',
                'eventType',
                'bookingServices.assignedStaff',
                'quotations' => fn ($query) => $query
                    ->orderByDesc('created_at')
                    ->orderByDesc('id'),
            ])
            ->where('organization_id', $tenant->organizationId())
            ->whereKey($booking)
            ->firstOrFail();
    }
}
