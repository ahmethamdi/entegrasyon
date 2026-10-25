<?php

declare(strict_types=1);

namespace App\Domain\Channels\Certification;

use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\MapsOrderState;
use App\Domain\Channels\Contracts\SupportsCatalog;
use App\Domain\Channels\Contracts\SupportsCatalogImport;
use App\Domain\Channels\Contracts\SupportsFulfillment;
use App\Domain\Channels\Contracts\SupportsInventory;
use App\Domain\Channels\Contracts\SupportsOfferLifecycle;
use App\Domain\Channels\Contracts\SupportsOrders;
use App\Domain\Channels\Contracts\SupportsPricing;
use App\Domain\Channels\Contracts\SupportsReturns;

/**
 * S15 · sertifikasyonun 12 yeteneği — TEK KAYNAK.
 *
 * "Uygulandı mı" ELLE YAZILMAZ: adaptör sınıfının arayüzlerinden okunur
 * (`requires()` — hepsi uygulanmalı; `anyOf()` — biri yeter). Site, yardım
 * ve panel tabloları da buradan okur; sitede "çalışır" deyip kodda olmayan
 * satır `ChannelCertificationContractTest`'te kırılır.
 *
 * `testNeedles()` — test taramasının (certification:scan-tests) bir test
 * yöntemini bu yetenekle eşlemesi için yöntemde ÇAĞRILMASI gereken adaptör
 * yöntemleri; `testKeywords()` boş değilse yöntem gövdesinde bunlardan biri
 * de geçmeli (iptal → "cancel", hız sınırı → "429").
 */
enum ChannelCapability: string
{
    case AUTH = 'auth';
    case PRODUCT_IMPORT = 'product_import';
    case PUBLISHING = 'publishing';
    case INVENTORY = 'inventory';
    case PRICE = 'price';
    case ORDER_IMPORT = 'order_import';
    case CANCELLATION = 'cancellation';
    case RETURN = 'return';
    case SHIPMENT = 'shipment';
    case RATE_LIMIT = 'rate_limit';
    case ERROR_RECOVERY = 'error_recovery';
    case RECONCILIATION = 'reconciliation';

    /** Yönetim ekranındaki ad. */
    public function label(): string
    {
        return match ($this) {
            self::AUTH => 'Kimlik doğrulama',
            self::PRODUCT_IMPORT => 'Ürün içe aktarma',
            self::PUBLISHING => 'Ürün yayınlama',
            self::INVENTORY => 'Stok gönderme',
            self::PRICE => 'Fiyat gönderme',
            self::ORDER_IMPORT => 'Sipariş alma',
            self::CANCELLATION => 'İptal',
            self::RETURN => 'İade talepleri',
            self::SHIPMENT => 'Kargo bildirimi',
            self::RATE_LIMIT => 'Hız sınırı',
            self::ERROR_RECOVERY => 'Hata kurtarma',
            self::RECONCILIATION => 'Mutabakat',
        };
    }

    /**
     * Satıcıya gösterilen sade cümle — iç durum adı DEĞİL. `null` = satıcıya
     * ayrı satır olarak gösterilmez (her kanalda çalışan altyapı).
     */
    public function sellerText(): ?string
    {
        return match ($this) {
            self::PRODUCT_IMPORT => 'Ürünlerini bu kanaldan 34Pazar\'a aktarabilirsin',
            self::PUBLISHING => 'Ürünü panelden bu kanala gönderebilirsin',
            self::INVENTORY => 'Başka kanalda satış olunca stok burada da düşer',
            self::PRICE => 'Panelde değiştirdiğin fiyat bu kanala gider',
            self::ORDER_IMPORT => 'Siparişler diğer kanallarla aynı listeye düşer',
            self::CANCELLATION => 'İptal edilen siparişin stoğu geri eklenir',
            self::RETURN => 'İade talepleri panelde görünür',
            self::SHIPMENT => 'Kargo takip numarası panelden bu kanala iletilir',
            self::RECONCILIATION => 'Stok farkları düzenli kontrol edilip düzeltilir',
            self::AUTH, self::RATE_LIMIT, self::ERROR_RECOVERY => null,
        };
    }

    /** @return list<class-string> HEPSİ uygulanmalı */
    public function requires(): array
    {
        return match ($this) {
            self::AUTH, self::RATE_LIMIT, self::ERROR_RECOVERY => [ChannelAdapter::class],
            self::PRODUCT_IMPORT => [SupportsCatalogImport::class],
            self::PUBLISHING => [],
            self::INVENTORY, self::RECONCILIATION => [SupportsInventory::class],
            self::PRICE => [SupportsPricing::class],
            self::ORDER_IMPORT => [SupportsOrders::class],
            // İptal sipariş durum eşlemesiyle gelir (OrderState::CANCELLED → stok geri).
            self::CANCELLATION => [SupportsOrders::class, MapsOrderState::class],
            // İade TALEBİ (claim) akışı. Siparişin iadeyle kapanması sipariş durumuyla gelir; bu satır o değil.
            self::RETURN => [SupportsReturns::class],
            self::SHIPMENT => [SupportsFulfillment::class],
        };
    }

    /** @return list<class-string> en az BİRİ uygulanmalı (boşsa koşul yok) */
    public function anyOf(): array
    {
        return match ($this) {
            // Tek adımlı yayın ya da eBay'in çok adımlı (offer) yayını.
            self::PUBLISHING => [SupportsCatalog::class, SupportsOfferLifecycle::class],
            default => [],
        };
    }

    /** @return list<string> */
    public function testNeedles(): array
    {
        return match ($this) {
            self::AUTH => ['healthCheck'],
            self::PRODUCT_IMPORT => ['fetchProductPage'],
            self::PUBLISHING => ['createListing', 'updateListing', 'upsertOffer', 'publishOffer'],
            self::INVENTORY => ['pushInventory'],
            self::PRICE => ['pushPrices'],
            self::ORDER_IMPORT => ['fetchOrders', 'parseOrderEvent'],
            self::CANCELLATION => ['fetchOrders', 'parseOrderEvent', 'orderStateFor'],
            self::RETURN => ['fetchReturnPage', 'fetchReturn'],
            self::SHIPMENT => ['pushFulfillment'],
            self::RATE_LIMIT => ['classifyError', 'rateLimitProfile'],
            self::ERROR_RECOVERY => ['classifyError'],
            self::RECONCILIATION => ['fetchInventory'],
        };
    }

    /** @return list<string> küçük harf; yöntem gövdesinde biri geçmeli */
    public function testKeywords(): array
    {
        return match ($this) {
            self::CANCELLATION => ['cancel', 'iptal'],
            self::RATE_LIMIT => ['429', 'ratelimit', 'rate_limit', 'throttl'],
            default => [],
        };
    }

    /** Sınıf bu yeteneği uyguluyor mu (arayüzlerden). */
    public function implementedBy(string $adapterClass): bool
    {
        if (! class_exists($adapterClass)) {
            return false;
        }

        foreach ($this->requires() as $interface) {
            if (! is_a($adapterClass, $interface, true)) {
                return false;
            }
        }

        $anyOf = $this->anyOf();
        if ($anyOf === []) {
            return true;
        }

        foreach ($anyOf as $interface) {
            if (is_a($adapterClass, $interface, true)) {
                return true;
            }
        }

        return false;
    }
}
