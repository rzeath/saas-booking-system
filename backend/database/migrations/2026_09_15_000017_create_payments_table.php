<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('booking_id');
            $table->unsignedBigInteger('quotation_id');
            $table->unsignedBigInteger('billing_id');
            $table->decimal('amount', 13, 2);
            $table->dateTime('paid_at', 6);
            $table->string('payment_method', 30);
            $table->string('reference_number')->nullable();
            $table->text('internal_note')->nullable();
            $table->string('status', 20)->default('POSTED');
            $table->unsignedBigInteger('created_by');
            $table->dateTime('voided_at', 6)->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps(6);

            $table->foreign(
                ['organization_id', 'booking_id', 'quotation_id', 'billing_id'],
                'payments_billing_lineage_foreign',
            )->references(['organization_id', 'booking_id', 'quotation_id', 'id'])
                ->on('billings')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'created_by'], 'payments_tenant_creator_foreign')
                ->references(['organization_id', 'id'])
                ->on('users')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'voided_by'], 'payments_tenant_voider_foreign')
                ->references(['organization_id', 'id'])
                ->on('users')
                ->restrictOnDelete();
            $table->index(['organization_id', 'paid_at'], 'payments_tenant_paid_index');
            $table->index(['billing_id', 'status', 'paid_at'], 'payments_billing_status_paid_index');
            $table->index(['organization_id', 'reference_number'], 'payments_tenant_reference_index');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                ALTER TABLE payments
                    ADD CONSTRAINT payments_amount_positive CHECK (amount > 0),
                    ADD CONSTRAINT payments_method_valid
                        CHECK (payment_method IN ('CASH', 'GCASH', 'BANK_TRANSFER', 'CHECK')),
                    ADD CONSTRAINT payments_reference_consistent
                        CHECK (
                            payment_method = 'CASH'
                            OR (reference_number IS NOT NULL AND CHAR_LENGTH(TRIM(reference_number)) > 0)
                        ),
                    ADD CONSTRAINT payments_status_valid CHECK (status IN ('POSTED', 'VOIDED')),
                    ADD CONSTRAINT payments_void_audit_consistent
                        CHECK (
                            (status = 'POSTED'
                                AND voided_at IS NULL
                                AND voided_by IS NULL
                                AND void_reason IS NULL)
                            OR (status = 'VOIDED'
                                AND voided_at IS NOT NULL
                                AND voided_by IS NOT NULL
                                AND void_reason IS NOT NULL
                                AND CHAR_LENGTH(TRIM(void_reason)) > 0)
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
        Schema::dropIfExists('payments');
    }

    private function createSqliteConstraintTriggers(): void
    {
        $violation = <<<'SQL'
            NEW.amount <= 0
            OR NEW.payment_method NOT IN ('CASH', 'GCASH', 'BANK_TRANSFER', 'CHECK')
            OR (NEW.payment_method != 'CASH' AND (NEW.reference_number IS NULL OR LENGTH(TRIM(NEW.reference_number)) = 0))
            OR NEW.status NOT IN ('POSTED', 'VOIDED')
            OR (NEW.status = 'POSTED'
                AND (NEW.voided_at IS NOT NULL OR NEW.voided_by IS NOT NULL OR NEW.void_reason IS NOT NULL))
            OR (NEW.status = 'VOIDED'
                AND (NEW.voided_at IS NULL OR NEW.voided_by IS NULL OR NEW.void_reason IS NULL OR LENGTH(TRIM(NEW.void_reason)) = 0))
            SQL;

        DB::unprepared("CREATE TRIGGER payments_constraints_insert BEFORE INSERT ON payments WHEN {$violation} BEGIN SELECT RAISE(ABORT, 'payment constraints violated'); END");
        DB::unprepared("CREATE TRIGGER payments_constraints_update BEFORE UPDATE ON payments WHEN {$violation} BEGIN SELECT RAISE(ABORT, 'payment constraints violated'); END");
    }
};
