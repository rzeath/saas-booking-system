<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('event_type_id');
            $table->unsignedBigInteger('package_id');
            $table->unsignedInteger('duration_minutes');
            $table->decimal('unit_rate', 13, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign(['organization_id', 'event_type_id'], 'service_rates_tenant_event_type_foreign')
                ->references(['organization_id', 'id'])
                ->on('event_types')
                ->restrictOnDelete();
            $table->foreign(['organization_id', 'package_id'], 'service_rates_tenant_package_foreign')
                ->references(['organization_id', 'id'])
                ->on('packages')
                ->restrictOnDelete();
            $table->unique(
                ['organization_id', 'event_type_id', 'package_id', 'duration_minutes'],
                'service_rates_combination_unique',
            );
            $table->index(
                ['organization_id', 'event_type_id', 'package_id', 'duration_minutes', 'is_active'],
                'service_rates_pricing_lookup_index',
            );
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                ALTER TABLE service_rates
                    ADD CONSTRAINT service_rates_duration_positive CHECK (duration_minutes > 0),
                    ADD CONSTRAINT service_rates_unit_rate_non_negative CHECK (unit_rate >= 0)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('service_rates');
    }
};
