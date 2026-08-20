<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mimari Karar Dokümanı v2.2 · §4 · Billing ve denetim, §3 · Domain/Billing,
 * §13 · Faz 4 · "Planlar, abonelik, kota, ödeme entegrasyonu — 26 sa".
 *
 * §4 tabloları birebir şöyle tanımlar:
 *   plans          · code (PK), name, price_monthly, currency, limits (JSONB),
 *                    is_public · KİRACISIZ, seed
 *   subscriptions  · id, tenant_id, plan_code, status, started_at,
 *                    current_period_end, cancelled_at, external_ref
 *                  · UNIQUE(tenant_id) WHERE status = 'active'
 *                  · INDEX(current_period_end)
 *
 * `usage_records` BU TURDA YAZILMADI ve bu bilinçlidir: kotalar ANLIK
 * sayımdır (ürün sayısı, kanal sayısı) ve dönemsel kullanım biriktirmez.
 * O tablo §4'te dönemsel ölçüm için tanımlı ("fiyatlandırma verisi geriye
 * dönük üretilemez") ve sipariş/senkron başına ücretlendirmeye geçilirse
 * yazılır. Şimdi yazmak, hiçbir yerden yazılmayan boş bir tablo bırakırdı.
 *
 * DEĞİŞMEZ KURAL — `plans` KİRACIYA AİT DEĞİLDİR (§4 açıkça: "Kiracısız,
 * seed"). Plan kataloğu ÜRÜNÜN gerçeğidir, satıcının kararı değil; kiracı
 * başına kopyalansaydı fiyat değişikliği her kiracı için ayrı yazılmak
 * zorunda kalır ve biri unutulunca aynı planın iki fiyatı olurdu.
 * `metric_snapshots` ve `channel_categories` ile aynı gerekçe.
 *
 * DEĞİŞMEZ KURAL — `code` DOĞAL ANAHTARDIR, uuid DEĞİL (§4: "code (PK)").
 * `tenants.plan_code` zaten metin taşıyor ve o kolon 15 Ağustos'tan beri
 * yerinde. Uuid seçilseydi plan kimliği okunamaz olur ve seed'i sürümlemek
 * zorlaşırdı — plan sayısı ONLARCA değil, BİRKAÇ tanedir.
 *
 * DEĞİŞMEZ KURAL — FİYAT `decimal(12,2)`, float DEĞİL. Para float taşımaz;
 * yuvarlama kuruş kayması üretir. Projede `variants.price` de böyledir ve
 * fiyat senkron kuralı karşılaştırmayı KURUŞ ölçeğinde tam sayı üzerinden
 * yapar.
 *
 * DEĞİŞMEZ KURAL — `UNIQUE(tenant_id) WHERE status = 'active'` KISMİ
 * TEKİLLİKTİR. Bir kiracının aynı anda İKİ aktif aboneliği olamaz; iptal
 * edilmiş ve süresi dolmuş abonelikler SİLİNMEZ ve tarihçe olarak durur
 * ("geçen yıl hangi plandaydı" sorusu faturalamada sorulur). Tam tekillik
 * konsaydı plan değiştiren kiracının eski aboneliği silinmek zorunda
 * kalır ve gelir geçmişi kaybolurdu. Depo kuralıyla aynı kalıp (§5).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ───────────────────────────────────────────────────────── plans

        Schema::create('plans', function (Blueprint $table): void {
            // §4: code (PK). Doğal anahtar — `free`, `starter`, `pro`.
            $table->string('code')->primary();

            $table->string('name');

            // Aylık fiyat. `decimal` — para float taşımaz.
            $table->decimal('price_monthly', 12, 2)->default(0);

            $table->string('currency', 3)->default('TRY');

            // Kota tanımları. JSONB çünkü kota TÜRLERİ zamanla değişir
            // (bugün ürün+kanal, yarın sipariş) ve her yeni kota için
            // migration yazmak plan kataloğunu şemaya kilitlerdi.
            // Okuma `PlanLimits` üzerinden TEK KAYNAKTAN yapılır.
            $table->jsonb('limits')->default(DB::raw("'{}'::jsonb"));

            // Panelde/fiyat sayfasında gösterilir mi? Eski planlar
            // (grandfathered) veya özel anlaşmalar `false` taşır ve
            // SİLİNMEZ: o plandaki kiracıların aboneliği ona bağlıdır.
            $table->boolean('is_public')->default(true);

            $table->timestamps();
        });

        // ─────────────────────────────────────────────────── subscriptions

        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->uuid('tenant_id');

            $table->string('plan_code');

            // active · trialing · past_due · cancelled · expired
            $table->string('status');

            $table->timestamp('started_at')->nullable();

            // Dönem sonu — yenileme ve süre dolumu buradan okunur.
            $table->timestamp('current_period_end')->nullable();

            $table->timestamp('cancelled_at')->nullable();

            // §4 · external_ref: ÖDEME SAĞLAYICISININ kimliği
            // (Stripe'ta `sub_...`). Sağlayıcı adı KOLONA GÖMÜLMEZ —
            // alan §4'te sağlayıcıdan bağımsız adlandırılmıştır ve
            // sağlayıcı değişirse şema değişmemelidir.
            //
            // TEKİL: aynı uzak abonelik iki yerel satıra bağlanamaz.
            // Webhook tekrarı (Stripe olayları EN AZ BİR KEZ gönderir)
            // aksi halde ikinci bir abonelik satırı yaratırdı.
            $table->string('external_ref')->nullable();

            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')
                ->cascadeOnDelete();

            // Plan silinemez — abonelik ona bağlıdır.
            $table->foreign('plan_code')->references('code')->on('plans')
                ->restrictOnDelete();

            // §4: INDEX(current_period_end) — "süresi dolanlar" taraması.
            $table->index('current_period_end');

            $table->index(['tenant_id', 'status']);
        });

        // §4'ün kısmi tekilliği: kiracı başına EN FAZLA BİR aktif abonelik.
        // `trialing` de aktif sayılır — deneme süresindeki kiracı ikinci
        // bir abonelik açamamalı, yoksa iki kez ücretlendirilirdi.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX subscriptions_one_active_per_tenant
                ON subscriptions (tenant_id)
                WHERE status IN ('active', 'trialing')
        SQL);

        // Uzak abonelik kimliği tekildir — webhook tekrarına karşı çıpa.
        // KISMİ: `external_ref` NULL olabilir (elle açılan/ücretsiz plan)
        // ve birden çok NULL tekilliği ihlal etmemeli.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX subscriptions_external_ref_unique
                ON subscriptions (external_ref)
                WHERE external_ref IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS subscriptions_external_ref_unique');
        DB::statement('DROP INDEX IF EXISTS subscriptions_one_active_per_tenant');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
