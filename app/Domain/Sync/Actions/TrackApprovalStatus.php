<?php

declare(strict_types=1);

namespace App\Domain\Sync\Actions;

use App\Domain\Channels\Contracts\SupportsApprovalWorkflow;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Support\ApprovalTrackingResult;
use Illuminate\Support\Facades\DB;

/**
 * Kanaldaki onay durumlarını okur ve listing'lere yazar.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 2 ("onay durumu takibi"),
 * §7 · SupportsApprovalWorkflow, §14 · onay süreci.
 *
 * DEĞİŞMEZ KURAL — ONAY YALNIZCA `lifecycle_status` YÖNETİR (§14):
 *   Bu action hiçbir stok hareketi yazmaz, hiçbir bakiye güncellemez ve
 *   hiçbir outbox olayı üretmez. "Onaysız listing'e stok gönderilmez"
 *   kuralı fan-out'un `lifecycle_status = 'live'` filtresiyle zaten
 *   sağlanır; STOK MANTIĞI DEĞİŞMEZ.
 *
 * DEĞİŞMEZ KURAL — YETENEK `instanceof` İLE OKUNUR:
 *   Woo `SupportsApprovalWorkflow` uygulamaz; orada onay süreci yoktur ve
 *   ürün gönderilir gönderilmez yayına girer. `if ($code === '...')`
 *   yazılmaz.
 *
 * DEĞİŞMEZ KURAL — YALNIZCA GÖNDERİLMİŞ SATIRLAR SORULUR:
 *   `blocked` ve `draft` satırlar kanala hiç gitmedi; onaylarını sormak
 *   boşuna istektir. Aday sorgusu `pending_approval` ve `rejected`
 *   satırları alır — ikincisi bilinçlidir: satıcı eksiği düzeltip yeniden
 *   gönderdiğinde durumun canlıya dönmesi ancak yeniden sorularak görülür.
 *
 * DEĞİŞMEZ KURAL — KANALDA GÖRÜNMEYEN SATIRA DOKUNULMAZ:
 *   Trendyol yeni gönderilen ürünü listeye hemen koymaz. Yanıtta yoksa
 *   satır olduğu gibi bırakılır; yokluğu red saymak satıcıyı var olmayan
 *   bir hatayı düzeltmeye gönderirdi.
 */
final class TrackApprovalStatus
{
    /** Tek turda sorulacak en fazla listing — kanal yükü sınırlı tutulur. */
    private const BATCH_SIZE = 50;

    public function __construct(
        private readonly AdapterRegistry $registry,
    ) {}

    public function run(ChannelConnection $connection): ApprovalTrackingResult
    {
        // Her çağrıda YENİ örnek (§7 · P0 güvenlik).
        $adapter = $this->registry->for($connection);

        // Yetenek TİP SİSTEMİNDEN okunur.
        if (! $adapter instanceof SupportsApprovalWorkflow) {
            return ApprovalTrackingResult::unsupported();
        }

        $listings = $this->candidates($connection);

        if ($listings === []) {
            return new ApprovalTrackingResult(supported: true, checked: 0, approved: 0, rejected: 0);
        }

        $batch = $adapter->fetchApprovalStatus($listings);

        $approved = 0;
        $rejected = 0;

        foreach ($listings as $listing) {
            if ($listing->external_id === null) {
                continue;
            }

            $status = $batch->statusFor($listing->external_id);

            // Kanalda görünmüyor: DURUM UYDURULMAZ, satıra dokunulmaz.
            if ($status === null) {
                continue;
            }

            $this->apply($listing, $status);

            match ($status['status']) {
                'approved' => $approved++,
                'rejected' => $rejected++,
                default => null,
            };
        }

        return new ApprovalTrackingResult(
            supported: true,
            checked: count($listings),
            approved: $approved,
            rejected: $rejected,
        );
    }

    /**
     * Onay bekleyen satırlar — kiracı scope'u altında.
     *
     * `listings_pending_approval_idx` kısmi indeksi tam bu sorgu içindir.
     * En uzun süredir sorulmayanlar önce gelir: `approval_checked_at`
     * NULL olanlar hiç sorulmamış demektir ve sıranın başındadır.
     *
     * @return list<Listing>
     */
    private function candidates(ChannelConnection $connection): array
    {
        return Listing::query()
            ->where('channel_connection_id', $connection->id)
            ->whereIn('lifecycle_status', ['pending_approval', 'rejected'])
            // Kimliği olmayan satır kanala hiç gitmedi; sormak anlamsız.
            ->whereNotNull('external_id')
            ->orderByRaw('approval_checked_at NULLS FIRST')
            ->limit(self::BATCH_SIZE)
            ->get()
            ->all();
    }

    /**
     * Kanalın söylediğini satıra yazar.
     *
     * RED SEBEBİ ONAYDA TEMİZLENİR: satıcı eksiği düzeltip yeniden
     * gönderdiğinde eski sebep kalsaydı panel çalışan bir üründe hâlâ
     * hata gösterirdi.
     *
     * `listed_at` YALNIZCA İLK ONAYDA yazılır: satır daha önce yayına
     * girmişse o tarih ürünün kanaldaki gerçek yaşını taşır ve her turda
     * ezilmesi geçmişi silerdi.
     *
     * @param  array{status: string, reason?: string|null}  $status
     */
    private function apply(Listing $listing, array $status): void
    {
        $lifecycle = match ($status['status']) {
            // ONAYLANDI: canlı işareti fan-out hedefidir ve ancak burada
            // konur — kanalda yayında olmayan satıra stok göndermek her
            // turda hata alırdı.
            'approved' => 'live',
            'rejected' => 'rejected',
            // Onaylı ama satışa kapalı: kanalda GÖRÜNMÜYOR, canlı sayılmaz.
            'inactive' => 'pending_approval',
            default => $listing->lifecycle_status,
        };

        DB::transaction(function () use ($listing, $status, $lifecycle): void {
            $listing->forceFill([
                'lifecycle_status' => $lifecycle,
                'approval_rejection_reason' => $status['reason'] ?? null,
                'approval_checked_at' => now(),
                'listed_at' => $lifecycle === 'live'
                    ? ($listing->listed_at ?? now())
                    : $listing->listed_at,
            ])->save();
        });
    }
}
