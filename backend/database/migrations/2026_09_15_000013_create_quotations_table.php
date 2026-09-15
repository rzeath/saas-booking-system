<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('booking_id');
            $table->string('quotation_number', 50);
            $table->string('status', 20)->default('DRAFT');
            $table->date('valid_until')->nullable();
            $table->dateTime('sent_at', 6)->nullable();
            $table->dateTime('accepted_at', 6)->nullable();
            $table->dateTime('closed_at', 6)->nullable();
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
            $table->unsignedTinyInteger('active_slot')->storedAs(
                "CASE WHEN status IN ('DRAFT', 'SENT') THEN 1 ELSE NULL END",
            );
            $table->unsignedTinyInteger('accepted_slot')->storedAs(
                "CASE WHEN status = 'ACCEPTED' THEN 1 ELSE NULL END",
            );
            $table->unsignedBigInteger('created_by');
            $table->timestamps(6);

            $table->foreign(['organization_id', 'booking_id'], 'quotations_tenant_booking_foreign')
                ->references(['organization_id', 'id'])
                ->on('bookings')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'created_by'], 'quotations_tenant_creator_foreign')
                ->references(['organization_id', 'id'])
                ->on('users')
                ->restrictOnDelete();
            $table->unique(['organization_id', 'quotation_number'], 'quotations_tenant_number_unique');
            $table->unique(['organization_id', 'id'], 'quotations_tenant_id_unique');
            $table->unique(['booking_id', 'id'], 'quotations_booking_id_unique');
            $table->unique(['booking_id', 'active_slot'], 'quotations_booking_active_unique');
            $table->unique(['booking_id', 'accepted_slot'], 'quotations_booking_accepted_unique');
            $table->index(
                ['organization_id', 'booking_id', 'status', 'created_at'],
                'quotations_tenant_booking_status_created_index',
            );
            $table->index(
                ['organization_id', 'status', 'valid_until'],
                'quotations_tenant_status_expiry_index',
            );
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                ALTER TABLE quotations
                    ADD CONSTRAINT quotations_status_valid
                        CHECK (status IN ('DRAFT', 'SENT', 'ACCEPTED', 'REJECTED', 'CANCELLED', 'EXPIRED', 'OUTDATED')),
                    ADD CONSTRAINT quotations_money_valid
                        CHECK (
                            subtotal >= 0
                            AND transportation_fee >= 0
                            AND crew_meal_fee >= 0
                            AND discount_amount >= 0
                            AND discount_amount <= subtotal + transportation_fee + crew_meal_fee
                            AND total >= 0
                            AND total = subtotal + transportation_fee + crew_meal_fee - discount_amount
                        ),
                    ADD CONSTRAINT quotations_payable_status_total_positive
                        CHECK (status NOT IN ('SENT', 'ACCEPTED') OR total > 0)
                SQL);

            return;
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->createSqliteConstraintTriggers();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('quotations');
    }

    private function createSqliteConstraintTriggers(): void
    {
        $violation = <<<'SQL'
            NEW.status NOT IN ('DRAFT', 'SENT', 'ACCEPTED', 'REJECTED', 'CANCELLED', 'EXPIRED', 'OUTDATED')
            OR NEW.subtotal < 0
            OR NEW.transportation_fee < 0
            OR NEW.crew_meal_fee < 0
            OR NEW.discount_amount < 0
            OR NEW.discount_amount > NEW.subtotal + NEW.transportation_fee + NEW.crew_meal_fee
            OR NEW.total < 0
            OR ROUND(NEW.total, 2) != ROUND(NEW.subtotal + NEW.transportation_fee + NEW.crew_meal_fee - NEW.discount_amount, 2)
            OR (NEW.status IN ('SENT', 'ACCEPTED') AND NEW.total <= 0)
            SQL;

        DB::unprepared("CREATE TRIGGER quotations_constraints_insert BEFORE INSERT ON quotations WHEN {$violation} BEGIN SELECT RAISE(ABORT, 'quotation constraints violated'); END");
        DB::unprepared("CREATE TRIGGER quotations_constraints_update BEFORE UPDATE ON quotations WHEN {$violation} BEGIN SELECT RAISE(ABORT, 'quotation constraints violated'); END");
    }
};
