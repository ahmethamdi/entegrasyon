<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Billing\Enums\QuotaMetric;
use App\Domain\Billing\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Plan kataloğu — §4: "Kiracısız, seed".
 *
 * Mimari Karar Dokümanı v2.2 · §4 · plans, §13 · Faz 4.
 *
 * FİYATLAR VE LİMİTLER TİCARİ BİR KARARDIR, mimari bir karar değil.
 * Doküman plan kademelerini TANIMLAMAZ — yalnızca tabloyu tanımlar.
 * Buradaki değerler MAKUL BAŞLANGIÇ değerleridir ve satıcı geri
 * bildirimiyle değişmesi BEKLENİR; değiştirmek yalnızca bu dosyayı ve
 * `plans` satırlarını etkiler, KOD DEĞİŞMEZ.
 *
 * DEĞİŞMEZ KURAL — `updateOrCreate` KULLANILIR, `create` DEĞİL. Seed
 * birden çok kez koşar (yeni ortam, CI, deploy) ve `create` ikinci
 * turda tekillik ihlali verirdi. Fiyat güncellemesi de böyle yayılır.
 *
 * DEĞİŞMEZ KURAL — `free` PLANI SİLİNMEZ ve KODU SABİTTİR
 * (`EnforceQuota::DEFAULT_PLAN_CODE`). Aboneliği olmayan her kiracı ona
 * düşer; kaldırılsaydı o kiracılarda kota HİÇ uygulanmazdı.
 *
 * LİMİT ANAHTARLARI `QuotaMetric` ENUM'INDAN GELİR, elle yazılmaz:
 * anahtar kalıcı veriye (JSONB) yazılan bir SÖZLEŞMEDİR ve iki yerde
 * elle yazılsaydı biri değiştiğinde limit sessizce "tanımsız" olur,
 * yani SINIRSIZA döner ve kota fark edilmeden kalkardı.
 */
final class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $products = QuotaMetric::PRODUCTS->value;
        $channels = QuotaMetric::CHANNELS->value;

        $plans = [
            [
                'code' => 'free',
                'name' => 'Ücretsiz',
                'price_monthly' => 0,
                // Denemek için yeterli, üretim için değil: satıcı ürünü
                // gerçekten kanala gönderip akışı görebilmeli.
                'limits' => [$products => 25, $channels => 1],
                'is_public' => true,
            ],
            [
                'code' => 'starter',
                'name' => 'Başlangıç',
                'price_monthly' => 499,
                'limits' => [$products => 500, $channels => 2],
                'is_public' => true,
            ],
            [
                'code' => 'pro',
                'name' => 'Profesyonel',
                'price_monthly' => 1499,
                'limits' => [$products => 5000, $channels => 5],
                'is_public' => true,
            ],
            [
                'code' => 'business',
                'name' => 'Kurumsal',
                'price_monthly' => 3999,
                // Limit YOK = SINIRSIZ. `null` yerine anahtarı hiç
                // yazmamak da aynı anlama gelir; burada NİYET açık
                // olsun diye açıkça null yazılıyor.
                'limits' => [$products => null, $channels => null],
                'is_public' => true,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::query()->updateOrCreate(
                ['code' => $plan['code']],
                [
                    'name' => $plan['name'],
                    'price_monthly' => $plan['price_monthly'],
                    'currency' => 'TRY',
                    'limits' => $plan['limits'],
                    'is_public' => $plan['is_public'],
                ],
            );
        }
    }
}
