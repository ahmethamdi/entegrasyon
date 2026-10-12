<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-010 · Bağlantı başına dışarı yazma izinleri.
 *
 * Varsayılan `false` ve NOT NULL: betikle, panelden ya da doğrudan
 * veritabanından açılan her yeni bağlantı kanala HİÇBİR ŞEY yazamaz.
 *
 * GEÇİŞ KURALI (mevcut satırlar): bu sürümden önce her bağlantı, kimlik
 * bilgisi olduğu an ürün, fiyat ve stok yazabiliyordu. Hepsiburada ve N11
 * DIŞINDAKİ mevcut bağlantılarda bu davranış korunur (üçü de `true`):
 * canlıda Trendyol/Etsy/Shopify akışları buna dayanır. Hepsiburada ve
 * N11 bağlantıları canlı doğrulama kapısından geçmediği için `false`
 * kalır. Bağlantı türü kod olarak eşlenir; kanal türünün `is_active`
 * bayrağına, kimlik bilgisine ya da adapter yeteneğine BAKILMAZ.
 */
return new class extends Migration
{
    private const COLUMNS = ['product_publish_enabled', 'price_sync_enabled', 'stock_sync_enabled'];

    private const UNVERIFIED_CHANNELS = ['hepsiburada', 'n11'];

    public function up(): void
    {
        Schema::table('channel_connections', function (Blueprint $table): void {
            foreach (self::COLUMNS as $column) {
                $table->boolean($column)->default(false);
            }
        });

        DB::table('channel_connections')
            ->whereNotIn('channel_type_code', self::UNVERIFIED_CHANNELS)
            ->update(array_fill_keys(self::COLUMNS, true));
    }

    public function down(): void
    {
        Schema::table('channel_connections', function (Blueprint $table): void {
            $table->dropColumn(self::COLUMNS);
        });
    }
};
