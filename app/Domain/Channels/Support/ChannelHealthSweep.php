<?php

declare(strict_types=1);

namespace App\Domain\Channels\Support;

use App\Domain\Channels\Actions\CheckChannelHealth;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bağlı kanalların sağlığını düzenli ölçer.
 *
 * KAPATILAN BOŞLUK (8 Eki 2026, canlı): sağlık kontrolü yalnızca BAĞLANIRKEN
 * koşuyordu. Etsy uygulama anahtarı 7 Eki 13:40 UTC'de kanal tarafında
 * geçersiz oldu; 31 saat boyunca 207 çağrı 403 aldı, `last_error` doldu ama
 * bağlantı panelde "sağlıklı" (yeşil) kaldı ve sipariş yoklaması her turda
 * reddedildi. Satıcı kopukluğu göremezdi.
 *
 * HATA İKİNCİ ÖLÇÜMLE DOĞRULANIR: `CheckChannelHealth` sağlıksız bağlantıyı
 * `pending`'e çeker ve akış durur. Tek bir ağ takılması bağlantıyı bir saat
 * kapalı bırakmasın diye ilk başarısız ölçümden sonra kısa bekleyip yeniden
 * ölçülür; yazmayı yalnız ikinci ölçüm yapar.
 *
 * İYİLEŞME KENDİLİĞİNDENDİR: sağlıksız bağlantı tekrar geçerse
 * `CheckChannelHealth` onu `active`'e alır — satıcı anahtarı düzeltince
 * elle bir şey yapması gerekmez.
 *
 * Yalnız bir kez BAĞLANMIŞ (`connected_at` dolu) ve `active`/`pending`
 * bağlantılar ölçülür: hiç kurulmamış bir bağlantıyı tarama kendiliğinden
 * etkinleştirmemeli; `inactive` (iptal edilmiş) bağlantıya dokunulmaz.
 */
final class ChannelHealthSweep
{
    public function __construct(
        private readonly AdapterRegistry $registry,
        private readonly CheckChannelHealth $checkHealth,
    ) {}

    /**
     * @return array{healthy: int, unhealthy: int, recovered: int, blips: int}
     */
    public function run(int $confirmDelaySeconds = 20): array
    {
        return TenantContext::runAsSystem(function () use ($confirmDelaySeconds): array {
            $counts = ['healthy' => 0, 'unhealthy' => 0, 'recovered' => 0, 'blips' => 0];

            $connections = ChannelConnection::query()
                ->with('channelType:code,name,adapter_class')
                ->whereIn('status', ['active', 'pending'])
                ->whereNotNull('connected_at')
                ->get();

            foreach ($connections as $connection) {
                try {
                    $counts[$this->checkOne($connection, $confirmDelaySeconds)]++;
                } catch (Throwable $e) {
                    // Tek bağlantının hatası turu durdurmaz.
                    Log::warning('channels.health_sweep_failed', [
                        'connection_id' => $connection->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $counts;
        });
    }

    /** @return 'healthy'|'unhealthy'|'recovered'|'blips' */
    private function checkOne(ChannelConnection $connection, int $confirmDelaySeconds): string
    {
        $wasHealthy = $connection->health_status === 'healthy' && $connection->status === 'active';

        try {
            $first = $this->registry->for($connection)->healthCheck()->healthy;
        } catch (Throwable) {
            $first = false;
        }

        if ($first) {
            if ($wasHealthy) {
                // Değişen bir şey yok; yalnız son sağlıklı anı ilerler.
                $connection->forceFill(['last_healthy_at' => now()])->save();

                return 'healthy';
            }

            // Sağlıksızdan iyileşme: durum geçişini çekirdek yazar.
            $this->checkHealth->run($connection);

            return $connection->health_status === 'healthy' ? 'recovered' : 'unhealthy';
        }

        if ($confirmDelaySeconds > 0) {
            sleep($confirmDelaySeconds);
        }

        // İkinci ölçüm YAZAR: geçerse anlık takılmaydı, geçmezse kırmızı.
        $this->checkHealth->run($connection);

        if ($connection->health_status === 'healthy') {
            return 'blips';
        }

        return 'unhealthy';
    }
}
