<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('quotation_id');
            $table->unsignedBigInteger('booking_id');
            $table->unsignedBigInteger('booking_service_id')->nullable();
            $table->string('service_name');
            $table->string('package_name');
            $table->dateTime('start_at', 6);
            $table->dateTime('end_at', 6);
            $table->unsignedInteger('duration_minutes');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_rate', 13, 2);
            $table->decimal('line_total', 13, 2);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps(6);

            $table->foreign(['booking_id', 'quotation_id'], 'quotation_items_booking_quotation_foreign')
                ->references(['booking_id', 'id'])
                ->on('quotations')
                ->restrictOnDelete();
            $table->foreign(
                ['booking_id', 'booking_service_id'],
                'quotation_items_booking_service_foreign',
            )->references(['booking_id', 'id'])
                ->on('booking_services')
                ->restrictOnDelete();
            $table->unique(
                ['quotation_id', 'booking_service_id'],
                'quotation_items_booking_service_unique',
            );
            $table->unique(['quotation_id', 'id'], 'quotation_items_quotation_id_unique');
            $table->index(['quotation_id', 'sort_order'], 'quotation_items_order_index');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                ALTER TABLE quotation_items
                    ADD CONSTRAINT quotation_items_interval_valid CHECK (start_at < end_at),
                    ADD CONSTRAINT quotation_items_duration_positive CHECK (duration_minutes > 0),
                    ADD CONSTRAINT quotation_items_quantity_positive CHECK (quantity > 0),
                    ADD CONSTRAINT quotation_items_unit_rate_non_negative CHECK (unit_rate >= 0),
                    ADD CONSTRAINT quotation_items_line_total_exact
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
        Schema::dropIfExists('quotation_items');
    }

    private function createSqliteConstraintTriggers(): void
    {
        $violation = <<<'SQL'
            NEW.start_at >= NEW.end_at
            OR NEW.duration_minutes <= 0
            OR NEW.quantity <= 0
            OR NEW.unit_rate < 0
            OR NEW.line_total < 0
            OR ROUND(NEW.line_total, 2) != ROUND(NEW.unit_rate * NEW.quantity, 2)
            SQL;

        DB::unprepared("CREATE TRIGGER quotation_items_constraints_insert BEFORE INSERT ON quotation_items WHEN {$violation} BEGIN SELECT RAISE(ABORT, 'quotation item constraints violated'); END");
        DB::unprepared("CREATE TRIGGER quotation_items_constraints_update BEFORE UPDATE ON quotation_items WHEN {$violation} BEGIN SELECT RAISE(ABORT, 'quotation item constraints violated'); END");
    }
};
