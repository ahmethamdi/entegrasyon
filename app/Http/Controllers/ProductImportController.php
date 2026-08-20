<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Catalog\Jobs\ImportProductsFromChannelJob;
use App\Domain\Catalog\Jobs\ImportProductsJob;
use App\Domain\Catalog\Models\ProductImport;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Throwable;

/**
 * Toplu ürün içe aktarma ekranı.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 3 · "Toplu içe aktarma
 * (Excel/CSV)", §17 · öncelik tablosu ("TEMEL").
 *
 * EKRANIN VARLIK SEBEBİ: satıcı 500 ürününü panelden tek tek giremez.
 * Ödeme mekanizması olsa bile ürünlerini sisteme sokamayan satıcı sistemi
 * kullanamaz.
 *
 * DEĞİŞMEZ KURAL — YÜKLEME İŞLEMEZ, KUYRUĞA ATAR:
 *   500 satırlık bir dosya HTTP isteğinde işlenirse istek zaman aşımına
 *   uğrar, kullanıcı yenilemeye basar ve aynı dosya İKİ KEZ işlenir.
 *   İstek yalnızca dosyayı kaydeder ve `listing:bulk` kuyruğuna iş atar.
 *
 * DEĞİŞMEZ KURAL — DEPO YÜKLEME ANINDA DONAR:
 *   Açılış stoğunun yazılacağı depo satıra yazılır. İş çalışırken
 *   varsayılan depo değişmiş olabilir ve o değişiklik bu yüklemeyi
 *   etkilememeli.
 *
 * DEĞİŞMEZ KURAL — INERTIA'YA MODEL GÖNDERİLMEZ: `payload` CSV'nin
 *   tamamını taşır ve panele gönderilmesi hem anlamsız hem pahalıdır.
 */
final class ProductImportController extends Controller
{
    /** Son yüklemeler; daha eskisi denetim değeri taşımaz. */
    private const HISTORY_LIMIT = 20;

    /** 500 satırlık bir CSV ~100 KB; 2 MB fazlasıyla yeterli. */
    private const MAX_KILOBYTES = 2048;

    public function __construct(private readonly AdapterRegistry $registry) {}

    public function index(): InertiaResponse
    {
        $imports = ProductImport::query()
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get(['id', 'filename', 'status', 'source', 'created_count', 'updated_count', 'skipped_count', 'errors', 'last_error', 'created_at', 'finished_at']);

        return Inertia::render('Products/Import', [
            'rows' => $imports->map(fn (ProductImport $import): array => [
                'id' => $import->id,
                'filename' => $import->filename,
                'status' => $import->status,
                // KAYNAK GÖSTERİLİR: aynı listede duran iki tur farklı
                // şeyler yapmıştır ve satıcı hangisinin dosyadan hangisinin
                // kanaldan geldiğini bilmelidir.
                'source' => $import->source,
                'created' => $import->created_count,
                'updated' => $import->updated_count,
                'skipped' => $import->skipped_count,
                // Hata listesi SATIR NUMARASI taşır: sayı tek başına
                // kullanıcıya ne yapacağını söylemez.
                'errors' => $import->errors ?? [],
                'lastError' => $import->last_error,
                'createdAt' => $import->created_at?->toIso8601String(),
                'finishedAt' => $import->finished_at?->toIso8601String(),
            ])->all(),
            'columns' => [
                'required' => ['sku', 'baslik', 'fiyat', 'stok'],
                'optional' => ['aciklama', 'marka', 'barkod', 'kategori'],
            ],
            'connections' => $this->importableConnections(),
        ]);
    }

    /**
     * Kanaldan içe aktarma turu başlatır.
     *
     * Tur KUYRUKTA çalışır — CSV yüklemesiyle aynı gerekçe ve burada daha
     * güçlü: kanal turu 50 sayfaya kadar HTTP isteği yapar.
     */
    public function storeFromChannel(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'connection_id' => ['required', 'string'],
        ]);

        $connection = $this->importableConnection($validated['connection_id']);

        $import = ProductImport::query()->create([
            'tenant_id' => TenantContext::idOrFail(),
            'filename' => $connection->label ?: $connection->external_account_id,
            'warehouse_id' => $this->defaultWarehouseId($request),
            'status' => 'pending',
            'source' => 'channel',
            'channel_connection_id' => $connection->id,
            // Kanal turunda gövde YOKTUR — ürünler çalışma anında çekilir.
            'payload' => null,
        ]);

        // İŞ EN SONDA ATILIR — CSV yolundaki gerekçenin aynısı.
        ImportProductsFromChannelJob::dispatch($import->tenant_id, $import->id);

        return redirect('/products/import')
            ->with('success', "{$import->filename} kanalından ürünler çekiliyor.");
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.self::MAX_KILOBYTES,
                // `mimes:csv` TEK BAŞINA YETMEZ: bazı sistemler CSV'yi
                // `text/plain` veya `application/vnd.ms-excel` olarak
                // bildirir. Uzantı de kontrol edilir.
                'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel',
            ],
        ]);

        $file = $validated['file'];

        $import = ProductImport::query()->create([
            'tenant_id' => TenantContext::idOrFail(),
            'filename' => $file->getClientOriginalName(),
            'warehouse_id' => $this->defaultWarehouseId($request),
            'status' => 'pending',
            // Dosyanın KENDİSİ saklanır, disk yolu değil: yol saklansaydı
            // iş çalışmadan önce dosya silinebilir veya worker başka bir
            // makinede olabilirdi.
            'payload' => (string) $file->get(),
        ]);

        // İŞ EN SONDA ATILIR: satır yazılmadan iş kuyruğa girerse worker
        // onu bulamaz. `sync` sürücüde iş DERHAL çalışır ve satır henüz
        // commit edilmemiş olurdu.
        ImportProductsJob::dispatch($import->tenant_id, $import->id);

        return redirect('/products/import')
            ->with('success', "{$import->filename} yüklendi, arka planda işleniyor.");
    }

    /**
     * Açılış stoğunun yazılacağı depo.
     *
     * "En az bir varsayılan" DB kısıtıyla zorlanmaz; `CreateTenant` garanti
     * eder. Yoksa bu bir veri bütünlüğü hatasıdır ve sessizce geçilmemeli —
     * geçilseydi 500 ürün stoksuz yazılırdı.
     */
    private function defaultWarehouseId(Request $request): string
    {
        $warehouse = $request->attributes->get('tenant')?->defaultWarehouse();

        abort_if($warehouse === null, 409, 'Kiracının varsayılan deposu yok.');

        return $warehouse->id;
    }

    // ------------------------------------------------------------- kanal

    /**
     * İçe aktarmayı destekleyen AKTİF bağlantılar.
     *
     * Yetenek `instanceof` ile okunur (§7); `if ($channel === '...')`
     * YAZILMAZ. Desteklemeyen kanal listede HİÇ görünmez — düğmeyi gösterip
     * sonra hata vermek satıcıya iş yaptırıp geri almaktır.
     *
     * @return list<array<string, mixed>>
     */
    private function importableConnections(): array
    {
        return ChannelConnection::query()
            // adapter_class DA YÜKLENİR: registry onu okuyarak yetenekleri
            // çözer. Seçilmezse yetenekler SESSİZCE boşalır ve liste boş
            // gelir (bu tuzak projede birkaç kez çıktı).
            ->with('channelType:code,name,adapter_class')
            ->where('status', 'active')
            ->orderBy('label')
            ->get()
            ->filter(fn (ChannelConnection $c): bool => $this->supportsImport($c))
            ->map(fn (ChannelConnection $c): array => [
                'id' => $c->id,
                'label' => $c->label ?: $c->external_account_id,
                'channel' => $c->channelType?->name ?? $c->channel_type_code,
            ])
            ->values()
            ->all();
    }

    /**
     * Gönderilen bağlantı gerçekten içe aktarılabilir mi.
     *
     * Üç kapı da `ValidationException` ile alan hatasına çevrilir, 404/500
     * ile değil: kullanıcı yanlış bir şey seçmiştir, sunucu bozulmamıştır.
     */
    private function importableConnection(string $connectionId): ChannelConnection
    {
        $connection = ChannelConnection::query()
            ->with('channelType:code,name,adapter_class')
            ->find($connectionId);

        if ($connection === null) {
            throw ValidationException::withMessages(['connection_id' => 'Kanal bulunamadı.']);
        }

        if ($connection->status !== 'active') {
            throw ValidationException::withMessages([
                'connection_id' => sprintf(
                    '%s bağlantısı aktif değil; önce sağlık kontrolünü geçmesi gerekiyor.',
                    $connection->label ?: $connection->external_account_id,
                ),
            ]);
        }

        if (! $this->supportsImport($connection)) {
            throw ValidationException::withMessages([
                'connection_id' => sprintf(
                    '%s kanalı kanaldan ürün çekmeyi desteklemiyor.',
                    $connection->channelType?->name ?? $connection->channel_type_code,
                ),
            ]);
        }

        return $connection;
    }

    private function supportsImport(ChannelConnection $connection): bool
    {
        try {
            return $this->registry->capabilitiesFor($connection)['catalog_import'] ?? false;
        } catch (Throwable $e) {
            // SESSİZCE YUTULMAZ: bu catch bir turda `adapter_class`
            // seçilmediğini gizlemişti ve yetenekler sebepsiz boş
            // görünmüştü.
            Log::warning('products.import_capabilities_unavailable', [
                'connection' => $connection->id,
                'channel_type' => $connection->channel_type_code,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
