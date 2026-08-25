<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Models\ChannelType;
use Database\Seeders\ChannelTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Seeder elle açılmış kanalı KAPATMAZ.
 *
 * V3.0 · §16 · DB Delta 4 · P1-3 · T-V3-23.
 *
 * BU BİR HATA DÜZELTMESİDİR VE KANITI VAR: `356a662`'de
 * `db:seed --class=ChannelTypeSeeder` çalıştırıldığında **Trendyol
 * kapandı** ve elle SQL ile geri açıldı. Sebep `updateOrCreate`'in
 * güncelleme kümesinde `is_active` bulunmasıydı — seeder her koşuşta
 * kanalın operasyonel durumunu tohum değerine geri sarıyordu.
 *
 * V3'te bu tuzak ALTI KANALDA ALTI KEZ ısırır: §05'in 12 adımlı
 * listesi kanalı `is_active = false` doğurur (adım 1) ve gerçek hesapla
 * sağlık kontrolü geçtikten SONRA elle açar (adım 12). Adım 12'yi
 * geri alan bir seeder, o listenin tamamını anlamsız kılar.
 *
 * NEDEN AYRI BİR TEST GEREKİYOR: seeder'ın kendisi hatasız çalışır ve
 * diğer bütün alanları doğru yazar. Kod incelemesinde kusursuz görünür;
 * yalnızca "dün açtığım kanal bu sabah neden kapalı" sorusuyla anlaşılır.
 *
 * `is_active` DIŞINDAKİ ALANLAR GÜNCELLENMEYE DEVAM EDER — seeder
 * tanımların tek kaynağıdır ve bir hız sınırı düzeltmesi ya da yeni
 * yetenek bayrağı `db:seed` ile yayılabilmelidir. Korunan tek şey
 * kanalın AÇIK/KAPALI durumudur, çünkü o karar seeder'ın değil
 * operatörün kararıdır.
 */
final class ChannelTypeSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Elle açılan kanal, seeder yeniden koşunca AÇIK KALIR.
     *
     * Kanıtlanan tuzağın birebir yeniden üretimi: Trendyol tohumda
     * kapalı doğar, operatör onu açar, seeder yeniden koşar.
     */
    #[Test]
    public function seeder_does_not_close_a_manually_activated_channel(): void
    {
        $this->seed(ChannelTypeSeeder::class);

        // §05 · adım 12 — gerçek hesapla sağlık kontrolü geçti, kanal açıldı.
        ChannelType::query()->where('code', 'trendyol')->update(['is_active' => true]);

        // Bir hafta sonra başka bir sebeple deploy: db:seed yeniden koşar.
        $this->seed(ChannelTypeSeeder::class);

        $this->assertTrue(
            (bool) ChannelType::query()->where('code', 'trendyol')->value('is_active'),
            'Seeder elle açılmış kanalı kapattı — `356a662`\'de yaşanan hata geri geldi.',
        );
    }

    /**
     * Elle KAPATILAN kanal da açılmaz — koruma iki yönlüdür.
     *
     * Tek yönlü olsaydı, sorun çıktığı için acilen kapatılan bir kanal
     * (§26 · geri alma tablosu: "kanal yanlış davranıyor → is_active =
     * false") ilk `db:seed` ile sessizce geri açılırdı.
     */
    #[Test]
    public function seeder_does_not_reopen_a_manually_closed_channel(): void
    {
        $this->seed(ChannelTypeSeeder::class);

        // WooCommerce tohumda AÇIK doğar; operatör onu acilen kapatıyor.
        ChannelType::query()->where('code', 'woocommerce')->update(['is_active' => false]);

        $this->seed(ChannelTypeSeeder::class);

        $this->assertFalse(
            (bool) ChannelType::query()->where('code', 'woocommerce')->value('is_active'),
            'Seeder acilen kapatılmış kanalı geri açtı.',
        );
    }

    /**
     * YENİ kanal tohum değeriyle doğar.
     *
     * Koruma yalnızca VAR OLAN satır içindir. Yaratılışta `is_active`
     * yazılmasaydı kolon varsayılanına düşerdi ve §05'in "kanal KAPALI
     * doğar" kuralı kolon varsayılanının insafına kalırdı.
     */
    #[Test]
    public function newly_seeded_channels_are_born_closed(): void
    {
        $this->seed(ChannelTypeSeeder::class);

        // §05 · adım 1 — doğrulanmamış kanal panelde GÖRÜNMEZ.
        $this->assertFalse(
            (bool) ChannelType::query()->where('code', 'hepsiburada')->value('is_active'),
            'Hepsiburada kapalı doğmalıydı — uç noktaları DOĞRULANMADI.',
        );
    }

    /**
     * `is_active` DIŞINDAKİ alanlar güncellenmeye devam eder.
     *
     * Koruma "satır varsa hiç dokunma"ya dönüşseydi seeder tanımların
     * tek kaynağı olmaktan çıkardı: bir hız sınırı düzeltmesi veya yeni
     * yetenek bayrağı üretime ASLA ulaşmaz, kod ile veritabanı sessizce
     * ayrışırdı.
     */
    #[Test]
    public function seeder_still_updates_every_other_field(): void
    {
        $this->seed(ChannelTypeSeeder::class);

        ChannelType::query()->where('code', 'woocommerce')->update([
            'name' => 'BOZUK AD',
            'supports_webhooks' => false,
        ]);

        $this->seed(ChannelTypeSeeder::class);

        $row = ChannelType::query()->where('code', 'woocommerce')->firstOrFail();

        $this->assertSame('WooCommerce', $row->name);
        $this->assertTrue((bool) $row->supports_webhooks);
    }
}
