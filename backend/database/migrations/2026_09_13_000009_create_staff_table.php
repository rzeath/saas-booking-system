<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('phone', 50);
            $table->string('email')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'id'], 'staff_tenant_id_unique');
            $table->index(['organization_id', 'is_active', 'name'], 'staff_tenant_active_name_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff');
    }
};
