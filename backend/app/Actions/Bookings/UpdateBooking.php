<?php

namespace App\Actions\Bookings;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingService;
use App\Models\Organization;
use App\Support\Bookings\BookingMasterDataResolver;
use App\Support\Bookings\BookingServiceCandidate;
use App\Support\Bookings\BookingServiceCandidateBuilder;
use App\Support\Bookings\ServiceAvailabilityChecker;
use App\Support\Bookings\ServiceRowLocker;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateBooking
{
    public function __construct(
        private readonly BookingMasterDataResolver $masterData,
        private readonly ServiceRowLocker $serviceLocker,
        private readonly BookingServiceCandidateBuilder $candidateBuilder,
        private readonly ServiceAvailabilityChecker $availability,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(Organization $organization, int $bookingId, array $data): Booking
    {
        return DB::transaction(function () use ($organization, $bookingId, $data): Booking {
            $booking = Booking::query()
                ->where('organization_id', $organization->id)
                ->whereKey($bookingId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($booking->status !== BookingStatus::Pending) {
                throw ValidationException::withMessages([
                    'status' => 'Only pending bookings may be edited.',
                ]);
            }

            $existingLines = BookingService::query()
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
            $candidates = $this->candidateBuilder->build(
                $organization,
                $data['event_date'],
                $booking->timezone,
                $data['booking_services'],
                $eventType->id,
                $services,
            );

            $this->validateLineIdentities($candidates, $existingLines);
            $this->availability->ensureAvailable(
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
                'event_date' => $data['event_date'],
                'venue_name' => $data['venue_name'],
                'venue_address' => $data['venue_address'] ?? null,
                'contact_person' => $data['contact_person'],
                'contact_number' => $data['contact_number'],
                'internal_notes' => $data['internal_notes'] ?? null,
            ]);

            $retainedIds = [];
            foreach ($candidates as $candidate) {
                if ($candidate->id !== null) {
                    $existingLines->get($candidate->id)->update(
                        $candidate->persistenceAttributes($organization->id),
                    );
                    $retainedIds[] = $candidate->id;

                    continue;
                }

                $created = $booking->bookingServices()->create(
                    $candidate->persistenceAttributes($organization->id),
                );
                $retainedIds[] = $created->id;
            }

            BookingService::query()
                ->where('organization_id', $organization->id)
                ->where('booking_id', $booking->id)
                ->whereNotIn('id', $retainedIds)
                ->delete();

            return $booking->refresh()->load(['customer', 'eventType', 'bookingServices']);
        }, 3);
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
