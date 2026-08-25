<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `listings.channel_metadata` — V3.0'ın TEK fiziksel DB şema değişikliği.
 *
 * V3.0 · §03 · Delta 2 · §16 · DB Delta 1 · §07.
 *
 * NEDEN GEREKLİ: Shopify, Etsy ve eBay'in üçünde de BİRDEN ÇOK KALICI uzak
 * kimlik var ve `external_id` + `external_parent_id` yetmiyor:
 *
 *   Shopify  {"inventory_item_gid": "gid://shopify/InventoryItem/789"}
 *   Etsy     {"offering_id": 4512345678}
 *   eBay     {"offer_id": "8912345", "marketplace_id": "EBAY_DE"}
 *
 * Bu kimlikler YAZMA HEDEFİDİR: Shopify'ın stok mutation'ı variant gid'i
 * kabul etmez, `inventoryItemId` ister; eBay'de stok/fiyat `offer_id` ile
 * yazılır ve o kimlik kaybedilirse yeniden yaratma `25002` (duplicate offer)
 * verir — KALICI hata, listing "düzeltilemez" damgasıyla ölür.
 *
 * NEDEN KOLON BAŞINA AYRI ALAN DEĞİL: her kanal farklı kimlik taşır ve kolon
 * eklemek altı kanalın beşinde NULL duran bir şema üretirdi. Kimlikler
 * ÇEKİRDEK TARAFINDAN SORGULANMAZ — yalnızca adapter okur ve yazar. JSONB tam
 * bu iş içindir (`channel_connections.settings` ile aynı gerekçe).
 *
 * NEDEN AYRI TABLO DEĞİL: kardinalite 1:1 olurdu ve her okuma bir JOIN eklerdi.
 *
 * İNDEKS YOK ve bu bilinçlidir: çekirdek bu alanı sorgulamaz. İndeks eklemek
 * her listing yazmasına bakım maliyeti bindirirdi ve hiçbir sorgu onu
 * kullanmazdı.
 *
 * ⚠️ DEĞİŞMEZ KURAL — `channel_metadata` SIR TAŞIMAZ (P0-9 · T-V3-20).
 * Kolon ŞİFRESİZDİR ve panele Inertia prop'u olarak gidebilir. Token, secret
 * ve imza `channel_credentials`'ta (şifreli) yaşar — v2.2'nin "sırlar
 * `settings` içine yazılmaz" kuralının aynısı. KİMLİK ≠ SIR.
 *
 * GERİ ALMA: kolon düşer ve Shopify/Etsy/eBay listing'leri stok yazamaz hâle
 * gelir; Woo/Trendyol/Hepsiburada ETKİLENMEZ (§26 · geri alma tablosu).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table): void {
            $table->jsonb('channel_metadata')->nullable()->after('external_parent_id');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table): void {
            $table->dropColumn('channel_metadata');
        });
    }
};
