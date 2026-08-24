<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kanal fiyat override'ı — SATICININ "KANALINKİNİ KABUL EDİYORUM" KARARI.
 *
 * Mimari Karar Dokümanı v2.2 · §9 (çakışma tespiti ve domain politikası),
 * §3 (`Pricing/Models/PriceOverride`), §11 (denetim kaydı).
 *
 * ŞEMA §4'TE TANIMLI DEĞİLDİR: doküman modeli klasör ağacında adıyla
 * anıyor ama tablo tanımını vermiyor. Tanım burada, §9'un davranış
 * tarifinden türetildi ve bu bilinçli bir tamamlamadır.
 *
 * ─────────────────────────────────────────────────────────────────────
 * NEDEN BU TABLO VAR — §9'UN PRICE POLİTİKASI
 * ─────────────────────────────────────────────────────────────────────
 * §9 domain başına çakışma politikası tanımlar ve PRICE'ı diğerlerinden
 * AYIRIR:
 *
 *   INVENTORY → "Sessizce üzerine yaz. Rozet yok."
 *   PRICE     → "ÜZERİNE YAZMA. Çakışma rozeti. Kullanıcı seçer.
 *                Kabul edilirse price_override olarak kaydedilir."
 *
 * Gerekçe dokümanda yazılı: **satıcılar kanal panelinden kampanya
 * yapıyor ve sessizce ezmek en sık şikayet.** Stokta tek otorite biziz;
 * fiyatta DEĞİLİZ.
 *
 * Bu tablo o "kabul" kararının kalıcı hâlidir: satır varsa o listing
 * fiyat fan-out'undan ELENİR ve kanonik fiyat ona gönderilmez.
 *
 * ─────────────────────────────────────────────────────────────────────
 * LISTING BAŞINA TEK SATIR
 * ─────────────────────────────────────────────────────────────────────
 * `UNIQUE(listing_id)` — aynı listing için iki farklı override anlamsız.
 * Yeni bir çakışma kabul edilirse satır GÜNCELLENİR; tarihçe
 * `audit_logs` tarafında yaşar (§11'in "fiyat çakışması kararı" olayı).
 *
 * Tekillik `tenant_id` içermez ve buna GEREK YOKTUR: `listings.id`
 * zaten global tekildir ve FK onu bir kiracıya bağlar. `tenant_id`
 * kolonu yine de tutulur — `BelongsToTenant` scope'u ve kiracı bazlı
 * sorgular için (`metric_snapshots`'ın aksine bu tablo KİRACIYA AİTTİR:
 * karar satıcınındır).
 *
 * ─────────────────────────────────────────────────────────────────────
 * İKİ FİYAT BİRDEN SAKLANIR
 * ─────────────────────────────────────────────────────────────────────
 * `channel_price` kabul edilen kanal fiyatı, `our_price` KARAR ANINDAKİ
 * kanonik fiyatımız. İkincisi olmadan "satıcı neyi neye tercih etti"
 * sorusu cevaplanamaz — kanonik fiyat sonradan değişir ve karar bağlamı
 * kaybolurdu.
 *
 * ÖNEMLİ SONUÇ: kanonik fiyat DEĞİŞİRSE override BAYATLAR. O durumu
 * `ResolveChannelPrice` yorumlar; şema burada karar vermez, yalnızca
 * ikisini de saklar.
 *
 * ─────────────────────────────────────────────────────────────────────
 * `expires_at` NULLABLE — SÜRESİZ KABUL MEŞRUDUR
 * ─────────────────────────────────────────────────────────────────────
 * Satıcı kampanyanın bitiş tarihini biliyorsa yazar; bilmiyorsa NULL
 * kalır ve override elle kaldırılana kadar sürer. Zorunlu olsaydı
 * satıcı uydurma bir tarih girmek zorunda kalırdı ve o tarih geldiğinde
 * fiyat habersizce ezilirdi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_overrides', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('listing_id');

            // Kabul edilen KANAL fiyatı — artık o listing için geçerli olan.
            $table->decimal('channel_price', 12, 2);

            // Karar anındaki KANONİK fiyatımız — gerekçe sınıf başlığında.
            $table->decimal('our_price', 12, 2);

            $table->timestampTz('accepted_at');

            // Kim kabul etti. Kullanıcı silinirse kayıt DURUR: karar izi,
            // kararı veren hesaptan uzun yaşamalıdır (`audit_logs` ile
            // aynı gerekçe).
            $table->uuid('accepted_by')->nullable();

            // Süresiz kabul meşrudur — gerekçe sınıf başlığında.
            $table->timestampTz('expires_at')->nullable();

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('listing_id')->references('id')->on('listings')->cascadeOnDelete();
            $table->foreign('accepted_by')->references('id')->on('users')->nullOnDelete();

            // LISTING BAŞINA TEK OVERRIDE — gerekçe sınıf başlığında.
            $table->unique('listing_id', 'price_overrides_listing_unique');

            // Fiyat fan-out'u "bu listing elenecek mi" sorusunu kiracı
            // kapsamında sorar; süresi dolmuşları ayıklamak da aynı indeksi
            // kullanır.
            $table->index(['tenant_id', 'expires_at'], 'price_overrides_tenant_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_overrides');
    }
};
