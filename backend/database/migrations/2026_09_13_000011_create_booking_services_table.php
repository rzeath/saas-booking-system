<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('booking_id');
            $table->unsignedBigInteger('service_id');
            $table->unsignedBigInteger('package_id');
            $table->unsignedInteger('duration_minutes');
            $table->unsignedInteger('quantity');
            $table->string('service_name');
            $table->string('package_name');
            $table->decimal('unit_rate', 13, 2);
            $table->decimal('line_total', 13, 2);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps(6);

            $table->foreign(['organization_id', 'booking_id'], 'booking_services_tenant_booking_foreign')
                ->references(['organization_id', 'id'])
                ->on('bookings')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'service_id'], 'booking_services_tenant_service_foreign')
                ->references(['organization_id', 'id'])
                ->on('services')
                ->restrictOnDelete();
            $table->foreign(
                ['organization_id', 'service_id', 'package_id'],
                'booking_services_tenant_service_package_foreign',
            )->references(['organization_id', 'service_id', 'package_id'])
                ->on('service_package')
                ->restrictOnDelete();
            $table->unique(['organization_id', 'id'], 'booking_services_tenant_id_unique');
            $table->unique(['booking_id', 'id'], 'booking_services_booking_id_unique');
            $table->index(
                ['organization_id', 'service_id', 'booking_id'],
                'booking_services_service_booking_index',
            );
            $table->index(
                ['organization_id', 'booking_id', 'sort_order'],
                'booking_services_booking_order_index',
            );
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                ALTER TABLE booking_services
                    ADD CONSTRAINT booking_services_duration_valid
                        CHECK (duration_minutes BETWEEN 1 AND 10080),
                    ADD CONSTRAINT booking_services_quantity_positive CHECK (quantity > 0),
                    ADD CONSTRAINT booking_services_unit_rate_non_negative CHECK (unit_rate >= 0),
                    ADD CONSTRAINT booking_services_line_total_exact
                        CHECK (line_total >= 0 AND line_total = unit_rate * quantity)
                SQL);

            return;
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->createSqliteConstraintTriggers();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_services');
    }

    private function createSqliteConstraintTriggers(): void
    {
        $violation = <<<'SQL'
            NEW.duration_minutes < 1
            OR NEW.duration_minutes > 10080
            OR NEW.quantity <= 0
            OR NEW.unit_rate < 0
            OR NEW.line_total < 0
            OR ROUND(NEW.line_total, 2) != ROUND(NEW.unit_rate * NEW.quantity, 2)
            SQL;

        DB::unprepared("CREATE TRIGGER booking_services_constraints_insert BEFORE INSERT ON booking_services WHEN {$violation} BEGIN SELECT RAISE(ABORT, 'booking service constraints violated'); END");
        DB::unprepared("CREATE TRIGGER booking_services_constraints_update BEFORE UPDATE ON booking_services WHEN {$violation} BEGIN SELECT RAISE(ABORT, 'booking service constraints violated'); END");
    }
};
