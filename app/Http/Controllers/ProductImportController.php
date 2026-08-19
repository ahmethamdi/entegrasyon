<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Catalog\Jobs\ImportProductsJob;
use App\Domain\Catalog\Models\ProductImport;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

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

    public function index(): InertiaResponse
    {
        $imports = ProductImport::query()
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get(['id', 'filename', 'status', 'created_count', 'updated_count', 'errors', 'last_error', 'created_at', 'finished_at']);

        return Inertia::render('Products/Import', [
            'rows' => $imports->map(fn (ProductImport $import): array => [
                'id' => $import->id,
                'filename' => $import->filename,
                'status' => $import->status,
                'created' => $import->created_count,
                'updated' => $import->updated_count,
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
        ]);
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
}
