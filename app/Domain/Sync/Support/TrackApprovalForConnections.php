<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Sync\Actions\TrackApprovalStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Onay takibi turu — BAĞLANTI başına.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 2 ("onay durumu takibi"),
 * §15 · zamanlanmış işler.
 *
 * MANTIK BURADA, KOMUT İNCE KABUK: `Command::run()` rezerve imzadır.
 *
 * DEĞİŞMEZ KURAL — BAĞLANTI BAŞINA TUR, TAKSONOMİNİN AKSİNE:
 *   Taksonomi kanal TÜRÜ başına dönerdi çünkü ağaç ortaktır. Onay durumu
 *   SATICIYA özeldir: her satıcının kendi ürünleri kendi onay sürecinden
 *   geçer. Tür başına tek tur atılsaydı yalnızca bir satıcının ürünleri
 *   kontrol edilir, diğerleri sonsuza kadar "beklemede" görünürdü.
 *
 * DEĞİŞMEZ KURAL — BİR BAĞLANTININ HATASI DİĞERLERİNİ DURDURMAZ:
 *   Hata günlüğe yazılır ve tur devam eder. Sessizce yutulsaydı onay
 *   durumu "kontrol edildi" sanılır ve satıcı reddedilen ürününü hiç
 *   öğrenemezdi.
 *
 * DEĞİŞMEZ KURAL — TUR KİRACI BAĞLAMINI KENDİ KURAR:
 *   `runAsSystem` ile tüm kiracıların bağlantıları taranır, ama her
 *   bağlantı KENDİ kiracısının bağlamında işlenir: listing sorguları
 *   kiracı scope'una tabidir ve sistem bağlamında çalıştırılsaydı bir
 *   kiracının turu diğerinin satırlarını görürdü.
 */
final class TrackApprovalForConnections
{
    public function __construct(
        private readonly TrackApprovalStatus $trackApprovalStatus,
    ) {}

    /**
     * @return array{connections: int, approved: int, rejected: int}
     */
    public function sweep(): array
    {
        $connections = TenantContext::runAsSystem(
            fn () => ChannelConnection::query()
                ->where('status', 'active')
                ->orderBy('created_at')
                ->get(['id', 'tenant_id', 'channel_type_code']),
        );

        $touched = 0;
        $approved = 0;
        $rejected = 0;

        foreach ($connections as $connection) {
            try {
                // Her bağlantı KENDİ kiracısının bağlamında işlenir.
                $result = TenantContext::runFor(
                    $connection->tenant_id,
                    function () use ($connection) {
                        // Bağlantı bağlam altında YENİDEN okunur: yetenek
                        // çözümü `adapter_class` gerektirir ve yukarıdaki
                        // sorgu yalnızca üç kolon seçti.
                        $scoped = ChannelConnection::query()
                            ->with('channelType:code,name,adapter_class')
                            ->find($connection->id);

                        if ($scoped === null) {
                            return null;
                        }

                        return $this->trackApprovalStatus->run($scoped);
                    },
                );

                if ($result === null || ! $result->supported) {
                    continue;
                }

                $touched++;
                $approved += $result->approved;
                $rejected += $result->rejected;
            } catch (Throwable $e) {
                // Bir bağlantının hatası turu durdurmaz.
                Log::warning('approval.track_failed', [
                    'connection' => $connection->id,
                    'channel_type' => $connection->channel_type_code,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['connections' => $touched, 'approved' => $approved, 'rejected' => $rejected];
    }
}
