<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S5 · Tedarikçi XML kaynakları (ADR-012).
 *
 * KAYNAK ≠ ÜRÜN: tedarikçi akışı bir KAYNAKTIR. Kanonik ürün/varyant
 * tedarikçi durumu taşımaz; tedarikçinin söylediği her şey
 * `supplier_records`'ta ham + yorum olarak durur, varyanta yalnız BAĞ
 * (`variant_id`) üzerinden ve yalnız kanonik eylemlerle (defter,
 * SetVariantPrice, SetVariantCost, CreateProduct) yansır.
 *
 * `supplier_sources`: akış adresi (ŞİFRELİ — adres çoğu zaman erişim
 * anahtarı taşır), eşleme, politikalar, zamanlama ve son tur durumu.
 * `sync_state = running` + `running_run_id` aynı kaynağın iki turunun
 * birlikte koşmasını engelleyen koşullu kilittir.
 *
 * `supplier_records`: tedarikçi kaydı başına TEK satır, kimlik
 * `(kaynak, source_key)`. Sayısal alanlar `*_state` (present/zero/missing/
 * invalid) + değer: BİLİNMEYEN ≠ SIFIR. `presence = missing` "son tam turda
 * akışta yoktu" demektir, stok sıfır DEĞİL. `last_applied_*` defterde/
 * varyantta en son uygulanan tedarikçi değeri — "değişince uygula"
 * kuralının ve tekrar idempotensinin dayanağı. Bir varyant en çok BİR
 * tedarikçi kaydına bağlanır (kısmi tekil).
 *
 * `supplier_sync_runs` + `supplier_run_changes`: tur özeti ve yalnız
 * DEĞİŞEN/sorunlu kayıtların satırları (değişmeyen kayıt satır üretmez —
 * "500 güncellendi" yanılgısı olmasın).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 120);
            // encrypted cast — sorgulanmaz, yalnız okunur.
            $table->text('feed_url');
            // Gösterim için sırsız ana makine adı.
            $table->string('feed_host', 255);
            $table->boolean('is_active')->default(false);

            // Tekrarlanan kayıt düğümünün yolu (ör. "Urunler/Urun").
            $table->string('record_path', 255)->nullable();
            $table->jsonb('mapping')->default('{}');
            // dot = 1234.56 (binlik ","), comma = 1.234,56
            $table->string('number_format', 8)->default('dot');
            $table->char('currency', 3)->default('TRY');

            // informational | authoritative
            $table->string('stock_policy', 16)->default('informational');
            // none | feed_price | cost_markup
            $table->string('price_policy', 16)->default('none');
            $table->decimal('markup_percent', 7, 2)->default(0);
            $table->decimal('markup_amount', 12, 2)->default(0);
            $table->string('rounding', 8)->default('none');
            $table->boolean('create_products')->default(false);

            $table->string('sync_state', 16)->default('idle');
            $table->uuid('running_run_id')->nullable();
            $table->timestampTz('running_since')->nullable();
            $table->timestampTz('last_attempted_at')->nullable();
            $table->timestampTz('last_succeeded_at')->nullable();
            $table->string('last_status', 24)->nullable();
            $table->string('last_reason_code', 64)->nullable();
            $table->integer('last_duration_ms')->nullable();
            $table->timestampTz('next_run_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['is_active', 'next_run_at']);
            $table->index('tenant_id');
        });

        Schema::create('supplier_sync_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('supplier_source_id');
            // preview | apply
            $table->string('mode', 8);
            // manual | schedule
            $table->string('trigger', 12);
            // running | complete | partial | fetch_failed | parse_failed | validation_failed | failed
            $table->string('status', 24);
            $table->string('reason_code', 64)->nullable();
            // Ham hata metni (çevrilmez) — sunum metni reason_code'dan.
            $table->text('error_detail')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->bigInteger('bytes')->nullable();
            $table->integer('records_seen')->default(0);
            $table->jsonb('counts')->default('{}');
            $table->bigInteger('peak_memory_bytes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('supplier_source_id')->references('id')->on('supplier_sources')->cascadeOnDelete();
            $table->index(['supplier_source_id', 'created_at']);
        });

        Schema::create('supplier_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('supplier_source_id');
            $table->string('source_key', 191);
            $table->string('group_key', 191)->nullable();
            $table->uuid('variant_id')->nullable();

            $table->string('sku', 191)->nullable();
            $table->string('barcode', 64)->nullable();
            $table->string('name', 500)->nullable();
            // Kaydın düz alan haritası (yol → değerler), boyutu sınırlı.
            $table->jsonb('raw')->default('{}');

            $table->string('stock_state', 8);
            $table->integer('stock_value')->nullable();
            $table->string('cost_state', 8);
            $table->decimal('cost_value', 12, 2)->nullable();
            $table->string('price_state', 8);
            $table->decimal('price_value', 12, 2)->nullable();
            $table->char('currency', 3)->nullable();

            // present | missing
            $table->string('presence', 8)->default('present');
            // bound | unmatched | ambiguous | conflict | invalid | duplicate
            $table->string('match_status', 16);
            $table->string('reason_code', 64)->nullable();

            $table->integer('last_applied_stock')->nullable();
            $table->decimal('last_applied_cost', 12, 2)->nullable();
            $table->decimal('last_applied_price', 12, 2)->nullable();

            $table->uuid('first_seen_run_id')->nullable();
            $table->uuid('last_seen_run_id')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('supplier_source_id')->references('id')->on('supplier_sources')->cascadeOnDelete();
            $table->foreign('variant_id')->references('id')->on('variants')->nullOnDelete();
            $table->unique(['supplier_source_id', 'source_key'], 'supplier_records_key_unique');
            $table->index(['supplier_source_id', 'group_key']);
            $table->index(['supplier_source_id', 'match_status']);
        });

        // Bir varyant en çok bir tedarikçi kaydına bağlanır: iki kaynak aynı
        // varyantın stoğunu birbirinin üstüne yazmasın.
        DB::statement('CREATE UNIQUE INDEX supplier_records_variant_unique ON supplier_records (variant_id) WHERE variant_id IS NOT NULL');

        Schema::create('supplier_run_changes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('supplier_sync_run_id');
            $table->uuid('supplier_record_id')->nullable();
            $table->string('source_key', 191)->nullable();
            $table->uuid('variant_id')->nullable();
            // created_product | created_variant | matched | stock | cost | price
            // | ambiguous | conflict | invalid | duplicate | unmatched | missing | quota | error
            $table->string('kind', 24);
            $table->string('before', 64)->nullable();
            $table->string('after', 64)->nullable();
            $table->string('reason_code', 64)->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('supplier_sync_run_id')->references('id')->on('supplier_sync_runs')->cascadeOnDelete();
            $table->index(['supplier_sync_run_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_run_changes');
        Schema::dropIfExists('supplier_records');
        Schema::dropIfExists('supplier_sync_runs');
        Schema::dropIfExists('supplier_sources');
    }
};
