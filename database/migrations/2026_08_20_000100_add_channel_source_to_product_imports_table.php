<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `product_imports` tablosunu KANAL kaynaklı içe aktarmaya açar.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 3 · madde 5 ("kanaldan ürün
 * çekme"). Tablo §4'te CSV için tanımlandı; kanal içe aktarması AYNI
 * raporu, AYNI ekranı ve AYNI durum makinesini kullanır.
 *
 * NEDEN AYRI TABLO DEĞİL:
 *   İki tablo olsaydı ekran iki sorgu birleştirir, `status`/`errors`
 *   sözleşmesi iki yerde yaşar ve biri değiştiğinde diğeri sessizce eski
 *   kalırdı. Kullanıcı için ikisi de "bir içe aktarma turu"dur; kaynak
 *   yalnızca bir ayrıntıdır ve o ayrıntı bir KOLONDUR.
 *
 * `payload` NULLABLE OLUYOR:
 *   CSV'de gövde metnin kendisidir; kanal turunda gövde YOKTUR — ürünler
 *   çalışma anında HTTP ile çekilir. Sentinel bir boş dize yazılabilirdi
 *   ama o, "gövdesi boş bir CSV" ile "gövdesi olmayan bir tur" arasındaki
 *   farkı siler ve ayrıştırıcı boş dizeyi "zorunlu kolon eksik" diye
 *   reddederdi. NULL bu yüzden GERÇEĞİ taşıyan değerdir.
 *
 * `warehouse_id` NOT NULL KALIYOR:
 *   Kanal turunda da açılış stoğunun yazılacağı bir depo gerekir ve o
 *   depo tur başlarken DONAR (CSV'deki gerekçenin aynısı: iş çalışırken
 *   varsayılan depo değişmiş olabilir).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_imports', function (Blueprint $table): void {
            // csv | channel — kaynağı AYIRT EDER. Varsayılan `csv`, çünkü
            // var olan satırların tamamı CSV yüklemesidir.
            $table->string('source')->default('csv');

            // Kanal turunda hangi bağlantıdan çekildiği. CSV'de NULL.
            // `nullOnDelete` DEĞİL `cascadeOnDelete` DEĞİL: bağlantı
            // silinse bile içe aktarma GEÇMİŞİ durmalı — "bu ürünler
            // nereden geldi" sorusu bağlantı silindikten sonra da
            // sorulur. Bu yüzden FK yok; kimlik saklanır.
            $table->uuid('channel_connection_id')->nullable();
        });

        // Woo'da SKU zorunlu olmadığı için atlanan ürünler AYRI sayılır:
        // "47 ürün geldi, 3'ü atlandı" ile "47 ürün geldi" farklı şeylerdir
        // ve ikincisi satıcıya eksiği HİÇ göstermez.
        Schema::table('product_imports', function (Blueprint $table): void {
            $table->integer('skipped_count')->default(0);
        });

        // CSV gövdesi kanal turunda YOKTUR — gerekçe sınıf başlığında.
        DB::statement('ALTER TABLE product_imports ALTER COLUMN payload DROP NOT NULL');
    }

    public function down(): void
    {
        // Geri alırken kanal satırları `payload` NULL taşır ve NOT NULL
        // kısıtı onları reddeder; önce silinirler. Veri kaybı bilinçlidir:
        // bu satırlar zaten yalnızca yeni özelliğin ürettiği kayıtlardır.
        DB::table('product_imports')->where('source', 'channel')->delete();

        DB::statement('ALTER TABLE product_imports ALTER COLUMN payload SET NOT NULL');

        Schema::table('product_imports', function (Blueprint $table): void {
            $table->dropColumn(['source', 'channel_connection_id', 'skipped_count']);
        });
    }
};
