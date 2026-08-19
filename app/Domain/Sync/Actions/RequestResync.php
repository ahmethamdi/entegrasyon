<?php

declare(strict_types=1);

namespace App\Domain\Sync\Actions;

use App\Domain\Messaging\Models\OutboxEvent;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\ListingSyncState;
use Illuminate\Support\Facades\DB;

/**
 * `error_permanent` durumundan çıkış — resync talebi.
 *
 * Mimari Karar Dokümanı v2.2 · §9 · error_permanent durumundan çıkış,
 * §1 · Karar 18, §18 · T10.
 *
 * DEĞİŞMEZ KURAL — DURUM DEĞİŞİKLİĞİ TEK BAŞINA HİÇBİR İŞ ÜRETMEZ.
 * Durum alanını `pending` yazmak yeterli olsaydı hiçbir şey olmazdı: kanonik
 * veri o arada DEĞİŞMEDİ ve değişmeyen veriden yeni bir domain olayı doğmaz.
 * Satır panelde "bekliyor" görünür, sonsuza kadar bekler ve kullanıcı
 * düzeltmesinin neden işe yaramadığını anlamaz. Bu yüzden her çıkış geçişi
 * AYNI TRANSACTION içinde bir `ListingResyncRequested` olayı yazar.
 *
 * BU GEÇİŞ SATIRI AKIŞA GERİ SOKAN TEK YOLDUR. `error_permanent` mutabakatta
 * ASLA aday değildir (§10 · `CandidateSelector`): düzeltilemeyecek bir listing
 * her turda kontrol edilirse bütçe boşa gider. Yani o satıra başka hiçbir
 * mekanizma dokunmaz ve bu action olmadan kalıcı hata gerçekten kalıcıdır.
 *
 * TEK GENERIC OLAY TİPİ KULLANILIR, ayrı bir olay taksonomisi kurulmaz (§9).
 * Sebep ayrımı YÜKTE yaşar (`reason`): taksonomi kurulsaydı her yeni tetik
 * yeni bir olay tipi, yeni bir tüketici dalı ve yeni bir kayıt satırı
 * gerektirirdi; oysa hepsinin yapacağı iş birebir aynıdır.
 *
 * `desired_version` ARTIRILMAZ. Artırılsaydı sürüm kapısı sonraki GERÇEK
 * değişikliği "bayat" sayar ve o değişiklik kanala hiç gitmezdi — bu projede
 * ön koşul kapısında tam bu tuzak yaşandı. Kanonik veri değişmedi, dolayısıyla
 * istenen sürüm de değişmez.
 */
final class RequestResync
{
    /** Tetikleyen durumlar — hepsi TEK generic olay kullanır (§9). */
    public const REASON_TAXONOMY_PREREQUISITE_FIXED = 'taxonomy_prerequisite_fixed';

    public const REASON_CREDENTIAL_REAUTHORIZED = 'credential_reauthorized';

    public const REASON_MANUAL_RETRY = 'manual_retry';

    public const REASON_PRICE_CONFLICT_RESOLVED = 'price_conflict_resolved';

    public const REASON_CONTENT_CORRECTED = 'content_corrected';

    /**
     * @param  string  $reason  Neden resync istendi — yükte taşınır
     */
    public function run(Listing $listing, SyncDomain $domain, string $reason): OutboxEvent
    {
        // DURUM VE OLAY AYNI TRANSACTION'DA. Ayrı olsalardı araya düşen hata
        // iki yönde de bozuk durum bırakırdı: durum `pending` ama olay yok
        // (satır sonsuza kadar bekler, hiçbir tarama onu görmez) veya olay var
        // ama durum `error_permanent` (iş üretilir, panel hâlâ hata gösterir).
        return DB::transaction(function () use ($listing, $domain, $reason): OutboxEvent {
            $this->resetState($listing, $domain);

            return OutboxEvent::record(
                aggregateType: 'listing',
                aggregateId: $listing->id,
                eventType: 'ListingResyncRequested',
                payload: [
                    'listing_id' => $listing->id,
                    'domain' => $domain->value,
                    // Sürüm YÜKTE taşınır ve tüketici onu yeniden HESAPLAMAZ:
                    // iş kuyrukta beklerken kanonik sürüm değişmiş olabilir ve
                    // o değişiklik kendi olayını doğurmuştur. Talebin hangi
                    // sürüm için yapıldığı burada donar.
                    'current_version' => $this->currentVersionFor($listing, $domain),
                    'reason' => $reason,
                ],
                tenantId: $listing->tenant_id,
            );
        });
    }

    /**
     * Sync state satırını akışa geri sokar.
     *
     * DURUM SORULMAZ — ÖN KOŞUL KOYULMAZ. "Yeniden dene" geçici hatada da,
     * takılı kalmış bekleyen satırda da meşru bir taleptir; `error_permanent`
     * şartı koymak kullanıcının elindeki tek kurtarma düğmesini keyfi biçimde
     * kilitlerdi. Fazladan bir resync'in bedeli tek bir gereksiz operasyondur.
     *
     * ESKİ HATA METNİ TEMİZLENİR ve SAYAÇ SIFIRLANIR: eski metin kalsaydı
     * panel çözülmüş bir sorunu göstermeye devam eder ve kullanıcı
     * düzeltmesinin işe yaramadığını sanardı. Sayaç ise *ardışık* hatayı
     * sayar; sıfırlanmazsa yeniden deneme bütçesi devralınmış hatalarla
     * dolu başlar.
     *
     * SÜRÜM ALANLARINA DOKUNULMAZ: `desired_version` artırılmaz (yukarıdaki
     * gerekçe) ve `synced_version` geriye alınmaz — o alan GERÇEĞİ taşır,
     * kanala ne gittiğini söyler ve mutabakat ile panel rozeti ondan beslenir.
     */
    private function resetState(Listing $listing, SyncDomain $domain): void
    {
        // Satır yoksa YARATILIR: hiç senkronlanmamış listing tam da
        // kullanıcının "yeniden dene" demek isteyeceği satırdır ve talebi
        // "satır yok" diye sessizce yutmak öğrenileni çöpe atmaktır.
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

        ListingSyncState::query()
            ->where('listing_id', $listing->id)
            ->where('domain', $domain->value)
            ->update([
                'status' => 'pending',
                'error_count' => 0,
                'last_error' => null,
                'last_requested_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Alanın kanonik iş sürümü — YAPAY OLARAK ARTIRILMAZ.
     *
     * CONTENT sürümü ürünün `content_version`'ından, stok sürümü projeksiyonun
     * `version`'ından gelir; uydurma bir sayaç panelde "senkron" görünen
     * ürünün kanala hiç gitmemesine yol açardı.
     */
    private function currentVersionFor(Listing $listing, SyncDomain $domain): int
    {
        if ($domain === SyncDomain::INVENTORY) {
            return (int) (DB::table('inventory_levels')
                ->where('tenant_id', $listing->tenant_id)
                ->where('variant_id', $listing->variant_id)
                ->value('version') ?? 0);
        }

        return (int) (DB::table('products')
            ->join('variants', 'variants.product_id', '=', 'products.id')
            ->where('variants.id', $listing->variant_id)
            ->where('products.tenant_id', $listing->tenant_id)
            ->value('products.content_version') ?? 0);
    }
}
