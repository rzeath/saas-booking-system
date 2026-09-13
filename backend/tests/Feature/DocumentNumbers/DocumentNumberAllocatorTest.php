<?php

namespace Tests\Feature\DocumentNumbers;

use App\Enums\DocumentType;
use App\Models\BusinessSetting;
use App\Models\DocumentSequence;
use App\Models\Organization;
use App\Support\DocumentNumbers\DocumentNumberAllocator;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class DocumentNumberAllocatorTest extends TestCase
{
    use DatabaseMigrations;

    public function test_booking_numbers_start_at_one_and_increment(): void
    {
        $organization = $this->organizationWithSettings();
        $createdAt = new DateTimeImmutable('2027-04-10T12:00:00+00:00');

        $this->assertSame('BK-2027-000001', $this->allocate($organization, DocumentType::Booking, $createdAt));
        $this->assertSame('BK-2027-000002', $this->allocate($organization, DocumentType::Booking, $createdAt));
        $this->assertDatabaseHas('document_sequences', [
            'organization_id' => $organization->id,
            'document_type' => DocumentType::Booking->value,
            'year' => 2027,
            'next_number' => 3,
        ]);
    }

    public function test_document_types_have_independent_sequences(): void
    {
        $organization = $this->organizationWithSettings();
        $createdAt = new DateTimeImmutable('2027-04-10T12:00:00+00:00');

        $this->assertSame('BK-2027-000001', $this->allocate($organization, DocumentType::Booking, $createdAt));
        $this->assertSame('QT-2027-000001', $this->allocate($organization, DocumentType::Quotation, $createdAt));
        $this->assertSame('INV-2027-000001', $this->allocate($organization, DocumentType::Billing, $createdAt));
    }

    public function test_organizations_and_years_have_independent_sequences(): void
    {
        $first = $this->organizationWithSettings();
        $second = $this->organizationWithSettings();

        $this->assertSame(
            'BK-2027-000001',
            $this->allocate($first, DocumentType::Booking, new DateTimeImmutable('2027-06-01T00:00:00Z')),
        );
        $this->assertSame(
            'BK-2028-000001',
            $this->allocate($first, DocumentType::Booking, new DateTimeImmutable('2028-06-01T00:00:00Z')),
        );
        $this->assertSame(
            'BK-2027-000001',
            $this->allocate($second, DocumentType::Booking, new DateTimeImmutable('2027-06-01T00:00:00Z')),
        );
    }

    public function test_business_timezone_determines_the_sequence_year(): void
    {
        $organization = $this->organizationWithSettings(['timezone' => 'Asia/Manila']);

        $number = $this->allocate(
            $organization,
            DocumentType::Booking,
            new DateTimeImmutable('2026-12-31T16:30:00Z'),
        );

        $this->assertSame('BK-2027-000001', $number);
    }

    public function test_prefix_changes_affect_future_numbers_without_resetting_sequence(): void
    {
        $organization = $this->organizationWithSettings(['booking_prefix' => 'BOOK']);
        $createdAt = new DateTimeImmutable('2027-04-10T12:00:00Z');

        $this->assertSame('BOOK-2027-000001', $this->allocate($organization, DocumentType::Booking, $createdAt));

        $organization->businessSetting()->update(['booking_prefix' => 'EVENT']);

        $this->assertSame('EVENT-2027-000002', $this->allocate($organization, DocumentType::Booking, $createdAt));
        $this->assertDatabaseCount('document_sequences', 1);
    }

    public function test_allocator_requires_the_callers_active_transaction(): void
    {
        $organization = $this->organizationWithSettings();

        $this->expectException(LogicException::class);

        app(DocumentNumberAllocator::class)->allocate(
            $organization,
            DocumentType::Booking,
            new DateTimeImmutable('2027-04-10T12:00:00Z'),
        );
    }

    public function test_rolled_back_allocation_does_not_consume_the_number(): void
    {
        $organization = $this->organizationWithSettings();
        $createdAt = new DateTimeImmutable('2027-04-10T12:00:00Z');

        try {
            DB::transaction(function () use ($organization, $createdAt): void {
                app(DocumentNumberAllocator::class)->allocate(
                    $organization,
                    DocumentType::Booking,
                    $createdAt,
                );

                throw new RuntimeException('Roll back this business transaction.');
            });
        } catch (RuntimeException) {
            // The assertion below verifies the sequence insert and increment both rolled back.
        }

        $this->assertSame('BK-2027-000001', $this->allocate($organization, DocumentType::Booking, $createdAt));
    }

    public function test_database_enforces_one_sequence_row_per_tenant_type_and_year(): void
    {
        $organization = $this->organizationWithSettings();
        DocumentSequence::factory()->for($organization)->create();

        $this->expectException(QueryException::class);

        DocumentSequence::factory()->for($organization)->create();
    }

    /** @param array<string, mixed> $settings */
    private function organizationWithSettings(array $settings = []): Organization
    {
        $organization = Organization::factory()->create();
        BusinessSetting::factory()->for($organization)->create($settings);

        return $organization;
    }

    private function allocate(
        Organization $organization,
        DocumentType $documentType,
        DateTimeImmutable $createdAt,
    ): string {
        return DB::transaction(
            fn (): string => app(DocumentNumberAllocator::class)->allocate(
                $organization,
                $documentType,
                $createdAt,
            ),
        );
    }
}
