<?php

namespace App\Actions\Bookings;

use App\Enums\BookingStatus;
use App\Enums\DocumentType;
use App\Models\Booking;
use App\Models\Organization;
use App\Models\User;
use App\Support\Bookings\BookingMasterDataResolver;
use App\Support\Bookings\BookingServiceCandidateBuilder;
use App\Support\Bookings\ServiceAvailabilityChecker;
use App\Support\Bookings\ServiceRowLocker;
use App\Support\DocumentNumbers\DocumentNumberAllocator;
use Illuminate\Support\Facades\DB;

class CreateBooking
{
    public function __construct(
        private readonly BookingMasterDataResolver $masterData,
        private readonly ServiceRowLocker $serviceLocker,
        private readonly BookingServiceCandidateBuilder $candidateBuilder,
        private readonly ServiceAvailabilityChecker $availability,
        private readonly DocumentNumberAllocator $numberAllocator,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(Organization $organization, User $user, array $data): Booking
    {
        return DB::transaction(function () use ($organization, $user, $data): Booking {
            $customer = $this->masterData->customer($organization, (int) $data['customer_id']);
            $eventType = $this->masterData->eventType($organization, (int) $data['event_type_id']);
            $serviceIds = array_map(
                fn (array $line): int => (int) $line['service_id'],
                $data['booking_services'],
            );
            $services = $this->serviceLocker->lock($organization, $serviceIds);
            $timezone = $organization->businessSetting()->value('timezone');

            $candidates = $this->candidateBuilder->build(
                $organization,
                $data['event_date'],
                $timezone,
                $data['booking_services'],
                $eventType->id,
                $services,
            );

            $this->availability->ensureAvailable(
                $organization->id,
                $candidates,
                lockReservations: true,
            );

            $createdAt = now('UTC');
            $booking = $organization->bookings()->create([
                'booking_number' => $this->numberAllocator->allocate(
                    $organization,
                    DocumentType::Booking,
                    $createdAt,
                ),
                ...$this->bookingAttributes($data, $customer, $eventType),
                'timezone' => $timezone,
                'status' => BookingStatus::Pending,
                'created_by' => $user->id,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            foreach ($candidates as $candidate) {
                $booking->bookingServices()->create(
                    $candidate->persistenceAttributes($organization->id),
                );
            }

            return $booking->load(['customer', 'eventType', 'bookingServices']);
        }, 3);
    }

    /** @return array<string, mixed> */
    private function bookingAttributes(array $data, object $customer, object $eventType): array
    {
        return [
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
        ];
    }
}
