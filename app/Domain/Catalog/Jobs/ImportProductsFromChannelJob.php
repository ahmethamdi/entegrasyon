<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Jobs;

use App\Domain\Catalog\Actions\ImportProductsFromChannel;
use App\Domain\Catalog\Models\ProductImport;
use App\Domain\Channels\Models\ChannelConnection;
use App\Support\Tenancy\TenantAwareJob;
use App\Support\Tenancy\TenantContext;
use Throwable;

/**
 * Kanaldan içe aktarmayı kuyrukta çalıştırır.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 3 · madde 5, §15 · kuyruk tablosu
 * (`listing:bulk`, background havuz).
 *
 * DEĞİŞMEZ KURAL — İŞLEME KUYRUKTA YAPILIR:
 *   `ImportProductsJob` ile aynı gerekçe ve burada DAHA GÜÇLÜ: kanal
 *   turunda ürün sayısı kadar HTTP isteği de vardır (50 sayfaya kadar).
 *   HTTP isteğinde işlenseydi tur zaman aşımına uğrar, kullanıcı yenilemeye
 *   basar ve aynı katalog İKİ KEZ çekilirdi.
 *
 * DEĞİŞMEZ KURAL — KUYRUK `listing:bulk` (§15):
 *   `reconciliation` ile havuz PAYLAŞMAZ. İçe aktarma yeni müşteri
 *   kurulumunun tam ortasıdır ve arka plan havuzunu doldurur; mutabakat
 *   turlarını atlatırsa ürünün temel iddiası tam o anda çalışmaz.
 *
 * DEĞİŞMEZ KURAL — İŞ KİRACI BAĞLAMINI KENDİ KURAR:
 *   `TenantAwareJob::handle()` FINAL'dir ve bağlamı yükten kurup `finally`
 *   ile bırakır; alt sınıf yalnızca `handleForTenant()` yazar.
 *
 * YENİDEN DENEME YOK (`$tries = 1`):
 *   İçe aktarma İDEMPOTENT DEĞİLDİR — `ImportProductsJob` ile aynı
 *   gerekçe. Yeniden denense ilk turda YARATILAN ürünler ikinci turda
 *   GÜNCELLEME sayılır ve rapor yanıltır. Kanal turunda ayrıca her deneme
 *   kanal kotasını yeniden harcar.
 *
 * ZAMAN AŞIMI CSV'DEN UZUN: 50 sayfa HTTP + ürün başına transaction.
 * §15'in background havuzu 300 sn'ye kadar iş kabul eder; sınır o.
 */
final class ImportProductsFromChannelJob extends TenantAwareJob
{
    /** Yeniden deneme YOK — gerekçe sınıf başlığında. */
    public int $tries = 1;

    /** §15 · background havuzunun üst sınırı. */
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

        $connection = ChannelConnection::query()
            // adapter_class ZORUNLU: registry onu okuyarak adapter'ı çözer.
            // Seçilmezse yetenek sessizce boşalır ve tur "kanal
            // desteklemiyor" diyerek biterdi (bu tuzak projede DÖRT KEZ
            // çıktı — CLAUDE.md · eager-load kuralı).
            ->with('channelType:code,name,adapter_class')
            ->find($import->channel_connection_id);

        if ($connection === null) {
            $import->forceFill([
                'status' => 'failed',
                'last_error' => 'Kanal bağlantısı bulunamadı.',
                'finished_at' => now(),
            ])->save();

            return;
        }

        $import->forceFill(['status' => 'running', 'started_at' => now()])->save();

        try {
            $result = app(ImportProductsFromChannel::class)->run(
                connection: $connection,
                warehouseId: $import->warehouse_id,
            );
        } catch (Throwable $e) {
            // SESSİZCE YUTULMAZ: kullanıcı sonucu yalnızca bu satırdan
            // öğrenir ve durum `running` kalsaydı tur sonsuza kadar
            // "işleniyor" görünürdü.
            $import->forceFill([
                'status' => 'failed',
                'last_error' => $e->getMessage(),
                'finished_at' => now(),
            ])->save();

            throw $e;
        }

        // DESTEKLENMEYEN KANAL `failed`'DİR: "0 ürün geldi" ile "bu kanal
        // bunu yapamıyor" farklı şeylerdir ve ikincisinde kullanıcının
        // yapması gereken belli bir iş vardır (başka kanal seç).
        $import->forceFill([
            'status' => $result->supported ? 'completed' : 'failed',
            'created_count' => $result->created,
            'updated_count' => $result->updated,
            'skipped_count' => $result->skipped,
            'errors' => $result->errors,
            // ERKEN DURMA `completed` İLE BİRLİKTE YAŞAYABİLİR: tur
            // başarıyla yazdıklarını korur ama kataloğun tamamını
            // okumamıştır ve kullanıcı bunu BİLMELİDİR.
            'last_error' => $result->stopReason,
            'finished_at' => now(),
        ])->save();
    }

    /**
     * İş kalıcı olarak düşerse durum satırı `failed` kalmalı.
     *
     * `handleForTenant()` içindeki `catch` bunu yazıyor ama iş zaman
     * aşımına uğrarsa oraya HİÇ gelinmez: worker süreci öldürülür. Kanal
     * turunda zaman aşımı GERÇEK bir olasılıktır (50 sayfa HTTP).
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
