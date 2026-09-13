<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->unsignedInteger('total_units');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'name'], 'services_tenant_name_unique');
            $table->unique(['organization_id', 'id'], 'services_tenant_id_unique');
            $table->index(['organization_id', 'is_active', 'name'], 'services_tenant_active_name_index');
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE services ADD CONSTRAINT services_total_units_positive CHECK (total_units > 0)');
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('CREATE UNIQUE INDEX services_tenant_name_nocase_unique ON services (organization_id, name COLLATE NOCASE)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
