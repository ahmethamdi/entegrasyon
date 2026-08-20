<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gönderilmiş uyarı bildirimleri — TEKRAR GÖNDERİMİ ÖNLEYEN ÇIPA.
 *
 * Mimari Karar Dokümanı v2.2 · §11 ("eşik aşımında e-posta"), §12
 * ("günlük özet: kiracı başına 10'dan fazla ölü iş → e-posta").
 *
 * ─────────────────────────────────────────────────────────────────────
 * NEDEN BU TABLO VAR — SESSİZLİK BİLDİRİMİN PARÇASIDIR
 * ─────────────────────────────────────────────────────────────────────
 * Eşik aşımı KALICI bir durumdur: fazla satış düzeltilene kadar her
 * turda yeniden ölçülür. Kayıt tutulmasaydı aynı uyarı tur tur yeniden
 * gönderilir, satıcının gelen kutusu dolar ve İNSANLAR UYARILARI
 * OKUMAYI BIRAKIR. O noktadan sonra bildirim sistemi yalnızca gürültü
 * üretir ve GERÇEK bir olay geldiğinde de fark edilmez.
 *
 * Bu, devre kesicinin "AUTHENTICATION hatasında süresiz aç" kararıyla
 * aynı aileden: tekrar eden bir durumu tekrar eden bir eylemle
 * karşılamak çözmez, yalnızca maliyeti çoğaltır.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ÇIPA (kapsam + uyarı türü + GÜN)
 * ─────────────────────────────────────────────────────────────────────
 * `UNIQUE(alert_key, sent_on)` — aynı uyarı aynı GÜN içinde yalnızca
 * bir kez gider. Gün sınırı bilinçlidir: §12 "GÜNLÜK özet" diyor.
 * Saat bazlı olsaydı sürekli aşan bir eşik günde 24 e-posta üretirdi;
 * hiç sınır olmasaydı sorun çözülene kadar SONSUZA kadar susulurdu ve
 * satıcı ertesi gün durumu hatırlamazdı.
 *
 * `sent_on` DATE'tir, timestamp DEĞİL: tekillik "aynı gün" sorusunu
 * cevaplamalı ve timestamp saniye taşıdığı için iki gönderim asla
 * çakışmazdı — kısıt hiçbir şey korumazdı.
 *
 * KİRACISIZ DEĞİLDİR ama `tenant_id` NULLABLE'dır: sistem geneli
 * yönetici uyarısının kiracısı YOKTUR ve ona uydurma bir kiracı yazmak
 * `metric_snapshots`'ta reddedilen çözümün aynısı olurdu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Sistem geneli uyarıda NULL — gerekçe sınıf başlığında.
            $table->uuid('tenant_id')->nullable();

            // Uyarının kimliği: "metric:dead_operations:tenant:{id}" gibi.
            // Kapsamı ve metriği İÇİNDE taşır; ayrı kolonlara bölünseydi
            // sistem uyarısı için o kolonların bir kısmı boş kalırdı ve
            // tekillik kısıtı NULL'lar yüzünden hiçbir şey korumazdı
            // (PostgreSQL'de NULL'lar birbirine eşit sayılmaz).
            $table->string('alert_key');

            // Hangi kanaldan gitti — bugün yalnızca `mail`.
            $table->string('channel')->default('mail');

            // Kaç alıcıya gitti; sıfır "alıcı bulunamadı" demektir ve bu
            // BAŞARISIZLIK DEĞİL bilinçli bir durumdur (yönetici adresi
            // tanımsız olabilir) — ama kayıtta görünmesi gerekir.
            $table->integer('recipient_count')->default(0);

            // Uyarıyı tetikleyen değer ve eşik: "neden gönderildi"
            // sorusu e-postanın kendisine bakmadan cevaplanabilmeli.
            $table->decimal('observed_value', 20, 4)->nullable();
            $table->decimal('threshold_value', 20, 4)->nullable();

            $table->date('sent_on');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            // AYNI UYARI AYNI GÜN İKİ KEZ GİTMEZ — tablonun varlık sebebi.
            $table->unique(['alert_key', 'sent_on'], 'alert_deliveries_key_day_uniq');

            // Ekran/denetim: kiracı başına en yeniyi önce.
            $table->index(['tenant_id', 'sent_on'], 'alert_deliveries_tenant_day_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_deliveries');
    }
};
