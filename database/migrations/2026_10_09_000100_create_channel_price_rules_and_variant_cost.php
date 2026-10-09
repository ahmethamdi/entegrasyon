<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fiyat kuralları — kalıcı kanal farkı + zarar koruması.
 *
 * `channel_price_rules`: bağlantı başına TEK satır. "Trendyol'da her şey
 * +%15 ve ,90'a yuvarla" gibi. Satıcının listing'e elle girdiği kanal fiyatı
 * (`listings.channel_price`) kuralın ÜSTÜNDEDİR: o bilinçli bir rakamdır.
 *
 * `variants.cost_price`: alış maliyeti, varyantın para biriminde. Boşsa
 * zarar koruması o varyant için çalışmaz (bilinmeyen maliyetle kıyas yok).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_price_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('channel_connection_id')->unique();

            // Yüzde ve sabit tutar BİRLİKTE uygulanabilir: önce yüzde, sonra
            // tutar (komisyon + sabit kargo payı). Negatif = indirim.
            $table->decimal('markup_percent', 6, 2)->default(0);
            $table->decimal('markup_amount', 12, 2)->default(0);

            // none | whole | x90 | x99 — YUKARI yuvarlar, kârı kırpmaz.
            $table->string('rounding', 8)->default('none');

            // Zarar koruması: giden fiyat maliyet × (1 + yüzde/100) altındaysa
            // GÖNDERİLMEZ. NULL = kapalı; 0 = maliyetin altına düşme.
            $table->decimal('min_margin_percent', 6, 2)->nullable();

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('channel_connection_id')->references('id')
                ->on('channel_connections')->cascadeOnDelete();
            $table->index('tenant_id');
        });

        Schema::table('variants', function (Blueprint $table): void {
            $table->decimal('cost_price', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('variants', function (Blueprint $table): void {
            $table->dropColumn('cost_price');
        });

        Schema::dropIfExists('channel_price_rules');
    }
};
