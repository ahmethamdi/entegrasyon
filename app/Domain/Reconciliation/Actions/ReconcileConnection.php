<?php

declare(strict_types=1);

namespace App\Domain\Reconciliation\Actions;

use App\Domain\Channels\Contracts\SupportsInventory;
use App\Domain\Channels\Contracts\SupportsPricing;
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
use App\Domain\Sync\Support\RemotePriceSnapshot;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Bir bağlantı için mutabakat — beş adımlı akış, İKİ DOMAIN.
 *
 * Mimari Karar Dokümanı v2.2 · §10 · Reconciliation Engine, §9 · domain
 * başına çakışma politikası.
 *
 *   DETECT   adaylar seçilir, uzak durum TOPLU okunur (tek istek)
 *   RECORD   kalem yazılır, remote_hash ve last_observed_at damgalanır
 *   CLASSIFY MATCHED / REPAIRED / DRIFT_DETECTED / MANUAL_REVIEW /
 *            REMOTE_MISSING / REMOTE_UNREACHABLE / PRICE_CONFLICT
 *   REPAIR   INVENTORY otomatik onarılır (§9), ÜÇ TUR EMNİYETİNE kadar;
 *            PRICE ONARILMAZ — gerekçe aşağıda
 *   VERIFY   bir SONRAKİ tur — onarımdan hemen sonra okumak yanlış sonuç verir
 *
 * DEĞİŞMEZ KURAL — İKİ DOMAIN, TEK AKIŞ (§10'un "üç katman tek akış"
 * kuralının kardeşi):
 *   Fiyat turu bu akışın bir KOPYASI DEĞİLDİR; aynı beş adımı yürütür ve
 *   yalnızca ÜÇ noktada ayrışır: hangi yetenek okunur (`SupportsInventory`
 *   / `SupportsPricing`), beklenen değer nereden gelir (kırpılmış bakiye /
 *   kanonik fiyat) ve fark bulununca ne yazılır. Kopyalansaydı iki akış
 *   zamanla ayrışırdı — `max(available, 0)` karşılaştırması ya da üç tur
 *   emniyeti birinde düzeltilip ötekinde eski hâliyle kalırdı.
 *
 * DEĞİŞMEZ KURAL — FİYATTA REPAIR ADIMI ATLANIR (§9 · PRICE politikası):
 *   Fark bulunduğunda kalem `PRICE_CONFLICT` yazılır ve onarım AÇILMAZ.
 *   Kapı `ItemStatus::isDrift()`'tir ve o `false` döner — burada ayrı bir
 *   `if ($domain === PRICE)` YAZILMAZ, çünkü kural enum'da tek kaynaktır.
 *   Gerekçe §9'da yazılı: satıcılar kanal panelinden kampanya yapıyor ve
 *   sessizce ezmek EN SIK ŞİKAYET. Stokta tek otorite biziz; fiyatta
 *   DEĞİLİZ.
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
     * @param  SyncDomain  $domain  INVENTORY veya PRICE — gerekçe sınıf başlığında
     */
    public function run(
        ChannelConnection $connection,
        ReconciliationScope $scope = ReconciliationScope::HOT,
        ?int $budget = null,
        string $triggerReason = 'scheduled',
        SyncDomain $domain = SyncDomain::INVENTORY,
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
        $candidates = $this->selectCandidates($connection, $scope, $budget, $domain);

        $run->forceFill(['candidates_count' => count($candidates)])->save();

        if ($candidates === []) {
            return $this->finish($run, 'completed');
        }

        $listings = $this->listingsFor($candidates);

        if ($listings === []) {
            return $this->finish($run, 'completed');
        }

        // YETENEK KAPISI `try` BLOĞUNUN DIŞINDADIR ve bu ZORUNLUDUR.
        // Yetenek yokluğu bir ÇALIŞTIRMA hatası değil PROGRAMLAMA hatasıdır:
        // tur hiç açılmamalıydı. İçeride kalsaydı bağlantının tüm
        // listing'leri REMOTE_UNREACHABLE damgalanır ve sebep — "bu kanal
        // fiyat okumayı desteklemiyor" — kalemlerin arasında kaybolurdu.
        // Ayrım İSTİSNA TİPİYLE yapılamaz: adapter'lar da `RuntimeException`
        // fırlatır ve gerçek bir ağ hatası yetenek yokluğu sanılırdı.
        $adapter = $this->adapterFor($connection, $domain);

        // Uzak durum TOPLU okunur. Okuma patlarsa bu SÜRÜKLENME DEĞİLDİR:
        // fark kanıtlanmadı, altyapı sorunu var. Kalemler
        // REMOTE_UNREACHABLE yazılır ve onarım AÇILMAZ.
        try {
            $snapshot = $domain === SyncDomain::PRICE
                ? $adapter->fetchPrices(array_values($listings))
                : $adapter->fetchInventory(array_values($listings));
        } catch (Throwable $e) {
            $this->recordUnreachable($run, $listings, $candidates, $domain);

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

            $item = $this->compareAndRecord($run, $listing, $candidate, $snapshot, $domain);

            $checked++;

            // ── REPAIR KAPISI ─────────────────────────────────────────
            // `PRICE_CONFLICT` burada elenir ve fiyat turunun REPAIR adımı
            // böylece ATLANIR — ayrı bir domain koşulu YAZILMAZ, kural
            // `ItemStatus::isDrift()` içinde TEK KAYNAKTIR (§9 · PRICE).
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
        SyncDomain $domain,
    ): array {
        $cap = $budget ?? $scope->budget();

        if ($scope->usesReasonQueries()) {
            return $this->candidates->for($connection, $cap, $scope, $domain);
        }

        return $this->sampled->for(
            $connection,
            $this->sampled->budgetFor(
                activeListings: $this->sampled->activeListingCount($connection, $domain),
                cap: $cap,
            ),
            $domain,
        );
    }

    /**
     * Domainin gerektirdiği yeteneği taşıyan adapter — akışın ayrıştığı
     * BİRİNCİ nokta.
     *
     * Yetenek `instanceof` ile okunur; `if ($channel === '...')` YAZILMAZ
     * (§7). Yetenek yoksa İSTİSNA fırlatılır ve tur AÇILMAMALIYDI: sessizce
     * boş anlık görüntü dönseydi kanaldaki her listing "orada yok"
     * (`REMOTE_MISSING`) damgalanır ve satıcı var olmayan bir sorunu
     * kovalardı. "Yazılmamış yetenek SESSİZCE BAŞARILI DÖNMEZ" kuralının
     * okuma tarafındaki karşılığı.
     */
    private function adapterFor(
        ChannelConnection $connection,
        SyncDomain $domain,
    ): SupportsInventory|SupportsPricing {
        $adapter = $this->registry->for($connection);

        if ($domain === SyncDomain::PRICE) {
            if (! $adapter instanceof SupportsPricing) {
                throw new RuntimeException(
                    "Bağlantı {$connection->id} fiyat okumayı desteklemiyor ".
                    '(SupportsPricing uygulanmıyor).'
                );
            }

            return $adapter;
        }

        if (! $adapter instanceof SupportsInventory) {
            throw new RuntimeException(
                "Bağlantı {$connection->id} stok okumayı desteklemiyor ".
                '(SupportsInventory uygulanmıyor).'
            );
        }

        return $adapter;
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
        RemoteInventorySnapshot|RemotePriceSnapshot $snapshot,
        SyncDomain $domain,
    ): ReconciliationItem {
        [$status, $magnitude, $local, $remote, $observedHash] = $domain === SyncDomain::PRICE
            ? $this->comparePrice($listing, $snapshot)
            : $this->compareInventory($listing, $snapshot);

        $item = ReconciliationItem::query()->create([
            'tenant_id' => $run->tenant_id,
            'reconciliation_run_id' => $run->id,
            'listing_id' => $listing->id,
            'domain' => $domain->value,
            'priority_reason' => $candidate['reason'],
            'status' => $status->value,
            'local_value' => $local,
            'remote_value' => $remote,
            'drift_magnitude' => $magnitude,
            'checked_at' => now(),
            'resolved_at' => $status === ItemStatus::MATCHED ? now() : null,
        ]);

        // Uzak durum GÖZLENDİ — §9'un üçüncü durumu burada dolar.
        $this->stampObservation($listing, $observedHash, $domain);

        return $item;
    }

    /**
     * Stok karşılaştırması — KIRPILMIŞ giden değerle.
     *
     * @return array{0: ItemStatus, 1: int|null, 2: array<string, mixed>, 3: array<string, mixed>, 4: string|null}
     */
    private function compareInventory(Listing $listing, RemoteInventorySnapshot $snapshot): array
    {
        $level = InventoryLevel::query()
            ->where('variant_id', $listing->variant_id)
            ->first();

        // KARŞILAŞTIRMA TABANI — kırpılmış giden değer.
        $expectedRemote = $level === null ? 0 : OutboundQuantity::forChannel($level);

        $observedRemote = $listing->external_id === null
            ? null
            : $snapshot->quantityFor($listing->external_id);

        [$status, $magnitude] = $this->classify(
            $listing->id,
            $expectedRemote,
            $observedRemote,
            SyncDomain::INVENTORY,
        );

        return [
            $status,
            $magnitude,
            // HAM kanonik bakiye DE saklanır: fazla satışta iki değer
            // ayrışır ve denetim "neden sürüklenme sayılmadı" sorusunu
            // ancak ikisini birden görerek cevaplayabilir.
            [
                'available' => $level?->available ?? 0,
                'expected_remote' => $expectedRemote,
                'version' => $level?->version ?? 0,
            ],
            ['quantity' => $observedRemote],
            $observedRemote === null ? null : hash('sha256', (string) $observedRemote),
        ];
    }

    /**
     * Fiyat karşılaştırması — KURUŞ ÖLÇEĞİNDE TAM SAYI ÜZERİNDEN.
     *
     * §7 ve fiyat senkron kuralları: `decimal(12,2)` PHP'ye STRING döner ve
     * float karşılaştırması İKİ YÖNDEN DE yanıltır — `"19.90" == "19.9"`
     * float olarak eşitken string olarak değildir, `0.1 + 0.2 !== 0.3` ise
     * eşit olması gerekirken değildir. Kuruşa çevrilmiş tam sayı ikisini de
     * çözer ve `UpdateProduct`'ın "fiyat değişmediyse olay yazma"
     * karşılaştırmasıyla AYNI ölçeği kullanır.
     *
     * KIRPMA YOK — stoktan farklı. Kırpma (`OutboundQuantity`) fazla satışın
     * negatif bakiyesine özgüdür; fiyatın negatif olma hâli yoktur ve
     * giden değer kanonik değerin KENDİSİDİR.
     *
     * @return array{0: ItemStatus, 1: int|null, 2: array<string, mixed>, 3: array<string, mixed>, 4: string|null}
     */
    private function comparePrice(Listing $listing, RemotePriceSnapshot $snapshot): array
    {
        $ourPrice = (string) ($listing->variant?->price ?? '0');

        $observedPrice = $listing->external_id === null
            ? null
            : $snapshot->priceFor($listing->external_id);

        [$status, $magnitude] = $this->classify(
            $listing->id,
            $this->toMinorUnits($ourPrice),
            $observedPrice === null ? null : $this->toMinorUnits($observedPrice),
            SyncDomain::PRICE,
        );

        return [
            $status,
            $magnitude,
            ['price' => $ourPrice],
            ['price' => $observedPrice],
            // Hash HAM METİNDEN değil normalleştirilmiş kuruştan türer:
            // aynı fiyatın "19.90" ve "19.9" yazımları aynı satırdır ve
            // farklı hash üretselerdi her tur sahte bir gözlem değişikliği
            // kaydederdi.
            $observedPrice === null
                ? null
                : hash('sha256', (string) $this->toMinorUnits($observedPrice)),
        ];
    }

    /**
     * "19.90" → 1990. Para karşılaştırması TAM SAYI üzerinden yapılır.
     *
     * `round()` KULLANILIR ve `(int)` cast'i TEK BAŞINA YETMEZ: `19.90 * 100`
     * IEEE-754'te `1989.9999...` olabilir ve cast onu AŞAĞI keser — fiyat
     * bir kuruş düşer, karşılaştırma sahte bir çakışma üretirdi.
     */
    private function toMinorUnits(string $price): int
    {
        return (int) round(((float) $price) * 100);
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
     * FARK BULUNDUĞUNDA DOMAIN AYRIŞIR (§9 · domain başına politika) — bu,
     * akışın ayrıştığı ÜÇÜNCÜ ve son noktadır:
     *
     *   INVENTORY → `DRIFT_DETECTED` (onarım açılır, üç tur emniyetiyle)
     *   PRICE     → `PRICE_CONFLICT` (onarım AÇILMAZ, kullanıcı seçer)
     *
     * FİYATTA ÜÇ TUR EMNİYETİ SORULMAZ ve buna gerek de yoktur: emniyet
     * SONSUZ ONARIM DÖNGÜSÜNE karşıdır ("kanal 200 dönüp değişikliği
     * uygulamıyorsa aynı fark her turda yeniden onarılır") ve fiyatta zaten
     * hiç onarım açılmaz. `MANUAL_REVIEW` yazılsaydı satıcıya "otomatik
     * onarım durdu" denirdi — oysa hiç başlamamıştı; `PRICE_CONFLICT` ise
     * doğru şeyi söyler: karar SENİN.
     *
     * @return array{0: ItemStatus, 1: int|null}
     */
    private function classify(
        string $listingId,
        int $expectedRemote,
        ?int $observedRemote,
        SyncDomain $domain,
    ): array {
        // Kanal bu kimliği hiç döndürmedi: ürün orada yok.
        // Otomatik onarım YAPILMAZ — yeniden listeleme kullanıcı onayı ister
        // ve sessizce yaratmak kanalda kopya ürün açardı.
        if ($observedRemote === null) {
            return [ItemStatus::REMOTE_MISSING, null];
        }

        if ($expectedRemote === $observedRemote) {
            return [
                $this->history->awaitingRepairVerification($listingId, $domain)
                    ? ItemStatus::REPAIRED
                    : ItemStatus::MATCHED,
                null,
            ];
        }

        $magnitude = abs($expectedRemote - $observedRemote);

        if ($domain === SyncDomain::PRICE) {
            return [ItemStatus::PRICE_CONFLICT, $magnitude];
        }

        return [
            $this->history->autoRepairAllowed($listingId, $domain)
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
    private function stampObservation(Listing $listing, ?string $observedHash, SyncDomain $domain): void
    {
        DB::table('listing_sync_states')->insertOrIgnore([
            'id' => ListingSyncState::generateUuidV7(),
            'tenant_id' => $listing->tenant_id,
            'listing_id' => $listing->id,
            'domain' => $domain->value,
            'desired_version' => 0,
            'synced_version' => 0,
            'status' => 'pending',
            'error_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $state = ListingSyncState::query()
            ->where('listing_id', $listing->id)
            ->where('domain', $domain->value)
            ->first();

        if ($state === null) {
            return;
        }

        $state->forceFill([
            'remote_hash' => $observedHash,
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
        SyncDomain $domain,
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
                'domain' => $domain->value,
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
