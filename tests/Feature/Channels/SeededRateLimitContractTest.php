<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Contracts\RateLimitProfile;
use App\Domain\Channels\Models\ChannelType;
use Database\Seeders\ChannelTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tohumlanan hız sınırı profili GERÇEKTEN okunuyor mu?
 *
 * V3.0 · §21 · v2.2 §7 · koruma katmanı kuralları.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ BU TEST GERÇEK BİR HATA BULDU (Etsy slice 3.1 · gerçek çalıştırma)
 * ─────────────────────────────────────────────────────────────────────
 * Seeder profili `'requests' => 120` diye yazıyordu; `RateLimitProfile::
 * fromArray()` ise `'requests_per_second'` okuyor. Ad uyuşmadığı için
 * `??` varsayılanı devreye giriyor ve BEŞ KANALIN BEŞİ DE sessizce
 * **5 istek/sn**'ye düşüyordu — Woo'nun 120'si, Shopify'ın 50'si ve
 * Hepsiburada'nın 10'u HİÇ uygulanmıyordu.
 *
 * SESSİZDİ çünkü:
 *   1. `burst_capacity` adı DOĞRUYDU ve okunuyordu — profil "kısmen
 *      çalışıyor" göründü.
 *   2. TÜM davranış testleri profili ELLE kuruyor ve doğru adı
 *      kullanıyor; seeder'ın yazdığı biçimi hiçbir test okumuyordu.
 *   3. Sonucu YAVAŞLIKTIR, yanlış veri değil: senkron çalışır, yalnızca
 *      olması gerekenden ~24 kat yavaş akar. Hiçbir alarm çalmaz.
 *
 * Bu, "yazan ve okuyan aynı yardımcıyı çağırmalı" kuralının TERSİ
 * biçimidir: burada yazan (seeder) ve okuyan (`fromArray`) FARKLI adlar
 * kullanıyordu ve aradaki köprüyü kimse sınamıyordu.
 *
 * ⚠️ TEST TOHUMLANMIŞ SATIRI OKUR, elle kurulmuş bir dizi DEĞİL. Elle
 * kurulsaydı tam olarak kaçırılan şeyi yeniden kaçırırdı.
 */
final class SeededRateLimitContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Her tohumlanan kanalın profili `fromArray()` ile GERÇEKTEN okunur.
     *
     * Beklenen değerler §21'in tablosundan gelir. Varsayılana (5) düşen
     * her kanal burada yakalanır.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function seededChannels(): array
    {
        return [
            // §21 tablosu — kanal başına beklenen İSTEK/SANİYE.
            //
            // ⚠️ WOO §21'DE "istek/dk"DIR: 120/dk = 2/sn. Kova SANİYELİK
            // yenilenir ve `window_seconds` alanını HİÇ OKUMAZ; dönüşüm
            // bu yüzden seeder'da yapılır. 120 yazılsaydı Woo saniyede
            // 120 isteğe izin verilir ve satıcının kendi sunucusu
            // çökerdi ("sınır sunucuya bağlıdır").
            'woocommerce' => ['woocommerce', 2],

            // Trendyol'un TABAN profili tutucudur: gerçek sınır satıcı
            // seviyesine göre değişir ve yanıt başlığından ÖĞRENİLİR.
            'trendyol' => ['trendyol', 1],

            'hepsiburada' => ['hepsiburada', 10],
            'shopify' => ['shopify', 50],
            'etsy' => ['etsy', 10],
        ];
    }

    #[Test]
    #[DataProvider('seededChannels')]
    public function the_seeded_profile_is_actually_read(string $code, int $expected): void
    {
        $this->seed(ChannelTypeSeeder::class);

        $profile = $this->asSystem(
            fn () => ChannelType::query()->where('code', $code)->value('rate_limit_profile')
        );

        $this->assertIsArray($profile, "{$code} için hız sınırı profili tohumlanmamış.");

        $this->assertSame(
            $expected,
            RateLimitProfile::fromArray($profile)->requestsPerSecond,
            "{$code} kanalının tohumlanan hız sınırı OKUNAMIYOR — anahtar adı "
            .'uyuşmuyor ve profil sessizce varsayılana düşüyor. Senkron çalışır '
            .'ama olması gerekenden kat kat yavaş akar ve hiçbir alarm çalmaz.',
        );
    }

    /**
     * ⚠️ SÖZLEŞME ÇİFT YÖNLÜDÜR: `toArray()` çıktısı `fromArray()` ile
     * geri okunabilmelidir.
     *
     * Trendyol öğrendiği sınırı `toArray()` biçiminde bağlantıya YAZAR
     * (`learned_rate_limit`) ve sonraki turda geri okur. İki taraf
     * ayrışsaydı öğrenilen sınır her turda kaybolur ve kanal sonsuza
     * kadar varsayılanla konuşurdu.
     */
    #[Test]
    public function the_profile_survives_a_round_trip(): void
    {
        $original = new RateLimitProfile(requestsPerSecond: 42, burstCapacity: 84);

        $restored = RateLimitProfile::fromArray($original->toArray());

        $this->assertSame(42, $restored->requestsPerSecond);
        $this->assertSame(84, $restored->burstCapacity);
    }
}
