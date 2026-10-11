<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

use App\Domain\Sync\Support\RecoveredOrderPage;
use Carbon\CarbonInterface;

/**
 * Webhook kanalında KAÇAN SİPARİŞİ kurtarma yeteneği (mimari denetim P0-3).
 *
 * Webhook kanalları yoklanmaz (`supports_webhooks`); kaybolan bir webhook
 * önceden kaybolan bir sipariş demekti. Bu yetenek `reconcile:orders`
 * turuna, son penceredeki siparişleri WEBHOOK GÖVDESİ BİÇİMİNDE verir —
 * çekirdek onları aynı `IngestInboxMessage` hattından geçirir ve aynı
 * normalizer'la işler. İkinci bir sipariş yolu AÇILMAZ.
 *
 * ⚠️ OLAY KİMLİĞİ webhook'unkinden FARKLIDIR (`reconcile:{id}:{tür}`).
 * Webhook önce gelmişse sipariş tekilliği (`bağlantı`, `external_id`)
 * ikinci yaratmayı eler; iptal ise kalan adete kırpıldığı için ikinci kez
 * stok üretmez. Kimlik her turda AYNIDIR — inbox tekilleştirmesi aynı
 * siparişi 30 dakikada bir yeniden işlemeyi engeller.
 *
 * ⚠️ İADE (para/kalem) BU YOLDAN ÜRETİLMEZ: iade olayının çıpası webhook
 * kimliğidir ve farklı bir kimlikle ikinci kez gelirse stok iki kez geri
 * eklenirdi. Bilinçli olarak ertelendi (bkz. 10-IMPLEMENTATION-CHECKLIST).
 */
interface SupportsOrderReconciliation
{
    /** `$since`'ten sonra güncellenmiş siparişler; sayfalı. */
    public function fetchOrdersForReconciliation(CarbonInterface $since, ?string $cursor = null): RecoveredOrderPage;
}
