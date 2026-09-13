<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained()
                ->restrictOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'name'], 'event_types_tenant_name_unique');
            $table->unique(['organization_id', 'id'], 'event_types_tenant_id_unique');
            $table->index(
                ['organization_id', 'is_active', 'name'],
                'event_types_tenant_active_name_index',
            );
        });

        // MySQL's application collation already makes the composite unique key
        // case-insensitive. Mirror that invariant in SQLite-based feature tests.
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX event_types_tenant_name_nocase_unique
                    ON event_types (organization_id, name COLLATE NOCASE)
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('event_types');
    }
};
