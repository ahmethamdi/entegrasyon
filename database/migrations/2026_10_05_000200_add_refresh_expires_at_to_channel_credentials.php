<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Yenileme anahtarının bitişi — kendiliğinden yenilenen bağlantıların
 * ASIL ömrü.
 *
 * `expires_at` erişim anahtarınındır ve yenileme turu (`TokenRefresher`)
 * onu okur. Shopify uygulamasında (2026'dan beri) erişim anahtarı 1 SAAT
 * yaşar: panel rozeti ve `token_expiring_soon` metriği yalnız `expires_at`'e
 * bakınca her Shopify bağlantısı SÜREKLİ "yakında dolacak" görünür ve her
 * saat uyarı üretirdi — oysa satıcının yapacağı bir şey yoktur. Satıcıyı
 * ilgilendiren, yenileme anahtarının bitişidir (Shopify: 90 gün); o geçerse
 * yeniden yetkilendirme gerekir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_credentials', function (Blueprint $table): void {
            $table->timestamp('refresh_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('channel_credentials', function (Blueprint $table): void {
            $table->dropColumn('refresh_expires_at');
        });
    }
};
