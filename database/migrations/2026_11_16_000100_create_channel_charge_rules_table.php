<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kanal kesinti varsayılanları (kâr paneli B · 16 Kasım 2026).
 *
 * Bağlantı başına bir VARSAYILAN satır (`internal_category_id = ''`):
 * komisyon yüzdesi, sipariş başı kargo kesintisi ve sipariş başı paketleme
 * maliyeti. Kategori satırları (`internal_category_id` dolu) yalnız
 * komisyon yüzdesini ezer — kargo/paketleme sipariş düzeyindedir.
 *
 * Bu kurallar yeni siparişte BEKLENEN kesinti üretir (`order_charges`,
 * source = 'rule'); hakediş gelince gerçek rakam ayrıca yazılır. Kural
 * değişmesi geçmiş kayda dokunmaz (order_charges ekleme-yalnız).
 *
 * Yalnız ekleme: eski kod bu tabloyu okumaz; geri alma tabloyu düşürür.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_charge_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('channel_connection_id');
            // '' = bağlantının varsayılanı; dolu = iç kategori istisnası.
            $table->string('internal_category_id')->default('');
            $table->decimal('commission_percent', 5, 2)->nullable();
            $table->decimal('shipping_fee', 12, 2)->nullable();
            $table->decimal('packaging_fee', 12, 2)->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('channel_connection_id')->references('id')->on('channel_connections')->cascadeOnDelete();
            $table->unique(['channel_connection_id', 'internal_category_id']);
            $table->index(['tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_charge_rules');
    }
};
