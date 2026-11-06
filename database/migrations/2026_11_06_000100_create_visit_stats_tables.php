<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ziyaretçi ölçümü (6 Kasım 2026) — çerezsiz, ham IP saklamayan günlük toplam.
 *
 * NEDEN: canlıda erişim kaydı yok; reklam döneminde siteye kaç kişinin
 * geldiğini, hangi sayfaya baktığını ve nereden geldiğini bilmiyorduk.
 * Analitik aracı (çerez + onay bandı + üçüncü taraf) yerine kendi
 * sayacımız: platform düzeyi, kiracısız iki tablo.
 *
 *   page_views_daily — gün × yol × kaynak alan adı başına görüntüleme SAYISI.
 *   visitors_daily   — gün başına tekil ziyaretçi ÖZETİ. `visitor` IP + tarayıcı
 *                      + gün + uygulama anahtarının SHA-256'sının ilk 32
 *                      karakteridir: ertesi gün aynı kişi başka değer üretir
 *                      (günler arası iz sürülmez) ve IP satırda yoktur.
 *
 * Birincil anahtar BİLEŞİKTİR ve sayacın doğruluğu ona dayanır: aynı anda
 * gelen iki istek `ON CONFLICT` ile aynı satırı artırır, ikinci satır
 * açılmaz. 90 günden eskiyi `visits:prune` siler.
 *
 * Yalnız ekleme: eski kod bu tabloları okumaz, geri alma tabloları düşürür.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_views_daily', function (Blueprint $table): void {
            $table->date('day');
            $table->string('path', 255);
            $table->string('referrer_host', 255)->default('');
            $table->integer('views')->default(0);

            $table->primary(['day', 'path', 'referrer_host']);
        });

        Schema::create('visitors_daily', function (Blueprint $table): void {
            $table->date('day');
            $table->char('visitor', 32);

            $table->primary(['day', 'visitor']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitors_daily');
        Schema::dropIfExists('page_views_daily');
    }
};
