<?php

declare(strict_types=1);

namespace App\Domain\Reconciliation\Actions;

use App\Domain\Channels\Contracts\SupportsInventory;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Inventory\Models\InventoryLevel;
use App\Domain\Inventory\Support\OutboundQuantity;
use App\Domain\Reconciliation\Enums\ItemStatus;
use App\Domain\Reconciliation\Enums\ReconciliationScope;
use App\Domain\Reconciliation\Models\ReconciliationItem;
use App\Domain\Reconciliation\Models\ReconciliationRun;
use App\Domain\Reconciliation\Support\CandidateSelector;
use App\Domain\Reconciliation\Support\DriftHistory;
use App\Domain\Reconciliation\Support\SampledCandidates;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\ListingSyncState;
use App\Domain\Sync\Support\RemoteInventorySnapshot;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Bir bağlantı için stok mutabakatı — beş adımlı akış.
 *
 * Mimari Karar Dokümanı v2.2 · §10 · Reconciliation Engine.
 *
 *   DETECT   adaylar seçilir, uzak durum TOPLU okunur (tek istek)
 *   RECORD   kalem yazılır, remote_hash ve last_observed_at damgalanır
 *   CLASSIFY MATCHED / REPAIRED / DRIFT_DETECTED / MANUAL_REVIEW /
 *            REMOTE_MISSING / REMOTE_UNREACHABLE
 *   REPAIR   INVENTORY otomatik onarılır (§9), ÜÇ TUR EMNİYETİNE kadar
 *   VERIFY   bir SONRAKİ tur — onarımdan hemen sonra okumak yanlış sonuç verir
 *
 * DEĞİŞMEZ KURAL — ONARIM DÖNGÜ EMNİYETİ (§10 · 3 tur kuralı):
 *   Onarım sürüm kapısını ATLAR ve `desired_version`'ı ARTIRMAZ; bunun
 *   bedeli, kanal 200 dönüp değişikliği UYGULAMIYORSA aynı farkın her
 *   turda yeniden bulunup yeniden onarılmasıdır — sıcak katmanda beş
 *   dakikada bir, SONSUZA KADAR. Üçüncü ardışık sürüklenmede otomatik
 *   onarım DURUR ve kalem `MANUAL_REVIEW` işaretlenir. Sürüklenme yine
 *   SAYILIR: emniyet onarımı durdurur, gerçeği gizlemez.
 *
 * DEĞİŞMEZ KURAL — KARŞILAŞTIRMA GİDEN DEĞERLE YAPILIR:
 *   Beklenen uzak değer `OutboundQuantity::forChannel()` yani
 *   `max(available, 0)`. Kanonik bakiye fazla satış nedeniyle negatifse
 *   kanaldaki 0 DOĞRUDUR ve sürüklenme DEĞİLDİR. Ham kanonik değerle
 *   karşılaştırılsaydı her fazla satış kalıcı sürüklenme olarak raporlanır
 *   ve SONSUZ ONARIM DÖNGÜSÜ doğardı.
 *
 * DEĞİŞMEZ KURAL — TOPLU OKUMA:
 *   50 listing TEK istekte okunur. Listing başına ayrı istek, ölçek
 *   hesabını (bağlantı başına saatte 12 istek) yüz katına çıkarırdı.
 *
 * DEĞİŞMEZ KURAL — DOĞRULAMA AYRI TURDA:
 *   Onarımdan hemen sonra okumak hem kota yer hem de pazaryerlerinde stok
 *   güncellemesi saniyeler sonra yansıdığı için yanlış sonuç verir. Kalem
 *   bir sonraki turda `drift_detected` sebebiyle tekrar aday olur.
 */
final class ReconcileConnection
{
    public function __construct(
        private readonly CandidateSelector $candidates,
        private readonly SampledCandidates $sampled,
        private readonly AdapterRegistry $registry,
        private readonly QueueRepair $queueRepair,
        private readonly DriftHistory $history,
    ) {}

    /**
     * @param  int|null  $budget  null → katmanın kendi bütçesi (§10 tablosu)
     */
    public function run(
        ChannelConnection $connection,
        ReconciliationScope $scope = ReconciliationScope::HOT,
        ?int $budget = null,
        string $triggerReason = 'scheduled',
    ): ReconciliationRun {
        $run = ReconciliationRun::query()->create([
            'tenant_id' => TenantContext::idOrFail(),
            'channel_connection_id' => $connection->id,
            'scope' => $scope->value,
            'trigger_reason' => $triggerReason,
            'started_at' => now(),
            'status' => 'running',
        ]);

        // ── DETECT ────────────────────────────────────────────────────
        $candidates = $this->selectCandidates($connection, $scope, $budget);

        $run->forceFill(['candidates_count' => count($candidates)])->save();

        if ($candidates === []) {
            return $this->finish($run, 'completed');
        }

        $listings = $this->listingsFor($candidates);

        if ($listings === []) {
            return $this->finish($run, 'completed');
        }

        $adapter = $this->registry->for($connection);

        if (! $adapter instanceof SupportsInventory) {
            throw new RuntimeException(
                "Bağlantı {$connection->id} stok okumayı desteklemiyor ".
                '(SupportsInventory uygulanmıyor).'
            );
        }

        // Uzak durum TOPLU okunur. Okuma patlarsa bu SÜRÜKLENME DEĞİLDİR:
        // fark kanıtlanmadı, altyapı sorunu var. Kalemler
        // REMOTE_UNREACHABLE yazılır ve onarım AÇILMAZ.
        try {
            $snapshot = $adapter->fetchInventory(array_values($listings));
        } catch (Throwable $e) {
            $this->recordUnreachable($run, $listings, $candidates);

            return $this->finish($run, 'failed', $e->getMessage());
        }

        // ── RECORD + CLASSIFY + REPAIR ────────────────────────────────
        $checked = 0;
        $drift = 0;

        foreach ($candidates as $candidate) {
            $listing = $listings[$candidate['listing_id']] ?? null;

            if ($listing === null) {
                continue;
            }

            $item = $this->compareAndRecord($run, $listing, $candidate, $snapshot);

            $checked++;

            if (! ItemStatus::from($item->status)->isDrift()) {
                continue;
            }

            $drift++;

            // §10 · ONARIM DÖNGÜ EMNİYETİ — kalem MANUAL_REVIEW yazıldıysa
            // bu listing üç turdur sürükleniyor ve kanal bizim yazmamızı
            // uygulamıyor demektir. Onarımı tekrarlamak yalnızca kota
            // harcar; sürüklenme yine SAYILIR (gerçeği gizlemeyiz), ama
            // operasyon AÇILMAZ.
            if ($item->status === ItemStatus::MANUAL_REVIEW->value) {
                continue;
            }

            // §9 · INVENTORY politikası: sessizce üzerine yaz, rozet yok.
            // Stokta tek otorite biziz; kanaldaki fark ya kanalın kendi
            // satışıdır (bize sipariş olarak gelir) ya sürüklenmedir.
            $this->queueRepair->run($item);
        }

        $run->forceFill([
            'checked_count' => $checked,
            'drift_count' => $drift,
        ])->save();

        return $this->finish($run, 'completed');
    }

    // ---------------------------------------------------------------- iç

    /**
     * Katmana göre aday seçimi — §10'un üç katmanı arasındaki TEK fark.
     *
     * Beş adımlı akış (DETECT/RECORD/CLASSIFY/REPAIR/VERIFY) üç katmanda da
     * AYNIDIR; değişen yalnızca hangi satırların seçildiği ve kaç tanesinin
     * okunduğudur. Akış katman başına kopyalansaydı üç kopya zamanla
     * ayrışır ve örneğin `max(available, 0)` karşılaştırması birinde
     * düzeltilip ötekilerde eski hâliyle kalırdı.
     *
     * SOĞUK KATMAN DÖRT SEBEP SORGUSUNU ÇALIŞTIRMAZ: kapsamı "rastgele
     * örneklem — uzun kuyruk"tur ve tam olarak o dört sebebin hiçbirine
     * takılmayan satırı arar. Dört sorgu burada da koşsaydı soğuk katman
     * ılık katmanın günlük bir kopyası olurdu.
     *
     * SOĞUK BÜTÇE ORANSALDIR ve burada hesaplanır — çağıranın verdiği
     * `$budget` yalnızca ÜST SINIRI belirler. Sabit 500 kullanılsaydı 50
     * listing'i olan bir bağlantıda günlük tur katalogun TAMAMINI okur ve
     * "tam katalog taraması hiçbir katmanda yok" kuralı sessizce
     * çiğnenirdi.
     *
     * @return list<array{listing_id: string, reason: string, priority: int}>
     */
    private function selectCandidates(
        ChannelConnection $connection,
        ReconciliationScope $scope,
        ?int $budget,
    ): array {
        $cap = $budget ?? $scope->budget();

        if ($scope->usesReasonQueries()) {
            return $this->candidates->for($connection, $cap, $scope);
        }

        return $this->sampled->for(
            $connection,
            $this->sampled->budgetFor(
                activeListings: $this->sampled->activeListingCount($connection),
                cap: $cap,
            ),
        );
    }

    /**
     * Kanonik ile uzak durumu karşılaştırır ve kalemi yazar.
     *
     * @param  array{listing_id: string, reason: string, priority: int}  $candidate
     */
    private function compareAndRecord(
        ReconciliationRun $run,
        Listing $listing,
        array $candidate,
        RemoteInventorySnapshot $snapshot,
    ): ReconciliationItem {
        $level = InventoryLevel::query()
            ->where('variant_id', $listing->variant_id)
            ->first();

        // KARŞILAŞTIRMA TABANI — kırpılmış giden değer.
        $expectedRemote = $level === null ? 0 : OutboundQuantity::forChannel($level);

        $observedRemote = $listing->external_id === null
            ? null
            : $snapshot->quantityFor($listing->external_id);

        [$status, $magnitude] = $this->classify($listing->id, $expectedRemote, $observedRemote);

        $item = ReconciliationItem::query()->create([
            'tenant_id' => $run->tenant_id,
            'reconciliation_run_id' => $run->id,
            'listing_id' => $listing->id,
            'domain' => SyncDomain::INVENTORY->value,
            'priority_reason' => $candidate['reason'],
            'status' => $status->value,
            // HAM kanonik bakiye DE saklanır: fazla satışta iki değer
            // ayrışır ve denetim "neden sürüklenme sayılmadı" sorusunu
            // ancak ikisini birden görerek cevaplayabilir.
            'local_value' => [
                'available' => $level?->available ?? 0,
                'expected_remote' => $expectedRemote,
                'version' => $level?->version ?? 0,
            ],
            'remote_value' => ['quantity' => $observedRemote],
            'drift_magnitude' => $magnitude,
            'checked_at' => now(),
            'resolved_at' => $status === ItemStatus::MATCHED ? now() : null,
        ]);

        // Uzak durum GÖZLENDİ — §9'un üçüncü durumu burada dolar.
        $this->stampObservation($listing, $observedRemote);

        return $item;
    }

    /**
     * Sınıflandırma — GEÇMİŞE DUYARLIDIR.
     *
     * Eşleşme ve sürüklenme kararları tek başına şu anki değerlere
     * bakarak verilemez; ikisinin de bir ÖNCESİ vardır (§10 · VERIFY):
     *
     *   · Eşleşme bir onarımın ARDINDAN geldiyse `REPAIRED`, yoksa
     *     `MATCHED`. Ayrım denetim içindir: `MATCHED` "zaten doğruydu",
     *     `REPAIRED` "bozuktu ve onarımımız TUTTU" demektir.
     *   · Sürüklenme üçüncü kez ÜST ÜSTE görülüyorsa `MANUAL_REVIEW`.
     *     `DRIFT_DETECTED` bırakılsaydı kalem bir sonraki turda yine
     *     `drift_detected` sebebiyle aday olur ve panel onu "onarım
     *     bekliyor" gibi gösterirdi — oysa hiçbir onarım gelmeyecek.
     *
     * @return array{0: ItemStatus, 1: int|null}
     */
    private function classify(string $listingId, int $expectedRemote, ?int $observedRemote): array
    {
        // Kanal bu kimliği hiç döndürmedi: ürün orada yok.
        // Otomatik onarım YAPILMAZ — yeniden listeleme kullanıcı onayı ister
        // ve sessizce yaratmak kanalda kopya ürün açardı.
        if ($observedRemote === null) {
            return [ItemStatus::REMOTE_MISSING, null];
        }

        if ($expectedRemote === $observedRemote) {
            return [
                $this->history->awaitingRepairVerification($listingId)
                    ? ItemStatus::REPAIRED
                    : ItemStatus::MATCHED,
                null,
            ];
        }

        $magnitude = abs($expectedRemote - $observedRemote);

        return [
            $this->history->autoRepairAllowed($listingId)
                ? ItemStatus::DRIFT_DETECTED
                : ItemStatus::MANUAL_REVIEW,
            $magnitude,
        ];
    }

    /**
     * `remote_hash` ve `last_observed_at` — §9'un "gözlenen" durumu.
     *
     * Bu iki alan olmadan çakışma tespiti imkânsızdır: `synced != remote`
     * sorusu ancak gözlem kaydedilirse sorulabilir.
     *
     * SATIR YOKSA YARATILIR. Hiç senkronlanmamış bir listing tam da
     * sürüklenmeye en açık olandır (kanalda elle açılmış veya gönderimi
     * hiç başarmamış olabilir); gözlemi atmak, mutabakatın öğrendiği tek
     * şeyi çöpe atmak olurdu. Sürüm alanları SIFIR başlar — gözlem
     * kaydetmek "gönderdik" demek değildir.
     */
    private function stampObservation(Listing $listing, ?int $observedRemote): void
    {
        DB::table('listing_sync_states')->insertOrIgnore([
            'id' => ListingSyncState::generateUuidV7(),
            'tenant_id' => $listing->tenant_id,
            'listing_id' => $listing->id,
            'domain' => SyncDomain::INVENTORY->value,
            'desired_version' => 0,
            'synced_version' => 0,
            'status' => 'pending',
            'error_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $state = ListingSyncState::query()
            ->where('listing_id', $listing->id)
            ->where('domain', SyncDomain::INVENTORY->value)
            ->first();

        if ($state === null) {
            return;
        }

        $state->forceFill([
            'remote_hash' => $observedRemote === null
                ? null
                : hash('sha256', (string) $observedRemote),
            'last_observed_at' => now(),
        ])->save();
    }

    /**
     * Kanal okunamadı — hepsi REMOTE_UNREACHABLE, onarım AÇILMAZ.
     *
     * @param  array<string, Listing>  $listings
     * @param  list<array{listing_id: string, reason: string, priority: int}>  $candidates
     */
    private function recordUnreachable(
        ReconciliationRun $run,
        array $listings,
        array $candidates,
    ): void {
        foreach ($candidates as $candidate) {
            $listing = $listings[$candidate['listing_id']] ?? null;

            if ($listing === null) {
                continue;
            }

            ReconciliationItem::query()->create([
                'tenant_id' => $run->tenant_id,
                'reconciliation_run_id' => $run->id,
                'listing_id' => $listing->id,
                'domain' => SyncDomain::INVENTORY->value,
                'priority_reason' => $candidate['reason'],
                'status' => ItemStatus::REMOTE_UNREACHABLE->value,
                'checked_at' => now(),
            ]);
        }
    }

    /**
     * @param  list<array{listing_id: string, reason: string, priority: int}>  $candidates
     * @return array<string, Listing>
     */
    private function listingsFor(array $candidates): array
    {
        $ids = array_column($candidates, 'listing_id');

        return Listing::query()
            ->with('variant')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id')
            ->all();
    }

    private function finish(ReconciliationRun $run, string $status, ?string $error = null): ReconciliationRun
    {
        return DB::transaction(function () use ($run, $status, $error): ReconciliationRun {
            $run->forceFill([
                'status' => $status,
                'finished_at' => now(),
                'last_error' => $error,
            ])->save();

            return $run->refresh();
        });
    }
}
