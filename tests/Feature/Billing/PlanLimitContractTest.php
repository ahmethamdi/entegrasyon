<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Domain\Billing\Actions\EnforceQuota;
use App\Domain\Billing\Enums\QuotaMetric;
use App\Domain\Billing\Models\Plan;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `plans.limits` anahtarları KALICI VERİYE yazılan bir SÖZLEŞMEDİR.
 *
 * Mimari Karar Dokümanı v2.2 · §4 · plans.limits (JSONB).
 *
 * BU TESTİN VARLIK SEBEBİ — YAZAN VE OKUYAN AYNI YARDIMCIYI ÇAĞIRIYOR:
 *   Seed `QuotaMetric::PRODUCTS->value` ile yazıyor, `Plan::limitFor()`
 *   aynı enum ile okuyor. Enum değeri değiştirilirse İKİSİ BİRLİKTE
 *   kayar ve davranış testlerinin HEPSİ yeşil kalır — ama üretimdeki
 *   `plans` satırları ESKİ anahtarı taşımaya devam eder, yeni kod onları
 *   BULAMAZ, limit "tanımsız" sayılır ve SINIRSIZA döner. Yani kota
 *   sessizce KALKAR ve bunu hiçbir test görmez.
 *
 *   Bu projede AYNI tuzak iki kez yaşandı (`MetricScope` önekleri ve
 *   `AlertKey` biçimi) ve ikisinde de mutasyon ancak BEKLENEN METİNLE
 *   sınayan bir sözleşme testi eklendikten sonra yakalandı. Bu test o
 *   dersin üçüncü uygulamasıdır.
 */
final class PlanLimitContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ANAHTARLAR BEKLENEN METİNLE SINANIR — enum'a sorularak DEĞİL.
     *
     * `QuotaMetric::PRODUCTS->value` ile karşılaştırmak bu testi
     * anlamsız kılardı: mutasyon her iki tarafı da değiştirir.
     */
    #[Test]
    public function the_limit_keys_are_a_frozen_contract(): void
    {
        $this->assertSame('products', QuotaMetric::PRODUCTS->value);
        $this->assertSame('channels', QuotaMetric::CHANNELS->value);
    }

    /** Varsayılan plan kodu da sözleşmedir — aboneliksiz kiracı ona düşer. */
    #[Test]
    public function the_default_plan_code_is_a_frozen_contract(): void
    {
        $this->assertSame('free', EnforceQuota::DEFAULT_PLAN_CODE);
    }

    /**
     * SEED GERÇEKTEN O ANAHTARLARLA YAZIYOR.
     *
     * Ham JSON okunur: `limitFor()` üzerinden bakmak, okuma tarafındaki
     * bir hatayı maskeler.
     */
    #[Test]
    public function the_seeder_writes_the_contracted_keys(): void
    {
        (new PlanSeeder)->run();

        // `DB::table()` KULLANILIR: Eloquent'in `array` cast'i kolonu
        // zaten çözer ve yazma tarafındaki anahtarı okuma tarafının
        // yorumuyla maskelerdi. Ham JSON metni okunur.
        $raw = json_decode(
            (string) DB::table('plans')->where('code', 'starter')->value('limits'),
            associative: true,
        );

        $this->assertArrayHasKey('products', $raw, 'Seed `products` anahtarını yazmalı.');
        $this->assertArrayHasKey('channels', $raw, 'Seed `channels` anahtarını yazmalı.');
        $this->assertSame(500, $raw['products']);
        $this->assertSame(2, $raw['channels']);
    }

    /** Varsayılan plan seed'de VARDIR — yoksa kota hiç uygulanmaz. */
    #[Test]
    public function the_seeder_creates_the_default_plan(): void
    {
        (new PlanSeeder)->run();

        $free = Plan::query()->find(EnforceQuota::DEFAULT_PLAN_CODE);

        $this->assertNotNull($free, 'Varsayılan plan seed edilmeli.');
        $this->assertSame(25, $free->limitFor(QuotaMetric::PRODUCTS));
        $this->assertSame(1, $free->limitFor(QuotaMetric::CHANNELS));
    }

    /** Seed iki kez koşabilir — `updateOrCreate`, tekillik ihlali vermez. */
    #[Test]
    public function the_seeder_is_idempotent(): void
    {
        (new PlanSeeder)->run();
        (new PlanSeeder)->run();

        $this->assertSame(4, Plan::query()->count());
    }

    /** Sınırsız plan `null` taşır ve SINIRSIZ olarak okunur. */
    #[Test]
    public function the_unlimited_plan_reads_as_unlimited(): void
    {
        (new PlanSeeder)->run();

        $business = Plan::query()->find('business');

        $this->assertNull($business->limitFor(QuotaMetric::PRODUCTS));
        $this->assertNull($business->limitFor(QuotaMetric::CHANNELS));
    }
}
