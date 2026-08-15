<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mimari Karar Dokümanı v2.2 · §4 · tablo 004 ve
 * "PostgreSQL DDL Correction — Warehouses Default Constraint".
 *
 * Kiracı başına en fazla BİR varsayılan depo, kısmi tekil INDEX ile.
 * DEFERRABLE kullanılmaz: PostgreSQL'de DEFERRABLE yalnızca tablo kısıtlarına
 * uygulanır, kısmi tekillik ise yalnızca indeksle ifade edilir ve indeks
 * ertelenemez. Değişim iki adımlı transaction ile yapılır (SetDefaultWarehouse).
 *
 * "En az bir varsayılan depo" kuralı veritabanı kısıtıyla ZORLANMAZ;
 * garanti CreateTenant action'ından gelir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code');
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->unique(['tenant_id', 'code'], 'warehouses_code_unique');
            $table->index(['tenant_id', 'is_active']);
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX warehouses_one_default_per_tenant
                ON warehouses (tenant_id)
                WHERE is_default = true
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS warehouses_one_default_per_tenant');
        Schema::dropIfExists('warehouses');
    }
};
