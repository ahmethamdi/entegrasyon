<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Jobs;

use App\Domain\Catalog\Actions\ImportProducts;
use App\Domain\Catalog\Models\ProductImport;
use App\Support\Tenancy\TenantAwareJob;
use App\Support\Tenancy\TenantContext;
use Throwable;

/**
 * Toplu içe aktarmayı kuyrukta çalıştırır.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 3 · toplu içe aktarma,
 * §15 · kuyruk tablosu (`listing:bulk`, background havuz, 300 sn).
 *
 * DEĞİŞMEZ KURAL — İŞLEME KUYRUKTA YAPILIR:
 *   500 satırlık bir dosya HTTP isteğinde işlenirse istek zaman aşımına
 *   uğrar, kullanıcı yenilemeye basar ve aynı dosya İKİ KEZ işlenir.
 *
 * DEĞİŞMEZ KURAL — KUYRUK `listing:bulk` VE `reconciliation` İLE HAVUZ
 * PAYLAŞMAZ (§15):
 *   Toplu içe aktarma yeni müşteri kurulumunun tam ortasıdır ve arka plan
 *   havuzunu doldurur. Mutabakat turlarını atlatırsa ürünün temel iddiası
 *   tam o anda çalışmaz hâle gelir.
 *
 * DEĞİŞMEZ KURAL — İŞ KİRACI BAĞLAMINI KENDİ KURAR:
 *   `Queue::looping` kancası her iş sınırında bağlamı temizler; `handle()`
 *   her koşulda bağlamsız başlar. `TenantAwareJob::handle()` FINAL'dir ve
 *   bağlamı yükten kurup `finally` ile bırakır; alt sınıf yalnızca
 *   `handleForTenant()` yazar. Bu, bağlam kurmayı unutmayı YAPISAL olarak
 *   imkânsız kılar.
 *
 * BAĞIMLILIK CONSTRUCTOR'DAN DEĞİL, `app()` İLE ALINIR:
 *   İş serileştirilip Redis'e yazılıyor; constructor'a action enjekte
 *   edilseydi o nesne de serileştirilmeye çalışılırdı. Kuyruk işlerinde
 *   bağımlılık `handle()` imzasından çözülür ama o imza burada FINAL —
 *   bu yüzden `handleForTenant()` içinde container'dan alınır.
 *
 * YENİDEN DENEME YOK (`$tries = 1`):
 *   İçe aktarma İDEMPOTENT DEĞİLDİR ve olmamalı. Yeniden denense, ilk
 *   turda yaratılan ürünler ikinci turda GÜNCELLEME olarak sayılır ve
 *   rapor "480 güncellendi" der — oysa kullanıcı yeni ürün yüklemişti.
 *   Daha kötüsü: ilk tur yarıda kaldıysa hangi satırın işlendiği
 *   bilinmiyor. Hata durumu satıra yazılır ve kullanıcı dosyayı yeniden
 *   yükler; o karar ONUNDUR.
 */
final class ImportProductsJob extends TenantAwareJob
{
    /** Yeniden deneme YOK — gerekçe sınıf başlığında. */
    public int $tries = 1;

    /** §15 · background havuzu 300 sn'ye kadar iş kabul eder. */
    public int $timeout = 300;

    public function __construct(
        string $tenantId,
        public readonly string $importId,
    ) {
        parent::__construct($tenantId);

        $this->onQueue('listing:bulk');
    }

    protected function handleForTenant(): void
    {
        $import = ProductImport::query()->find($this->importId);

        if ($import === null) {
            return;
        }

        $import->forceFill(['status' => 'running', 'started_at' => now()])->save();

        try {
            $result = app(ImportProducts::class)->run(
                csv: $import->payload,
                warehouseId: $import->warehouse_id,
            );
        } catch (Throwable $e) {
            // SESSİZCE YUTULMAZ: kullanıcı sonucu yalnızca bu satırdan
            // öğrenir ve durum `running` kalsaydı yükleme sonsuza kadar
            // "işleniyor" görünürdü.
            $import->forceFill([
                'status' => 'failed',
                'last_error' => $e->getMessage(),
                'finished_at' => now(),
            ])->save();

            throw $e;
        }

        // ZORUNLU KOLON EKSİKSE `failed`: "0 ürün yazıldı" ile "dosyan
        // hiç işlenmedi" farklı şeylerdir ve ikincisinde kullanıcının
        // yapması gereken belli bir iş vardır.
        $import->forceFill([
            'status' => $result->headerValid ? 'completed' : 'failed',
            'created_count' => $result->created,
            'updated_count' => $result->updated,
            'errors' => $result->errors,
            'last_error' => $result->headerValid
                ? null
                : 'Zorunlu kolon eksik: '.implode(', ', $result->missingColumns),
            'finished_at' => now(),
        ])->save();
    }

    /**
     * İş kalıcı olarak düşerse durum satırı `failed` kalmalı.
     *
     * `handle()` içindeki `catch` bunu zaten yazıyor ama iş zaman aşımına
     * uğrarsa oraya HİÇ gelinmez: worker süreci öldürülür. Bu kanca o
     * boşluğu kapatır — yoksa satır sonsuza kadar `running` görünürdü.
     */
    public function failed(?Throwable $e): void
    {
        TenantContext::runFor($this->tenantId, function () use ($e): void {
            $import = ProductImport::query()->find($this->importId);

            $import?->forceFill([
                'status' => 'failed',
                'last_error' => $e?->getMessage() ?? 'İş tamamlanamadı.',
                'finished_at' => now(),
            ])->save();
        });
    }
}
