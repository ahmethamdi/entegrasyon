<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Denetim kaydı — ANLAŞMAZLIK ÇIKTIĞINDA SORULAN ALTI SORU.
 *
 * Mimari Karar Dokümanı v2.2 · §4 (şema) · §11 ("Denetim kaydı").
 *
 * ─────────────────────────────────────────────────────────────────────
 * DAR KAPSAM BİLİNÇLİDİR — "HER SATIR DEĞİŞİKLİĞİ" DEĞİL
 * ─────────────────────────────────────────────────────────────────────
 * §11 açıkça diyor: "Her satır değişikliğini kaydetmek gereksiz; bu altı
 * olay anlaşmazlık çıktığında sorulan sorular." Genel bir model-observer
 * yazıp her `saved` olayını kaydetmek İKİ zarar verir: (a) tablo stok
 * hareketleriyle dolar ve gerçek denetim sinyali gürültüde kaybolur,
 * (b) `changes` kolonuna maskelenmemiş veri sızma yüzeyi her modele
 * yayılır — oysa maskeleme yalnızca ALTI çağrı yerinde denetlenebilir.
 *
 * Bu, `metric_snapshots`'ın "sıfır olan kiracı için satır yazılmaz"
 * kuralıyla aynı aileden: kayıt tutmanın değeri SEÇİCİLİĞİNDEN gelir.
 *
 * ─────────────────────────────────────────────────────────────────────
 * `changes` MASKELİDİR — ŞEMA DEĞİL, ÇAĞRI YERİ GARANTİ EDER
 * ─────────────────────────────────────────────────────────────────────
 * §4 kolonu "changes (JSONB, maskeli)" olarak tanımlar. Maskelemeyi
 * yapan `RecordAuditLog`'dur ve `PayloadRedactor`'ün İKİ katmanını da
 * çalıştırır: kimlik bilgisi güncellemesi kaydedilirken sırrın kendisi
 * bu tabloya düşerse kasa şifrelemesinin tüm anlamı kaybolur — sır
 * şifresiz bir jsonb kolonunda düz metin olarak durur ve panele giden
 * her denetim ekranı onu taşır.
 *
 * ─────────────────────────────────────────────────────────────────────
 * `user_id` NULLABLE — AKTÖR HER ZAMAN İNSAN DEĞİLDİR
 * ─────────────────────────────────────────────────────────────────────
 * Zamanlanmış tarama (`runAsSystem`) veya kuyruk işi de denetlenebilir
 * bir olay üretebilir. NOT NULL olsaydı o yollar ya uydurma bir
 * kullanıcı yazmak ya da kaydı hiç yazmamak zorunda kalırdı; ikisi de
 * denetim izini YALAN söyletir. Kullanıcı silinirse kayıt DURUR
 * (`nullOnDelete`) — denetim izi, denetlediği hesaptan uzun yaşamalıdır.
 *
 * `ip` de nullable ve aynı gerekçeye dayanır: konsol komutunun IP'si yok.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('tenant_id');

            // Aktör insan olmayabilir — gerekçe sınıf başlığında.
            $table->uuid('user_id')->nullable();

            // "channel.connected" · "stock.adjusted" gibi nokta ayrılmış ad.
            // Enum'a bağlanır (`AuditAction`), ama kolon METİNDİR: eski
            // kayıtlar enum'dan bir değer kaldırılsa bile okunabilir kalmalı
            // ve denetim izi kod refactor'ıyla ölmemelidir.
            $table->string('action');

            // Neye yapıldı: "channel_connection" + uuid.
            $table->string('subject_type');
            $table->uuid('subject_id')->nullable();

            // MASKELİ — gerekçe sınıf başlığında.
            $table->jsonb('changes')->nullable();

            // IPv6 45 karakteri aşmaz; konsol yolunda NULL.
            $table->string('ip', 45)->nullable();

            $table->timestampTz('occurred_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            // §4'ün tanımladığı iki indeks.
            // Birincisi denetim ekranının sorgusu ("bu kiracıda en son ne
            // oldu"), ikincisi tekil nesnenin geçmişi ("bu bağlantıya kim
            // dokundu"). İkisi ayrı sorgu ve ayrı indeks: tek birleşik
            // indeks ikincisinde `tenant_id` öneki yüzünden kullanılamazdı.
            $table->index(['tenant_id', 'occurred_at'], 'audit_logs_tenant_time_idx');
            $table->index(['subject_type', 'subject_id'], 'audit_logs_subject_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
