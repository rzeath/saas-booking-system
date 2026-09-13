<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('service_id');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign(['organization_id', 'service_id'], 'packages_tenant_service_foreign')
                ->references(['organization_id', 'id'])
                ->on('services')
                ->restrictOnDelete();
            $table->unique(['organization_id', 'service_id', 'name'], 'packages_service_name_unique');
            $table->unique(['organization_id', 'id'], 'packages_tenant_id_unique');
            $table->unique(['organization_id', 'service_id', 'id'], 'packages_tenant_service_id_unique');
            $table->index(
                ['organization_id', 'service_id', 'is_active', 'name'],
                'packages_tenant_service_active_name_index',
            );
        });

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('CREATE UNIQUE INDEX packages_service_name_nocase_unique ON packages (organization_id, service_id, name COLLATE NOCASE)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
