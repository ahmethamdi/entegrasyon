<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Support\ListingPayload;

/**
 * Çok adımlı, ARA KİMLİKLİ yayın yeteneği — V3.0'ın 9. capability arayüzü.
 *
 * V3.0 · §03 · Delta 1 · §13.1 · §13.2.
 *
 * NUMARALANDIRMA: v2.2 sonunda sekiz capability arayüzü vardı; bu 9.,
 * `SupportsTokenRefresh` 10.'dur. İkisi de mevcutların davranışını
 * DEĞİŞTİRMEZ — yalnızca genişletir.
 *
 * ═════════════════════════════════════════════════════════════════════
 * NEDEN `SupportsCatalog` YETMİYOR — ARA BAŞARISIZLIK KURTARILAMAZ
 * ═════════════════════════════════════════════════════════════════════
 * `createListing()` yayını TEK ÇAĞRI varsayar ve `external_id` döner.
 * eBay'de yayın ÜÇ ADIMDIR ve ÜÇ ayrı kimlik doğar; İKİSİ KALICIDIR:
 *
 *     inventory item (SKU)  →  offer (fiyat+miktar)  →  published listing
 *          PUT                   POST /offer            POST .../publish
 *      idempotent              offer_id döner          listing_id döner
 *
 * Üç çağrıyı `createListing()` içinde zincirlemek MÜMKÜN ama ara
 * başarısızlık KURTARILAMAZ:
 *
 *     upsertInventoryItem  ✅
 *     upsertOffer          ✅  offer_id = 8912345
 *     publishOffer         ❌  429
 *
 * v2.2 kuralı "başarısızlıkta `external_id` YAZILMAZ" der; o kural
 * doğrudur ama burada `offer_id` de KAYBOLURDU. Sonraki tur baştan
 * başlar, `POST /offer` İKİNCİ KEZ çağrılır ve eBay `25002` (duplicate
 * offer) döner — `VALIDATION`, yani KALICI hata. Listing "düzeltilemez"
 * damgasıyla ölür ve satıcı sebebini asla bulamaz.
 *
 * Bu arayüzle her adım KENDİ sonucunu yazar ve sonraki tur
 * `channel_metadata`'ya bakıp KALDIĞI YERDEN devam eder:
 *
 *     if (metadata.listing_id)   → güncelle
 *     elseif (metadata.offer_id) → publishOffer      ← kaldığı yer
 *     else                       → tam zincir
 *
 * ⚠️ BU, IDEMPOTENCY'NİN KANAL TARAFINDAKİ KARŞILIĞIDIR. Projede
 * idempotency çıpası hep BİZİM tarafımızdaydı (`MovementKey`,
 * `external_event_id`, `(order_id, type, external_ref)`); burada çıpa
 * KANALIN VERDİĞİ ARA KİMLİKTİR ve saklanmazsa idempotency kaybolur.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ BU ARAYÜZ eBay'E ÖZGÜ DEĞİLDİR — ADI DA ONU SÖYLER
 * ─────────────────────────────────────────────────────────────────────
 * `SupportsEbayOffer` DEĞİL `SupportsOfferLifecycle`. Amazon SP-API'nin
 * feed tabanlı akışı (`submitFeed` → `getFeedResult`) da aynı "çok
 * adımlı, ara kimlikli" deseni taşır ve bu arayüzün ikinci uygulayıcısı
 * olmaya adaydır. Kanal adı taşıyan bir arayüz, ikinci kanalda ya
 * yeniden yazılır ya da yanlış adla yaşardı.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ KİMLİK EŞLEMESİ — `external_id` = `listing_id`, `offer_id` DEĞİL
 * ─────────────────────────────────────────────────────────────────────
 * `external_id` "kanalda GÖRÜNEN ürün"dür; panel onu link olarak gösterir
 * ve mutabakat onunla sorgular. `offer_id` bir ARA kimliktir ve satıcı
 * onu hiçbir yerde görmez — `channel_metadata->>'offer_id'` içinde yaşar.
 *
 * AMA STOK VE FİYAT `offer_id` İLE YAZILIR (§13.4), bu yüzden ikisi de
 * KALICI olarak saklanmak zorundadır. `offer_id` kaybedilirse listing'e
 * bir daha stok gönderilemez ve yeniden yaratmak `25002` verir.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ ADAPTER YİNE YAN ETKİSİZDİR
 * ─────────────────────────────────────────────────────────────────────
 * Bu metotların hiçbiri veritabanına YAZMAZ. Her biri `AdapterResult`
 * döner ve dönen `channel_metadata` anahtarlarını ÇEKİRDEK
 * (`PushOfferListing`) kalıcılaştırır — v2.2'nin değişmez kuralı.
 *
 * Registry anahtarı: `offer_lifecycle`. Panel bu yeteneği görünce
 * `PushListing` yerine `PushOfferListing` işini kullanır (§13).
 */
interface SupportsOfferLifecycle
{
    /**
     * SKU'yu envantere yazar — İDEMPOTENT (PUT).
     *
     * İdempotentlik burada bir kolaylık değil ZORUNLULUKTUR: zincirin ilk
     * adımı ara başarısızlıktan sonra YENİDEN çağrılır ve ikinci çağrı
     * bir kopya YARATMAMALIDIR.
     *
     * Kimlik SKU'nun KENDİSİDİR — uzak bir kimlik DÖNMEZ ve bu yüzden
     * `channel_metadata`'ya yazılacak bir şey de yoktur.
     */
    public function upsertInventoryItem(Listing $listing, ListingPayload $payload): AdapterResult;

    /**
     * Offer yaratır veya günceller; `offer_id` döner.
     *
     * ⚠️ DÖNEN KİMLİK `channel_metadata['offer_id']` OLARAK YAZILIR ve
     * bu, zincirin KURTARMA ÇIPASIDIR. Yazılmazsa sonraki tur ikinci bir
     * offer yaratır ve `25002` alır.
     *
     * `channel_metadata`'da `offer_id` ZATEN varsa bu metot GÜNCELLEME
     * yapar (PUT) — yeniden yaratmaz.
     */
    public function upsertOffer(Listing $listing, ListingPayload $payload): AdapterResult;

    /**
     * Offer'ı yayına alır; `listing_id` döner.
     *
     * Dönen kimlik `external_id` olur — satıcının kanalda GÖRDÜĞÜ ilan.
     */
    public function publishOffer(Listing $listing): AdapterResult;

    /**
     * Yayından kaldırır — SİLMEZ (v2.2 · `delist` kuralı).
     *
     * ⚠️ `DELETE /offer/{id}` KULLANILMAZ. Silme geri alınamaz ve
     * `offer_id`'yi de götürür; o kimlik kaybedilirse listing'e bir daha
     * stok gönderilemez ve yeniden yaratmak `25002` verir. Ayrıca silme
     * kanaldaki satış geçmişini, sıralamayı ve SEO izini de götürür.
     */
    public function withdrawOffer(Listing $listing): AdapterResult;
}
