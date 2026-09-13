<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('document_type', 20);
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();

            $table->unique(
                ['organization_id', 'document_type', 'year'],
                'document_sequences_tenant_type_year_unique',
            );
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                ALTER TABLE document_sequences
                    ADD CONSTRAINT document_sequences_type_valid
                        CHECK (document_type IN ('BOOKING', 'QUOTATION', 'BILLING')),
                    ADD CONSTRAINT document_sequences_year_valid
                        CHECK (year >= 2000),
                    ADD CONSTRAINT document_sequences_next_number_valid
                        CHECK (next_number >= 1)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
