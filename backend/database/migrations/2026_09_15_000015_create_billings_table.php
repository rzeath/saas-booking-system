<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('booking_id');
            $table->unsignedBigInteger('quotation_id');
            $table->string('billing_number', 50);
            $table->string('quotation_number', 50);
            $table->string('business_display_name');
            $table->string('business_email')->nullable();
            $table->string('business_phone', 50)->nullable();
            $table->text('business_address')->nullable();
            $table->string('business_logo_path')->nullable();
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
            $table->decimal('subtotal', 13, 2);
            $table->decimal('transportation_fee', 13, 2)->default(0);
            $table->decimal('crew_meal_fee', 13, 2)->default(0);
            $table->decimal('discount_amount', 13, 2)->default(0);
            $table->decimal('total', 13, 2);
            $table->unsignedBigInteger('created_by');
            $table->timestamps(6);

            $table->foreign(['organization_id', 'booking_id'], 'billings_tenant_booking_foreign')
                ->references(['organization_id', 'id'])
                ->on('bookings')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'quotation_id'], 'billings_tenant_quotation_foreign')
                ->references(['organization_id', 'id'])
                ->on('quotations')
                ->restrictOnDelete();
            $table->foreign(['booking_id', 'quotation_id'], 'billings_booking_quotation_foreign')
                ->references(['booking_id', 'id'])
                ->on('quotations')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'created_by'], 'billings_tenant_creator_foreign')
                ->references(['organization_id', 'id'])
                ->on('users')
                ->restrictOnDelete();
            $table->unique(['organization_id', 'billing_number'], 'billings_tenant_number_unique');
            $table->unique('quotation_id', 'billings_quotation_unique');
            $table->unique(
                ['organization_id', 'booking_id', 'quotation_id', 'id'],
                'billings_lineage_id_unique',
            );
            $table->index(['organization_id', 'created_at'], 'billings_tenant_created_index');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                ALTER TABLE billings
                    ADD CONSTRAINT billings_money_valid
                        CHECK (
                            subtotal >= 0
                            AND transportation_fee >= 0
                            AND crew_meal_fee >= 0
                            AND discount_amount >= 0
                            AND discount_amount <= subtotal + transportation_fee + crew_meal_fee
                            AND total > 0
                            AND total = subtotal + transportation_fee + crew_meal_fee - discount_amount
                        )
                SQL);

            return;
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->createSqliteConstraintTriggers();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('billings');
    }

    private function createSqliteConstraintTriggers(): void
    {
        $violation = <<<'SQL'
            NEW.subtotal < 0
            OR NEW.transportation_fee < 0
            OR NEW.crew_meal_fee < 0
            OR NEW.discount_amount < 0
            OR NEW.discount_amount > NEW.subtotal + NEW.transportation_fee + NEW.crew_meal_fee
            OR NEW.total <= 0
            OR ROUND(NEW.total, 2) != ROUND(NEW.subtotal + NEW.transportation_fee + NEW.crew_meal_fee - NEW.discount_amount, 2)
            SQL;

        DB::unprepared("CREATE TRIGGER billings_constraints_insert BEFORE INSERT ON billings WHEN {$violation} BEGIN SELECT RAISE(ABORT, 'billing constraints violated'); END");
        DB::unprepared("CREATE TRIGGER billings_constraints_update BEFORE UPDATE ON billings WHEN {$violation} BEGIN SELECT RAISE(ABORT, 'billing constraints violated'); END");
    }
};
