<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `listings.channel_price` + `channel_price_currency` — satıcının o kanal
 * için girdiği, GÖNDERİLEN fiyat.
 *
 * `price_overrides`'TAN AYRIDIR ve karıştırılmaz: override "kanaldaki fiyat
 * kalsın, GÖNDERME" kararıdır (§9 çakışma çözümü). Bu kolon ise satıcının
 * BİZDEN o kanala gitmesini istediği fiyattır — USD Etsy mağazasında
 * $12.90, TL ürünün 199,90'ı yerine.
 *
 * NEDEN LISTING ÜZERİNDE: fiyat varyant × bağlantı başınadır ve listing tam
 * olarak o satırdır (unique: bağlantı + varyant). Ayrı tablo 1:1 olurdu.
 *
 * Para birimi DEĞERLE BİRLİKTE saklanır: satıcı fiyatı hangi birimde girdi
 * bilinmezse mağaza para birimini sonradan değiştirdiğinde eski rakam yeni
 * birimle giderdi; koruma (`EtsyAdapter::currencyFailuresFor`) bu alana
 * bakarak durdurur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table): void {
            $table->decimal('channel_price', 12, 2)->nullable();
            $table->char('channel_price_currency', 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table): void {
            $table->dropColumn(['channel_price', 'channel_price_currency']);
        });
    }
};
