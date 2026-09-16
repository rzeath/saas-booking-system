<?php

namespace App\Actions\Bookings;

use App\Actions\Quotations\OutdateQuotation;
use App\Enums\BookingStatus;
use App\Enums\QuotationStatus;
use App\Models\Booking;
use App\Models\BookingService;
use App\Models\Organization;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Support\Bookings\BookingCommercialChangeDetector;
use App\Support\Bookings\BookingMasterDataResolver;
use App\Support\Bookings\BookingServiceCandidate;
use App\Support\Bookings\BookingServiceCandidateBuilder;
use App\Support\Bookings\ManilaSchedule;
use App\Support\Bookings\ServiceAvailabilityChecker;
use App\Support\Bookings\ServiceRowLocker;
use App\Support\Bookings\StaffAssignmentSynchronizer;
use App\Support\Bookings\StaffAssignmentValidator;
use App\Support\Bookings\StaffAvailabilityChecker;
use App\Support\Bookings\StaffRowLocker;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateBooking
{
    public function __construct(
        private readonly BookingMasterDataResolver $masterData,
        private readonly ServiceRowLocker $serviceLocker,
        private readonly ManilaSchedule $schedule,
        private readonly BookingServiceCandidateBuilder $candidateBuilder,
        private readonly ServiceAvailabilityChecker $availability,
        private readonly StaffRowLocker $staffLocker,
        private readonly StaffAssignmentValidator $staffValidator,
        private readonly StaffAvailabilityChecker $staffAvailability,
        private readonly StaffAssignmentSynchronizer $staffAssignments,
        private readonly BookingCommercialChangeDetector $commercialChanges,
        private readonly OutdateQuotation $outdateQuotation,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(Organization $organization, User $user, int $bookingId, array $data): Booking
    {
        return DB::transaction(function () use ($organization, $user, $bookingId, $data): Booking {
            $booking = Booking::query()
                ->where('organization_id', $organization->id)
                ->whereKey($bookingId)
                ->lockForUpdate()
                ->firstOrFail();

            [$activeQuotation, $acceptedQuotation] = $this->lockRelevantQuotations($booking);
            $this->ensureEditableState($booking, $activeQuotation, $acceptedQuotation);

            $existingLines = BookingService::query()
                ->with('assignedStaff')
                ->where('organization_id', $organization->id)
                ->where('booking_id', $booking->id)
                ->get()
                ->keyBy('id');
            $newServiceIds = array_map(
                fn (array $line): int => (int) $line['service_id'],
                $data['booking_services'],
            );
            $allServiceIds = array_merge(
                $existingLines->pluck('service_id')->map(fn ($id): int => (int) $id)->all(),
                $newServiceIds,
            );
            $services = $this->serviceLocker->lock($organization, $allServiceIds);
            $customer = $this->masterData->customer($organization, (int) $data['customer_id']);
            $eventType = $this->masterData->eventType($organization, (int) $data['event_type_id']);
            $startAt = $this->schedule->startAt(
                $data['event_date'],
                $data['start_time'],
                'start_time',
            );
            $candidates = $this->candidateBuilder->build(
                $organization,
                $startAt,
                $data['booking_services'],
                $eventType->id,
                $services,
            );

            $this->validateLineIdentities($candidates, $existingLines);
            $hasCommercialChanges = $this->commercialChanges->hasChanges(
                $booking,
                $existingLines,
                $customer,
                $eventType,
                $candidates,
                $startAt,
                $data,
            );

            if ($acceptedQuotation !== null && $hasCommercialChanges) {
                throw ValidationException::withMessages([
                    'booking' => 'Commercially quoted Booking data cannot be changed after quotation acceptance.',
                ]);
            }

            if ($activeQuotation !== null && $hasCommercialChanges) {
                $this->outdateQuotation->handleLocked($activeQuotation, $booking);
            }

            $staff = $this->staffLocker->lock(
                $organization,
                array_merge(
                    $this->staffIds($candidates),
                    $existingLines->flatMap(fn (BookingService $line) => $line->assignedStaff->pluck('id'))
                        ->map(fn ($id): int => (int) $id)
                        ->all(),
                ),
            );
            $this->staffValidator->validate($candidates, $staff, $existingLines);
            $this->availability->ensureAvailable(
                $organization->id,
                $candidates,
                excludeBookingId: $booking->id,
                lockReservations: true,
            );
            $this->staffAvailability->ensureAvailable(
                $organization->id,
                $candidates,
                excludeBookingId: $booking->id,
                lockReservations: true,
            );

            $booking->update([
                'customer_id' => $customer->id,
                'event_type_id' => $eventType->id,
                'customer_name' => $customer->name,
                'customer_email' => $customer->email,
                'customer_phone' => $customer->phone,
                'customer_address' => $customer->address,
                'event_type_name' => $eventType->name,
                'event_name' => $data['event_name'],
                'start_at' => $startAt,
                'venue_name' => $data['venue_name'],
                'venue_address' => $data['venue_address'] ?? null,
                'contact_person' => $data['contact_person'],
                'contact_number' => $data['contact_number'],
                'internal_notes' => $data['internal_notes'] ?? null,
            ]);

            $retainedIds = [];
            foreach ($candidates as $candidate) {
                if ($candidate->id !== null) {
                    $line = $existingLines->get($candidate->id);
                    $line->update(
                        $candidate->persistenceAttributes($organization->id),
                    );
                    $this->staffAssignments->sync($line, $candidate->staffIds, $user);
                    $retainedIds[] = $candidate->id;

                    continue;
                }

                $created = $booking->bookingServices()->create(
                    $candidate->persistenceAttributes($organization->id),
                );
                $this->staffAssignments->sync($created, $candidate->staffIds, $user);
                $retainedIds[] = $created->id;
            }

            $removedIds = $existingLines->keys()
                ->map(fn ($id): int => (int) $id)
                ->diff($retainedIds)
                ->values()
                ->all();

            if ($removedIds !== []) {
                QuotationItem::query()
                    ->where('booking_id', $booking->id)
                    ->whereIn('booking_service_id', $removedIds)
                    ->update(['booking_service_id' => null]);
            }

            BookingService::query()
                ->where('organization_id', $organization->id)
                ->where('booking_id', $booking->id)
                ->whereNotIn('id', $retainedIds)
                ->delete();

            return $booking->refresh()->load(['customer', 'eventType', 'bookingServices.assignedStaff']);
        }, 3);
    }

    /** @return array{Quotation|null, Quotation|null} */
    private function lockRelevantQuotations(Booking $booking): array
    {
        $quotations = Quotation::query()
            ->where('organization_id', $booking->organization_id)
            ->where('booking_id', $booking->id)
            ->whereIn('status', [
                QuotationStatus::Draft,
                QuotationStatus::Sent,
                QuotationStatus::Accepted,
            ])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        return [
            $quotations->first(fn (Quotation $quotation): bool => in_array(
                $quotation->status,
                [QuotationStatus::Draft, QuotationStatus::Sent],
                true,
            )),
            $quotations->firstWhere('status', QuotationStatus::Accepted),
        ];
    }

    private function ensureEditableState(
        Booking $booking,
        ?Quotation $activeQuotation,
        ?Quotation $acceptedQuotation,
    ): void {
        $isConsistentPending = $booking->status === BookingStatus::Pending
            && $acceptedQuotation === null
            && ($activeQuotation === null || $activeQuotation->status === QuotationStatus::Draft);
        $isConsistentQuoted = $booking->status === BookingStatus::Quoted
            && (($activeQuotation?->status === QuotationStatus::Sent) xor ($acceptedQuotation !== null));

        if (! $isConsistentPending && ! $isConsistentQuoted) {
            throw ValidationException::withMessages([
                'status' => 'Only pending or consistently quoted bookings may be edited.',
            ]);
        }
    }

    /**
     * @param  list<BookingServiceCandidate>  $candidates
     * @return list<int>
     */
    private function staffIds(array $candidates): array
    {
        return array_values(array_unique(array_merge(...array_map(
            fn (BookingServiceCandidate $candidate): array => $candidate->staffIds,
            $candidates,
        ))));
    }

    /**
     * @param  list<BookingServiceCandidate>  $candidates
     * @param  Collection<int, BookingService>  $existingLines
     */
    private function validateLineIdentities(array $candidates, $existingLines): void
    {
        foreach ($candidates as $index => $candidate) {
            if ($candidate->id !== null && ! $existingLines->has($candidate->id)) {
                throw ValidationException::withMessages([
                    "booking_services.{$index}.id" => 'The selected booking service is invalid.',
                ]);
            }
        }
    }
}
