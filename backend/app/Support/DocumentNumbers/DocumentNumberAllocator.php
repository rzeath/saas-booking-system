<?php

namespace App\Support\DocumentNumbers;

use App\Enums\DocumentType;
use App\Models\Organization;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

class DocumentNumberAllocator
{
    public function allocate(
        Organization $organization,
        DocumentType $documentType,
        DateTimeInterface $createdAt,
    ): string {
        $connection = DB::connection($organization->getConnectionName());

        if ($connection->transactionLevel() === 0) {
            throw new LogicException('Document numbers must be allocated inside the caller business transaction.');
        }

        $settings = $this->lockedSettings($connection, $organization);
        $year = (int) DateTimeImmutable::createFromInterface($createdAt)
            ->setTimezone(new DateTimeZone((string) config('app.timezone')))
            ->format('Y');
        $now = now();

        // The unique key makes concurrent first inserts collapse to one row before it is locked.
        $connection->table('document_sequences')->insertOrIgnore([
            'organization_id' => $organization->getKey(),
            'document_type' => $documentType->value,
            'year' => $year,
            'next_number' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $sequence = $connection->table('document_sequences')
            ->where('organization_id', $organization->getKey())
            ->where('document_type', $documentType->value)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        if ($sequence === null) {
            throw new RuntimeException('The document sequence could not be initialized.');
        }

        $nextNumber = (int) $sequence->next_number;

        if ($nextNumber < 1 || $nextNumber === PHP_INT_MAX) {
            throw new RuntimeException('The document sequence is outside the supported range.');
        }

        $connection->table('document_sequences')
            ->where('id', $sequence->id)
            ->update([
                'next_number' => $nextNumber + 1,
                'updated_at' => $now,
            ]);

        $prefixColumn = $documentType->settingsPrefixColumn();

        return sprintf('%s-%04d-%06d', $settings->{$prefixColumn}, $year, $nextNumber);
    }

    private function lockedSettings(ConnectionInterface $connection, Organization $organization): object
    {
        $settings = $connection->table('business_settings')
            ->where('organization_id', $organization->getKey())
            ->lockForUpdate()
            ->first();

        if ($settings === null) {
            throw new RuntimeException('Business settings must exist before allocating a document number.');
        }

        return $settings;
    }
}
