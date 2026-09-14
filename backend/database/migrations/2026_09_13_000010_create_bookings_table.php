<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unique(['organization_id', 'id'], 'users_tenant_id_unique');
        });

        Schema::create('bookings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('booking_number', 50);
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('event_type_id');
            $table->string('customer_name');
            $table->string('customer_email')->nullable();
            $table->string('customer_phone', 50)->nullable();
            $table->text('customer_address')->nullable();
            $table->string('event_type_name');
            $table->string('event_name');
            $table->date('event_date');
            $table->string('venue_name');
            $table->text('venue_address')->nullable();
            $table->string('contact_person');
            $table->string('contact_number', 50);
            $table->string('status', 20)->default('PENDING');
            $table->text('internal_notes')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->dateTime('completed_at', 6)->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->dateTime('cancelled_at', 6)->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps(6);

            $table->foreign(['organization_id', 'customer_id'], 'bookings_tenant_customer_foreign')
                ->references(['organization_id', 'id'])
                ->on('customers')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'event_type_id'], 'bookings_tenant_event_type_foreign')
                ->references(['organization_id', 'id'])
                ->on('event_types')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'created_by'], 'bookings_tenant_creator_foreign')
                ->references(['organization_id', 'id'])
                ->on('users')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'completed_by'], 'bookings_tenant_completer_foreign')
                ->references(['organization_id', 'id'])
                ->on('users')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'cancelled_by'], 'bookings_tenant_canceller_foreign')
                ->references(['organization_id', 'id'])
                ->on('users')
                ->restrictOnDelete();
            $table->unique(['organization_id', 'booking_number'], 'bookings_tenant_number_unique');
            $table->unique(['organization_id', 'id'], 'bookings_tenant_id_unique');
            $table->index(['organization_id', 'status', 'event_date'], 'bookings_tenant_status_date_index');
            $table->index(['organization_id', 'customer_id', 'event_date'], 'bookings_tenant_customer_date_index');
            $table->index(['organization_id', 'event_type_id'], 'bookings_tenant_event_type_index');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                ALTER TABLE bookings
                    ADD CONSTRAINT bookings_status_valid
                        CHECK (status IN ('PENDING', 'QUOTED', 'CONFIRMED', 'COMPLETED', 'CANCELLED')),
                    ADD CONSTRAINT bookings_lifecycle_audit_consistent
                        CHECK (
                            (status = 'CANCELLED'
                                AND cancelled_at IS NOT NULL
                                AND cancelled_by IS NOT NULL
                                AND completed_at IS NULL
                                AND completed_by IS NULL)
                            OR (status = 'COMPLETED'
                                AND completed_at IS NOT NULL
                                AND completed_by IS NOT NULL
                                AND cancelled_at IS NULL
                                AND cancelled_by IS NULL
                                AND cancellation_reason IS NULL)
                            OR (status IN ('PENDING', 'QUOTED', 'CONFIRMED')
                                AND completed_at IS NULL
                                AND completed_by IS NULL
                                AND cancelled_at IS NULL
                                AND cancelled_by IS NULL
                                AND cancellation_reason IS NULL)
                        )
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_tenant_id_unique');
        });
    }
};
