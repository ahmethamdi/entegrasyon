<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S9 · otomatik kur çevirisi (24 Ekim 2026).
 *
 * `exchange_rates`: TCMB'nin her bülteninin HAM kaydı — tarih, birim, alış/
 * satış. Kiracıya ait değildir (tüm sistem aynı kuru görür).
 *
 * `fx_pricing_rates`: FİYATLAMADA kullanılan kur, para birimi başına TEK
 * satır ("çapa"). Her bülten çapayı DEĞİŞTİRMEZ: fark eşiğin (%0,5)
 * altındaysa çapa kalır. Her gün binde iki oynayan kur her ilanı her gün
 * yeniden gönderir, mutabakat da her turda sahte fiyat çakışması görürdü.
 *
 * `channel_price_rules.fx_*`: bağlantı başına çeviri ayarı. Sıra TEK yerde
 * (`Listing`): TL fiyat kuralları → kur → kur yüzdesi → kur yuvarlaması.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source', 16);
            $table->date('rate_date');
            $table->char('currency', 3);
            $table->unsignedInteger('unit');
            $table->decimal('forex_buying', 18, 6)->nullable();
            $table->decimal('forex_selling', 18, 6)->nullable();
            $table->string('bulletin_no', 32)->nullable();
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['source', 'rate_date', 'currency']);
            $table->index(['currency', 'rate_date']);
        });

        Schema::create('fx_pricing_rates', function (Blueprint $table): void {
            $table->char('currency', 3)->primary();
            // 1 birim yabancı para = kaç TL (birim bölünmüş; JPY 100'lüktür).
            $table->decimal('rate', 18, 6);
            // Çapanın alındığı bülten.
            $table->date('rate_date');
            $table->string('source', 16);
            $table->timestamp('anchored_at');
            $table->timestamps();
        });

        Schema::table('channel_price_rules', function (Blueprint $table): void {
            $table->boolean('fx_enabled')->default(false);
            $table->char('fx_currency', 3)->nullable();
            // Kur sonrası eklenen pay (komisyon, yurt dışı kargo); negatif olamaz.
            $table->decimal('fx_markup_percent', 6, 2)->default(0);
            // none | whole | half | x49 | x90 | x99 — YUKARI yuvarlar.
            $table->string('fx_rounding', 8)->default('none');
            // Çevrilmiş fiyat bunun altındaysa GÖNDERİLMEZ (kanal biriminde).
            $table->decimal('fx_min_price', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('channel_price_rules', function (Blueprint $table): void {
            $table->dropColumn(['fx_enabled', 'fx_currency', 'fx_markup_percent', 'fx_rounding', 'fx_min_price']);
        });

        Schema::dropIfExists('fx_pricing_rates');
        Schema::dropIfExists('exchange_rates');
    }
};
