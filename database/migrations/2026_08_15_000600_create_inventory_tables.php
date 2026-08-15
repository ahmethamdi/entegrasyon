<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mimari Karar Dokümanı v2.2 · §4 · Inventory · tablolar 011–012.
 *
 * TEMEL INVARIANT:
 *   on_hand   = Σ inventory_movements.on_hand_delta
 *   reserved  = Σ inventory_movements.reserved_delta
 *   available = on_hand − reserved            (generated stored)
 *
 * Bu eşitlik HER KOŞULDA geçerlidir, fazla satış dahil. Projeksiyon
 * güncellemesinde GREATEST/LEAST/clamp YASAKTIR.
 *
 * KASITLI OLARAK YOK:
 *   CHECK (on_hand >= 0)    — pazaryeri siparişi otoriterdir ve reddedilemez;
 *                             kısıt konulursa fazla satışta transaction geri
 *                             alınır ve sipariş hiç kaydedilmez.
 *   CHECK (available >= 0)  — negatiflik eksik miktarın kendisidir.
 *   oversold_qty kolonu     — ikinci durum kaynağı tutarsızlaşır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_levels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('warehouse_id');
            $table->uuid('variant_id');

            $table->integer('on_hand')->default(0);   // NEGATİF OLABİLİR
            $table->integer('reserved')->default(0);

            $table->bigInteger('version')->default(0);
            $table->uuid('last_movement_id')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->cascadeOnDelete();
            $table->foreign('variant_id')->references('id')->on('variants')->cascadeOnDelete();

            $table->unique(['tenant_id', 'warehouse_id', 'variant_id'],
                'inventory_levels_unique');

            $table->index(['tenant_id', 'variant_id']);
        });

        // available türetilmiş ve saklanan kolon: tutarsızlaşması imkânsız.
        DB::statement(<<<'SQL'
            ALTER TABLE inventory_levels
                ADD COLUMN available integer
                GENERATED ALWAYS AS (on_hand - reserved) STORED
        SQL);

        // reserved bizim kontrolümüzdeki bir değerdir; negatif olamaz.
        DB::statement(<<<'SQL'
            ALTER TABLE inventory_levels
                ADD CONSTRAINT reserved_non_negative CHECK (reserved >= 0)
        SQL);

        // Fazla satılmış satırlar: panel uyarısı ve metrik.
        DB::statement(<<<'SQL'
            CREATE INDEX inventory_levels_oversold_idx
                ON inventory_levels (tenant_id)
                WHERE available < 0
        SQL);

        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('warehouse_id');
            $table->uuid('variant_id');
            $table->string('type');

            $table->integer('on_hand_delta');
            $table->integer('reserved_delta');
            $table->integer('on_hand_after');     // kırpılmamış gerçek bakiye
            $table->integer('reserved_after');

            $table->string('source_type');
            $table->uuid('source_id')->nullable();
            $table->uuid('channel_connection_id')->nullable();
            $table->string('idempotency_key');
            $table->timestamp('occurred_at')->useCurrent();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->cascadeOnDelete();
            $table->foreign('variant_id')->references('id')->on('variants')->cascadeOnDelete();
            $table->foreign('channel_connection_id')->references('id')
                ->on('channel_connections')->nullOnDelete();

            $table->unique(['tenant_id', 'idempotency_key'], 'movements_idempotent');

            $table->index(['tenant_id', 'variant_id', 'occurred_at'], 'movements_variant_idx');
            $table->index(['tenant_id', 'type', 'occurred_at'], 'movements_type_time_idx');
            $table->index(['source_type', 'source_id'], 'movements_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        DB::statement('DROP INDEX IF EXISTS inventory_levels_oversold_idx');
        Schema::dropIfExists('inventory_levels');
    }
};
