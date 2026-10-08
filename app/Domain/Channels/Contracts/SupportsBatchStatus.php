<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Support\BatchStatus;

/**
 * Asenkron toplu iş sonucunu okuma yeteneği — stok/fiyat gönderimi.
 *
 * ─────────────────────────────────────────────────────────────────────
 * NEDEN VAR — "SIRAYA ALINDI" UYGULANDI DEMEK DEĞİLDİR
 * ─────────────────────────────────────────────────────────────────────
 * Trendyol, Hepsiburada, N11, Pazarama ve Çiçeksepeti stok/fiyat yükünü
 * kuyruğa alır ve yalnız bir iş kimliği döner. Satır bazındaki ret
 * (geçersiz barkod, onaysız ürün, fiyat bandı, %70 fiyat onayı) SONRADAN,
 * ayrı bir uç noktada görünür. Okunmazsa red sessiz kalır: push işi
 * "başarılı" yazar, mutabakat bir gün "fark var" der ve NEDEN hiçbir yerde
 * görünmez.
 *
 * ─────────────────────────────────────────────────────────────────────
 * SÖZLEŞME
 * ─────────────────────────────────────────────────────────────────────
 * - `batchIdFrom()` push sonucundan kanalın iş kimliğini çıkarır. Her
 *   adapter kimliği kendi adıyla taşır (`batch_request_id`, `upload_id`,
 *   `task_id`, `batch_id`); adı bilen yalnız adapter'dır, çekirdek
 *   `data` anahtarlarını TAHMİN ETMEZ.
 * - `fetchBatchStatus()` işin durumunu döner. Satır anahtarı, push yükünde
 *   kanala giden kimliktir = listing `external_id` (barkod / HB SKU /
 *   stockCode). Eşleştirme SIRAYLA DEĞİL, kimlikle yapılır.
 * - `batchRetentionSeconds()` kanalın sonucu sakladığı süre; dolunca
 *   çekirdek yoklamayı bırakır (sonsuza kadar yoklanan iş kotayı yer).
 *
 * Adapter yan etkisizdir (§7): veritabanına yazmaz, yalnız okur ve döner.
 * Durumu `ResolveChannelBatch` yazar.
 */
interface SupportsBatchStatus
{
    /** Push sonucundaki kanal iş kimliği; yoksa (boş yük, eski sonuç) null. */
    public function batchIdFrom(AdapterResult $result): ?string;

    /**
     * Kanaldaki toplu işin durumu.
     *
     * Geçici ağ/5xx hatası İSTİSNA olarak yükselir; çekirdek yoklamayı bir
     * sonraki tura bırakır. Kanalın "böyle iş yok" cevabı (404, süresi
     * dolmuş) `BatchStatus::expired()` döner — red UYDURULMAZ.
     *
     * @param  SyncDomain  $domain  INVENTORY ya da PRICE — bazı kanallarda uç nokta farklı
     */
    public function fetchBatchStatus(string $batchId, SyncDomain $domain): BatchStatus;

    /** Kanalın iş sonucunu sorgulanabilir tuttuğu süre, saniye. */
    public function batchRetentionSeconds(SyncDomain $domain): int;
}
