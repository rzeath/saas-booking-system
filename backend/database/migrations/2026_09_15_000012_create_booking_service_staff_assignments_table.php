<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_service_staff_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('booking_service_id');
            $table->unsignedBigInteger('staff_id');
            $table->unsignedBigInteger('assigned_by');
            $table->dateTime('assigned_at', 6);

            $table->foreign(
                ['organization_id', 'booking_service_id'],
                'booking_service_staff_tenant_booking_service_foreign',
            )->references(['organization_id', 'id'])
                ->on('booking_services')
                ->cascadeOnDelete();
            $table->foreign(
                ['organization_id', 'staff_id'],
                'booking_service_staff_tenant_staff_foreign',
            )->references(['organization_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
            $table->foreign(
                ['organization_id', 'assigned_by'],
                'booking_service_staff_tenant_assigner_foreign',
            )->references(['organization_id', 'id'])
                ->on('users')
                ->restrictOnDelete();
            $table->unique(
                ['booking_service_id', 'staff_id'],
                'booking_service_staff_assignment_unique',
            );
            $table->index(
                ['organization_id', 'staff_id', 'booking_service_id'],
                'booking_service_staff_availability_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_service_staff_assignments');
    }
};
