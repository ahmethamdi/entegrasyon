<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Consumers;

use App\Domain\Inventory\Support\SellableStock;
use App\Domain\Messaging\Models\OutboxEvent;
use App\Domain\Sync\Actions\OpenSyncOperation;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Enums\SyncIntent;
use App\Domain\Sync\Jobs\PushInventory;
use App\Domain\Sync\Models\Listing;
use Illuminate\Support\Facades\DB;

/**
 * Stok değişimi fan-out tüketicisi.
 *
 * Mimari Karar Dokümanı v2.2 · §6 · Fan-out tüketicisi, §1 · Karar 11.
 *
 * DEĞİŞMEZ KURAL — FAN-OUT VE GRUPLAMA AYRIDIR:
 *   Fan-out BURADA yapılır: bir olay, varyantın canlı listing sayısı kadar
 *   ayrı sync_operation üretir. Her biri kendi sürüm kapısından geçer, kendi
 *   listing_sync_states satırını günceller, kendi hatasını taşır.
 *
 *   Gruplama InventoryBatchBuilder'ın işidir: aynı bağlantıya ait bekleyen
 *   operasyonları tek API çağrısında birleştirir. Operasyon sayısı değişmez.
 *
 *   Bu sınıf GRUPLAMA YAPMAZ; batch builder FAN-OUT YAPMAZ.
 *
 * Üç kanallı bir varyantta bu tüketici üç operasyon üretir. Biri 429 alıp
 * yeniden denemeye girdiğinde diğer ikisi bağımsız tamamlanır. Tek operasyon
 * modelinde bir kanalın hatası diğerlerinin durumunu kirletirdi ve başarılı
 * kanalların sürümleri hiç ilerlemezdi.
 */
final class InventoryLevelChangedConsumer
{
    public function __construct(
        private readonly OpenSyncOperation $openSyncOperation,
    ) {}

    public function handle(OutboxEvent $event): void
    {
        $payload = $event->payload;

        // (1) Hedef listing'ler: varyantın TÜM canlı listeleri.
        $listings = Listing::query()
            ->where('tenant_id', $event->tenant_id)
            ->where('variant_id', $payload['variant_id'])
            ->live()
            // ADR-010 · stok gönderim izni kapalı bağlantıya operasyon
            // açılmaz (her değişimde ölü iş birikmesin). İzin açılınca
            // `SetOutboundWrite` canlı satırları yeniden senkronlar; kapı
            // işte de ayrıca sorulur.
            ->whereIn('channel_connection_id', static fn ($q) => $q->select('id')
                ->from('channel_connections')
                ->where('stock_sync_enabled', true))
            ->get();

        /** @var list<string> $pending Kuyruğa atılacak operasyon kimlikleri */
        $pending = [];

        // Yankı bastırma yalnız kaynak kanala yakın zamanda BİZ yazmadıysak
        // güvenlidir — bkz. originWasRecentlyWritten(). Olay başına tek sorgu.
        $suppressOrigin = ! $this->originWasRecentlyWritten($event, $payload);

        foreach ($listings as $listing) {
            // (2) Anlık yankı bastırma — bir ENİYİLEME, doğruluk kuralı değil.
            //     Stok değişimi bu kanaldan geldiyse ona geri yazmak gereksiz —
            //     yakın zamanda ona başka bir değer gitmediyse (yarış, yukarıda).
            //     Kanal otorite dışına ÇIKARILMIYOR: mutabakat onu da kontrol
            //     eder ve gerçek bir sürüklenme bulursa onarım açar (§10).
            if ($suppressOrigin && $this->isOriginConnection($listing, $payload)) {
                continue;
            }

            // (3) Listing başına AYRI operasyon.
            //     Granülerlik: listing × domain × version.
            $operation = $this->openSyncOperation->run(
                listing: $listing,
                domain: SyncDomain::INVENTORY,
                eventVersion: (int) $payload['version'],
                intent: SyncIntent::NORMAL_SYNC,
                sourceEvent: $event,
            );

            if ($operation !== null) {
                // (4) Kimlik TOPLANIR, iş burada ATILMAZ.
                //
                //     Dispatch planlama bittikten SONRA yapılır. Gerekçe
                //     kiracı bağlamıdır: kuyruk kancaları (Queue::looping,
                //     JobProcessing) her iş sınırında bağlamı temizler ve
                //     sync sürücüde iş DERHAL çalışır. Döngünün ortasında
                //     atılırsa kalan listing'ler bağlamsız kalır ve
                //     tenant-scoped sorgu istisna fırlatır — P0 izolasyon
                //     korumasının doğru davranışı.
                $pending[] = $operation->id;
            }
        }

        // (3b) SETLER — bu varyant bir setin bileşeniyse setin stoğu da
        //      değişmiştir (türetilir, kendi olayı yoktur). Setin canlı
        //      listing'lerine AYRI operasyon açılır; sürüm SETİN sürümüdür
        //      (bileşen sürümleri toplamı + tanım sürümü, `SellableStock`).
        //      Bileşenin kendi sürümü verilseydi iki bileşenli sette küçük
        //      sürümlü bileşenin değişimi kapıda "eski" sayılıp elenirdi.
        //
        //      Yankı bastırma YOK: kanal kendi tarafında yalnız satılan ilanı
        //      düşürür, aynı bileşeni içeren diğer setlerin ilanını değil.
        //      Mutlak değer gider; tekrar zararsızdır.
        foreach ($this->bundleListings($event, $payload) as [$listing, $version]) {
            $operation = $this->openSyncOperation->run(
                listing: $listing,
                domain: SyncDomain::INVENTORY,
                eventVersion: $version,
                intent: SyncIntent::NORMAL_SYNC,
                sourceEvent: $event,
            );

            if ($operation !== null) {
                $pending[] = $operation->id;
            }
        }

        // (4) PLANLAMA bitti. Downstream başarısını BEKLEMEZ.
        //
        //     consumed_at senkronun başarısını göstermez. Bir bağlantının
        //     token'ı süresi dolduğu için tüm operasyonları kalıcı hataya
        //     düşse bile olay consumed kalır ve yeniden yayınlanmaz; o hata
        //     operasyon seviyesinde yaşar ve panelde çözülür.
        //
        //     Erken çıkışta da (hiç listing yok, hepsi kapıya takıldı)
        //     damgalanmak ZORUNDADIR: aksi halde seviye 1 bütünlük taraması
        //     olayı kayıp sanar ve sonsuza kadar yeniden yayınlar.
        $event->markConsumed(operationsPlanned: count($pending));

        // (5) İşler EN SON atılır — planlama ve damgalama bittikten sonra.
        //
        //     Operasyon başına AYRI iş: gruplama kuyrukta değil
        //     InventoryBatchBuilder'da yapılır. Aynı bağlantıdaki diğer
        //     operasyonlar ilk işin yükünde gittiyse kalan işler boş yükle
        //     erken çıkar — ucuzdur ve teslim garantisini tek bir işe
        //     bağlamaktan güvenlidir.
        foreach ($pending as $operationId) {
            PushInventory::dispatch($operationId, $event->tenant_id)->onQueue('inventory:high');
        }
    }

    /**
     * Kaynak kanala son pencerede başka bir değişimin gönderimi gitmiş olabilir mi?
     *
     * YANKI BASTIRMA YARIŞI (S7 yük testi, 23 Ekim 2026 — 700 siparişte 7
     * satır kanalda +1/+2): B kanalındaki satışın paketi A'ya, A'nın kendi
     * siparişi bizde işlenmeden ÖNCE hazırlanır ve pazaryerinin kendi
     * düşüşünden SONRA varırsa o düşüşü ezer. A'nın siparişinden doğan olay
     * A'ya bastırıldığı için değer, ürün bir daha değişene ya da mutabakat
     * düzeltene kadar fazla kalır — fazla satış riski tam satan üründe.
     *
     * Kural: varyantta pencere içinde KAYNAK DIŞI bir hareket varsa (başka
     * kanalın satışı, elle düzeltme, içe aktarma — hepsi kaynağa da gönderim
     * doğurur) kaynak kanal da hedef olur; mutlak değer gittiği için tekrar
     * zararsızdır, sürüm kapısı ve paket birleştirme fazlasını eler. Pencere
     * sıcak mutabakatın "son 30 dakikada satıldı" penceresiyle aynı
     * varsayılanı taşır (§10) ve `entegrasyon.sync.origin_echo_window_minutes`
     * ile ayarlanır; 0 eski davranıştır (hep bastır).
     *
     * Olayın KENDİ hareketi ayrıca dışlanmaz: origin_connection_id o
     * hareketin bağlantısıdır, kaynak dışı filtresi onu zaten eler.
     *
     * @param  array<string, mixed>  $payload
     */
    private function originWasRecentlyWritten(OutboxEvent $event, array $payload): bool
    {
        $origin = $payload['origin_connection_id'] ?? null;
        $minutes = (int) config('entegrasyon.sync.origin_echo_window_minutes', 30);

        if ($origin === null || $minutes <= 0) {
            return false;
        }

        // `DB::table()` global scope'a tabi değil — kiracı filtresi açık.
        // `movements_variant_idx` (tenant_id, variant_id, occurred_at).
        return DB::table('inventory_movements')
            ->where('tenant_id', $event->tenant_id)
            ->where('variant_id', $payload['variant_id'])
            ->where('occurred_at', '>', now()->subMinutes($minutes))
            ->where(static fn ($q) => $q->whereNull('channel_connection_id')
                ->orWhere('channel_connection_id', '<>', $origin))
            ->exists();
    }

    /**
     * Bileşeni içeren setlerin canlı listing'leri ve setin GÜNCEL sürümü.
     *
     * Sürüm olay anında değil okuma anında hesaplanır: aynı anda iki
     * bileşen değiştiyse ikinci olay daha büyük toplamı görür ve kapıdan
     * geçer; birincisi aynı toplamla gelirse kapı onu eler — ikisi de
     * kanala güncel değeri götüreceği için kayıp yoktur.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{0: Listing, 1: int}>
     */
    private function bundleListings(OutboxEvent $event, array $payload): array
    {
        $bundleIds = SellableStock::bundlesContaining($event->tenant_id, (string) $payload['variant_id']);

        if ($bundleIds === []) {
            return [];
        }

        $listings = Listing::query()
            ->where('tenant_id', $event->tenant_id)
            ->whereIn('variant_id', $bundleIds)
            ->live()
            ->whereIn('channel_connection_id', static fn ($q) => $q->select('id')
                ->from('channel_connections')
                ->where('stock_sync_enabled', true))
            ->get();

        if ($listings->isEmpty()) {
            return [];
        }

        $views = SellableStock::forVariants($event->tenant_id, $bundleIds, $payload['warehouse_id'] ?? null);

        return $listings
            ->map(static fn (Listing $listing): array => [$listing, $views[$listing->variant_id]->version ?? 0])
            ->values()
            ->all();
    }

    /**
     * Olayın kaynağı bu listing'in bağlantısı mı?
     *
     * origin_connection_id yükte yoksa (elle düzeltme, import, mutabakat)
     * hiçbir kanal dışlanmaz.
     *
     * @param  array<string, mixed>  $payload
     */
    private function isOriginConnection(Listing $listing, array $payload): bool
    {
        $origin = $payload['origin_connection_id'] ?? null;

        return $origin !== null && $listing->channel_connection_id === $origin;
    }
}
