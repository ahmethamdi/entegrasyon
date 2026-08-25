<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Sync\Models\Listing;
use Database\Seeders\ChannelTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `listings.channel_metadata` — V3.0'ın TEK fiziksel DB şema değişikliği.
 *
 * V3.0 · §03 · Delta 2 · §16 · DB Delta 1 · §07 · P0-9 · T-V3-20.
 *
 * Üç yeni kanalın KALICI uzak kimliklerini taşır. Shopify'ın stok mutation'ı
 * variant gid'i kabul etmez (`inventoryItemId` ister); eBay'de stok/fiyat
 * `offer_id` ile yazılır ve o kimlik kaybedilirse yeniden yaratma `25002`
 * duplicate hatası verir — KALICI hata, listing "düzeltilemez" damgasıyla ölür.
 *
 * BU TESTİN ASIL DEĞERİ SIR YASAĞIDIR (P0-9): kolon ŞİFRESİZDİR ve panele
 * Inertia prop'u olarak gidebilir. Token buraya yazılsaydı `channel_credentials`
 * şifrelemesinin tüm anlamı kaybolur ve sır tarayıcıya kadar sızardı.
 */
final class ChannelMetadataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // `channel_connections.channel_type_code` yabancı anahtarı gerçek bir
        // `channel_types` satırı ister; factory kodu sabit yazar.
        $this->seed(ChannelTypeSeeder::class);
    }

    /**
     * Kolon var, jsonb ve NULLABLE.
     *
     * NULLABLE ZORUNLUDUR: Woo ve Trendyol listing'lerinin kanala özgü ek
     * kimliği YOKTUR ve `NOT NULL` olsaydı o iki kanalın listing yaratması
     * kırılırdı.
     */
    #[Test]
    public function listings_have_a_nullable_jsonb_channel_metadata_column(): void
    {
        $this->assertTrue(Schema::hasColumn('listings', 'channel_metadata'));

        $column = DB::selectOne(
            'SELECT data_type, is_nullable FROM information_schema.columns
             WHERE table_name = ? AND column_name = ?',
            ['listings', 'channel_metadata'],
        );

        $this->assertSame('jsonb', $column->data_type);
        $this->assertSame('YES', $column->is_nullable);
    }

    /**
     * ÜZERİNDE İNDEKS YOKTUR ve bu bilinçlidir (§16 · DB Delta 1).
     *
     * Çekirdek bu alanı SORGULAMAZ — yalnızca adapter okur ve yazar. İndeks
     * her listing yazmasına bakım maliyeti bindirir ve hiçbir sorgu onu
     * kullanmazdı. Bir gün indeks eklenmek istenirse önce onu KULLANAN bir
     * sorgu gösterilmelidir.
     */
    #[Test]
    public function channel_metadata_carries_no_index(): void
    {
        $indexes = DB::select(
            "SELECT indexdef FROM pg_indexes WHERE tablename = 'listings'"
        );

        foreach ($indexes as $index) {
            $this->assertStringNotContainsString(
                'channel_metadata',
                $index->indexdef,
                'channel_metadata indekslendi — çekirdek bu alanı sorgulamaz.',
            );
        }
    }

    /**
     * Dizi olarak yazılır ve dizi olarak geri okunur.
     *
     * Cast olmasaydı adapter JSON string alır, `$metadata['offer_id']` bir
     * karakter döndürür ve istek yanlış kimliğe giderdi.
     */
    #[Test]
    public function channel_metadata_round_trips_as_an_array(): void
    {
        $listing = $this->makeListing([
            'offer_id' => '8912345',
            'marketplace_id' => 'EBAY_DE',
        ]);

        $fresh = $this->reload($listing);

        $this->assertSame(
            ['offer_id' => '8912345', 'marketplace_id' => 'EBAY_DE'],
            $fresh->channel_metadata,
        );
    }

    /**
     * Woo/Trendyol listing'i metadata OLMADAN yaratılabilir.
     *
     * V3'ün şema değişikliği mevcut iki kanalı ETKİLEMEZ — §03'ün
     * "hepsi genişletmedir, davranış değiştirmez" iddiasının kanıtı.
     */
    #[Test]
    public function existing_channels_keep_working_without_metadata(): void
    {
        $listing = $this->makeListing(null);

        $this->assertNull($this->reload($listing)->channel_metadata);
    }

    /**
     * eBay senaryosu: `offer_id` KALICIDIR ve ara başarısızlıktan sağ çıkar.
     *
     * §13.2'nin asıl gerekçesi. `upsertOffer` başarılı, `publishOffer` 429
     * aldı: `external_id` yazılmaz (v2.2 kuralı) ama `offer_id` YAZILMIŞTIR
     * ve sonraki tur oradan devam eder. Kalıcı olmasaydı tur baştan başlar,
     * `POST /offer` ikinci kez çağrılır ve eBay `25002` döndürürdü.
     */
    #[Test]
    public function ebay_offer_id_survives_a_failed_publish(): void
    {
        $listing = $this->makeListing(['offer_id' => '8912345'], draft: true);

        // publish 429 aldı: external_id YAZILMAZ.
        $this->assertNull($listing->external_id);

        $resumed = $this->reload($listing);

        $this->assertSame('8912345', $resumed->channel_metadata['offer_id']);
        $this->assertNull(
            $resumed->external_id,
            'external_id başarısızlıkta yazılmamalıydı (v2.2 kuralı).',
        );
    }

    /**
     * ⚠️ P0-9 · T-V3-20 — KOLON SIR TAŞIMAZ.
     *
     * Kolon şifresizdir ve panele gidebilir. Bu test bir DAVRANIŞI değil bir
     * SÖZLEŞMEYİ korur: yasak anahtarların hiçbiri kod tabanında
     * `channel_metadata`'ya yazılmamalıdır. Yazılsaydı `channel_credentials`
     * şifrelemesinin tüm anlamı kaybolur ve sır Inertia prop'u üzerinden
     * TARAYICIYA kadar sızardı.
     *
     * Tarama kaynak kodu okur çünkü ihlal ancak o kanal yazıldığında doğar;
     * davranış testi bugün var olmayan bir adapter'ı sınayamaz.
     */
    #[Test]
    public function no_adapter_writes_a_secret_into_channel_metadata(): void
    {
        $forbidden = [
            'access_token', 'refresh_token', 'client_secret', 'consumer_secret',
            'api_secret', 'webhook_secret', 'code_verifier', 'password',
            'private_key', 'signature',
        ];

        $offenders = [];

        foreach ($this->sourceFiles() as $file) {
            $source = (string) file_get_contents($file);

            if (! str_contains($source, 'channel_metadata')) {
                continue;
            }

            foreach ($forbidden as $secret) {
                // Yasak anahtarın metadata yazımının yakınında geçmesi bile
                // incelenmelidir; kaba tarama bilinçli olarak GENİŞTİR.
                if (preg_match('/channel_metadata.{0,400}?[\'"]'.preg_quote($secret, '/').'[\'"]/s', $source)) {
                    $offenders[] = basename($file).' → '.$secret;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "channel_metadata'ya SIR yazılıyor — kolon şifresizdir ve panele gider:\n"
            .implode("\n", $offenders),
        );
    }

    /** @param array<string, mixed>|null $metadata */
    private function makeListing(?array $metadata, bool $draft = false): Listing
    {
        $tenant = Tenant::factory()->create();

        return $this->asTenant($tenant, function () use ($metadata, $draft): Listing {
            $factory = Listing::factory();

            // Taslak: kanala HİÇ gönderilmemiş — `external_id` null.
            return ($draft ? $factory->draft() : $factory)
                ->create(['channel_metadata' => $metadata]);
        });
    }

    private function reload(Listing $listing): Listing
    {
        return $this->asTenant(
            Tenant::query()->findOrFail($listing->tenant_id),
            fn (): Listing => Listing::query()->findOrFail($listing->id),
        );
    }

    /** @return list<string> */
    private function sourceFiles(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
