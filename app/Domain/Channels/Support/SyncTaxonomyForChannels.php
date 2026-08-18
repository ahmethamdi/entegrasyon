<?php

declare(strict_types=1);

namespace App\Domain\Channels\Support;

use App\Domain\Channels\Actions\SyncTaxonomy;
use App\Domain\Channels\Models\ChannelConnection;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Taksonomi turu — kanal TÜRÜ başına bir kez.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 2 (taksonomi), §15 · zamanlanmış
 * işler (maintenance kuyruğu).
 *
 * MANTIK BURADA, KOMUT İNCE KABUK: `Command::run()` rezerve imzadır ve
 * `Command`'dan türeyen sınıf kendi `run(...)` metodunu tanımlayamaz.
 *
 * DEĞİŞMEZ KURAL — KANAL TÜRÜ BAŞINA BİR TUR, BAĞLANTI BAŞINA DEĞİL:
 *   Taksonomi kiracısızdır ve Trendyol'un ağacı tüm satıcılar için aynıdır.
 *   Bağlantı başına dönülseydi 100 Trendyol satıcısı olan bir kurulumda
 *   AYNI 30 bin kategorilik ağaç 100 kez çekilir, kota anlamsızca tükenir
 *   ve veritabanına 100 kez aynı satırlar yazılırdı.
 *
 *   Yine de ÇAĞRI bir bağlantı üzerinden yapılmak zorundadır: kimlik
 *   bilgisi bağlantıya aittir. Bu yüzden tür başına bir bağlantı seçilir ve
 *   tur onun üzerinden döner.
 *
 * DEĞİŞMEZ KURAL — TEK BOZUK BAĞLANTI TÜM KANALI DURDURMAZ:
 *   Seçilen bağlantı çalışmayabilir: kimlik bilgisi süresi dolmuş, ayarı
 *   eksik veya satıcı hesabı kapanmış olabilir. İlk bağlantıda pes
 *   edilseydi, o kanaldaki TÜM satıcılar taksonomisiz kalırdı — üstelik
 *   sorun kendi bağlantılarında değil bir başkasınınkinde olduğu için
 *   hiçbiri düzeltemezdi.
 *
 *   Bu yüzden bağlantılar SIRAYLA denenir ve ilk BAŞARILI olan turu
 *   tamamlar. Ağaç ortak olduğu için hangisinin başardığı önemsizdir.
 *   (Gerçek çalıştırmada bulundu: ayarı eksik eski bir bağlantı seçilmiş
 *   ve tüm Trendyol taksonomisi hiç çekilmemişti.)
 *
 * BİR TÜRÜN HATASI DİĞERLERİNİ DURDURMAZ: tur devam eder ve hata günlüğe
 * yazılır; sessizce yutulsaydı taksonomi "güncellendi" görünürken ağaç
 * aylarca eskimiş kalırdı.
 */
final class SyncTaxonomyForChannels
{
    public function __construct(
        private readonly SyncTaxonomy $syncTaxonomy,
    ) {}

    /**
     * @return int Taksonomisi güncellenen kanal türü sayısı
     */
    public function sweep(bool $withAttributes = false): int
    {
        // Kanal türü başına TÜM aktif bağlantılar toplanır; ağaç ortaktır
        // ama hangi bağlantının gerçekten çalıştığı önceden bilinmez.
        $byChannelType = TenantContext::runAsSystem(
            fn () => ChannelConnection::query()
                ->where('status', 'active')
                ->orderBy('created_at')
                ->get(['id', 'tenant_id', 'channel_type_code'])
                ->groupBy('channel_type_code'),
        );

        $synced = 0;

        foreach ($byChannelType as $channelTypeCode => $connections) {
            if ($this->syncOneChannelType((string) $channelTypeCode, $connections->all(), $withAttributes)) {
                $synced++;
            }
        }

        return $synced;
    }

    /**
     * Bir kanal türünün taksonomisini çeker — ilk BAŞARILI bağlantıyla.
     *
     * Bağlantılar sırayla denenir: ayarı eksik ya da kimlik bilgisi ölmüş
     * bir bağlantıda pes edilseydi o kanaldaki tüm satıcılar taksonomisiz
     * kalırdı.
     *
     * @param  list<ChannelConnection>  $connections
     * @return bool Taksonomi çekildi mi
     */
    private function syncOneChannelType(
        string $channelTypeCode,
        array $connections,
        bool $withAttributes,
    ): bool {
        foreach ($connections as $connection) {
            try {
                $result = TenantContext::runFor(
                    $connection->tenant_id,
                    function () use ($connection, $withAttributes): TaxonomySyncResult {
                        // Model kiracı bağlamında YENİDEN okunur: yukarıdaki
                        // kopya yalnızca kimlik taşıyor ve adapter kurulumu
                        // bağlantının tüm alanlarına ihtiyaç duyuyor.
                        $fresh = ChannelConnection::query()->find($connection->id);

                        if ($fresh === null) {
                            return TaxonomySyncResult::unsupported();
                        }

                        return $this->syncTaxonomy->run($fresh, withAttributes: $withAttributes);
                    },
                );

                // Taksonomi desteklenmiyorsa (Woo) sonraki bağlantıyı
                // denemek anlamsızdır: yetenek KANAL TÜRÜNÜN özelliğidir,
                // bağlantının değil.
                if (! $result->supported) {
                    return false;
                }

                return true;
            } catch (Throwable $e) {
                // SESSİZCE YUTULMAZ: yutulsaydı taksonomi "güncellendi"
                // görünürken ağaç aylarca eskimiş kalırdı. Ama tur burada
                // BİTMEZ — sıradaki bağlantı denenir.
                Log::warning('taxonomy.sync_failed', [
                    'connection' => $connection->id,
                    'channel_type' => $channelTypeCode,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return false;
    }
}
