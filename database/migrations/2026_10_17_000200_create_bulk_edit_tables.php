<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S5 · Excel toplu fiyat/stok düzenleme (ADR-012 · §11).
 *
 * İKİ AŞAMA: yükleme yalnız AYRIŞTIRIR ve satırları önizleme olarak
 * yazar — stok/fiyat DEĞİŞMEZ. Uygulama ayrı ve açık eylemdir, bir kez
 * (`status parsed → applying` koşullu geçiş).
 *
 * Yüklenen dosya SAKLANMAZ: ayrıştırılır ve atılır; kalıcı olan satırların
 * yorumudur. Satır başına "önizleme anındaki" stok/fiyat
 * (`stock_current`, `price_current`) uygulamada yeniden karşılaştırılır:
 * arada satış olduysa satır uygulanmaz (eski dosya satılanı geri eklemez).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_edit_imports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id')->nullable();
            $table->uuid('warehouse_id');
            $table->string('filename', 255);
            // parsed | rejected | applying | applied | failed
            $table->string('status', 16);
            $table->string('reason_code', 64)->nullable();
            $table->jsonb('counts')->default('{}');
            $table->timestampTz('apply_requested_at')->nullable();
            $table->timestampTz('applied_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('bulk_edit_rows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('bulk_edit_import_id');
            $table->integer('row_number');
            $table->uuid('variant_id')->nullable();
            $table->string('sku', 191)->nullable();
            $table->string('label', 500)->nullable();

            // change | unchanged | invalid | conflict | applied | failed
            $table->string('status', 16);
            $table->string('reason_code', 64)->nullable();

            // Dosyadaki "mevcut" (dışa aktarım anı), önizleme anındaki gerçek, istenen.
            $table->integer('stock_file')->nullable();
            $table->integer('stock_current')->nullable();
            $table->integer('stock_new')->nullable();
            $table->decimal('price_file', 12, 2)->nullable();
            $table->decimal('price_current', 12, 2)->nullable();
            $table->decimal('price_new', 12, 2)->nullable();
            $table->char('currency', 3)->nullable();
            // Uygulanınca kanala gidecek mi (önizlemede bilgi).
            $table->smallInteger('stock_channels')->default(0);
            $table->smallInteger('price_channels')->default(0);

            $table->timestampTz('applied_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('bulk_edit_import_id')->references('id')->on('bulk_edit_imports')->cascadeOnDelete();
            $table->foreign('variant_id')->references('id')->on('variants')->nullOnDelete();
            $table->unique(['bulk_edit_import_id', 'row_number']);
            $table->index(['bulk_edit_import_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_edit_rows');
        Schema::dropIfExists('bulk_edit_imports');
    }
};
