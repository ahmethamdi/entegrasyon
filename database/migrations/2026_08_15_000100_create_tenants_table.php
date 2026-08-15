<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mimari Karar Dokümanı v2.2 · §4 · tablo 001.
 *
 * tenants kiracısızdır — kiracılığın kökü. BelongsToTenant uygulanmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default('active');
            $table->string('plan_code')->nullable();
            $table->string('default_currency', 3)->default('TRY');
            $table->string('default_locale', 5)->default('tr');
            $table->string('timezone')->default('Europe/Istanbul');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
