<?php

namespace App\Support\Bookings;

use App\Models\Customer;
use App\Models\EventType;
use App\Models\Organization;
use Illuminate\Validation\ValidationException;

class BookingMasterDataResolver
{
    public function customer(Organization $organization, int $customerId): Customer
    {
        $customer = Customer::query()
            ->where('organization_id', $organization->id)
            ->whereKey($customerId)
            ->where('is_active', true)
            ->first();

        if (! $customer instanceof Customer) {
            throw ValidationException::withMessages([
                'customer_id' => 'The selected customer is invalid or inactive.',
            ]);
        }

        return $customer;
    }

    public function eventType(Organization $organization, int $eventTypeId): EventType
    {
        $eventType = EventType::query()
            ->where('organization_id', $organization->id)
            ->whereKey($eventTypeId)
            ->where('is_active', true)
            ->first();

        if (! $eventType instanceof EventType) {
            throw ValidationException::withMessages([
                'event_type_id' => 'The selected event type is invalid or inactive.',
            ]);
        }

        return $eventType;
    }
}
