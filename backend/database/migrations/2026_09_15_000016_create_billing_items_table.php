<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('booking_id');
            $table->unsignedBigInteger('quotation_id');
            $table->unsignedBigInteger('billing_id');
            $table->unsignedBigInteger('quotation_item_id')->nullable();
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

            $table->foreign(
                ['organization_id', 'booking_id', 'quotation_id', 'billing_id'],
                'billing_items_billing_lineage_foreign',
            )->references(['organization_id', 'booking_id', 'quotation_id', 'id'])
                ->on('billings')
                ->restrictOnDelete();
            $table->foreign(
                ['quotation_id', 'quotation_item_id'],
                'billing_items_quotation_item_foreign',
            )->references(['quotation_id', 'id'])
                ->on('quotation_items')
                ->restrictOnDelete();
            $table->unique(
                ['billing_id', 'quotation_item_id'],
                'billing_items_source_unique',
            );
            $table->index(['billing_id', 'sort_order'], 'billing_items_order_index');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                ALTER TABLE billing_items
                    ADD CONSTRAINT billing_items_interval_valid CHECK (start_at < end_at),
                    ADD CONSTRAINT billing_items_duration_positive CHECK (duration_minutes > 0),
                    ADD CONSTRAINT billing_items_quantity_positive CHECK (quantity > 0),
                    ADD CONSTRAINT billing_items_unit_rate_non_negative CHECK (unit_rate >= 0),
                    ADD CONSTRAINT billing_items_line_total_exact
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
        Schema::dropIfExists('billing_items');
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

        DB::unprepared("CREATE TRIGGER billing_items_constraints_insert BEFORE INSERT ON billing_items WHEN {$violation} BEGIN SELECT RAISE(ABORT, 'billing item constraints violated'); END");
        DB::unprepared("CREATE TRIGGER billing_items_constraints_update BEFORE UPDATE ON billing_items WHEN {$violation} BEGIN SELECT RAISE(ABORT, 'billing item constraints violated'); END");
    }
};
