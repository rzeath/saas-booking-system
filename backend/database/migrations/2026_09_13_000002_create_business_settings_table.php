<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();
            $table->string('display_name');
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('address')->nullable();
            $table->string('logo_path', 2048)->nullable();
            $table->char('currency', 3)->default('PHP');
            $table->string('booking_prefix', 10)->default('BK');
            $table->string('quotation_prefix', 10)->default('QT');
            $table->string('billing_prefix', 10)->default('INV');
            $table->timestamps();
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(<<<'SQL'
                ALTER TABLE business_settings
                    ADD CONSTRAINT business_settings_display_name_not_blank
                        CHECK (CHAR_LENGTH(TRIM(display_name)) > 0),
                    ADD CONSTRAINT business_settings_currency_format
                        CHECK (REGEXP_LIKE(currency, '^[A-Z]{3}$', 'c')),
                    ADD CONSTRAINT business_settings_prefixes_valid
                        CHECK (
                            REGEXP_LIKE(booking_prefix, '^[A-Z0-9]+$', 'c')
                            AND REGEXP_LIKE(quotation_prefix, '^[A-Z0-9]+$', 'c')
                            AND REGEXP_LIKE(billing_prefix, '^[A-Z0-9]+$', 'c')
                        )
                SQL);
        }

        DB::table('organizations')
            ->select(['id', 'name'])
            ->orderBy('id')
            ->chunkById(100, function (Collection $organizations): void {
                $now = now();
                $settings = $organizations->map(fn (object $organization): array => [
                    'organization_id' => $organization->id,
                    'display_name' => $organization->name,
                    'currency' => 'PHP',
                    'booking_prefix' => 'BK',
                    'quotation_prefix' => 'QT',
                    'billing_prefix' => 'INV',
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                DB::table('business_settings')->insert($settings);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_settings');
    }
};
