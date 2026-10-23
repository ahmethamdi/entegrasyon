<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/**
 * Panel yetkisi — S7 · çok kullanıcı (23 Ekim 2026).
 *
 * Yetki ROTA ADINA bağlanır (`RoutePermissions`), kontrolcüye DEĞİL: tek
 * kapı `AuthorizeTenantRoute` ara katmanıdır. Kontrolcü başına `authorize()`
 * yazılsaydı yeni eklenen bir eylem unutulur ve SESSİZCE açık kalırdı;
 * eşlemesi olmayan rota bu düzende 403 alır ve sözleşme testi onu yakalar.
 *
 * GÖRME ve YAZMA AYRIDIR: salt okunur üye her ekranı açar ama hiçbir
 * düğmesi iş görmez; depo üyesi siparişte çalışır ama ürün ekranına giremez.
 *
 * DEĞER METİN OLARAK Inertia'ya gider ve menü onu okur — değiştirmek
 * menüyü sessizce boşaltır (`RolePermissionContractTest`).
 */
enum Permission: string
{
    /** Ana sayfa, yardım, kiracı değiştirme — her üye. */
    case PANEL = 'panel';

    /** Sipariş, iade, müşteri sorusu listeleri ve ayrıntıları. */
    case ORDERS_VIEW = 'orders.view';

    /** Kargo, etiket, toplama listesi, fatura kesme, iade kararı, soru cevabı. */
    case ORDERS_WORK = 'orders.work';

    /** Ürün/stok listesi (stok GÖRÜNTÜLEME). */
    case STOCK_VIEW = 'stock.view';

    /** Ürün ayrıntısı, kanal eşleme, tedarikçi, kampanya, mutabakat ekranları. */
    case CATALOG_VIEW = 'catalog.view';

    /** Ürün/stok/fiyat/kampanya/tedarikçi/eşleme yazma, başarısızları yeniden deneme. */
    case CATALOG_MANAGE = 'catalog.manage';

    /** Kanallar listesi ve ayarları. */
    case CHANNELS_VIEW = 'channels.view';

    /** Kanal bağlama, OAuth, yazma izni, kanal ayarı ve fiyat kuralı. */
    case CHANNELS_MANAGE = 'channels.manage';

    /** Katkı (kâr) raporu. */
    case FINANCE_VIEW = 'finance.view';

    /** Fatura iptali, iade kaydı ve düzeltmeler — muhasebe kaydı. */
    case FINANCE_MANAGE = 'finance.manage';

    /** Fatura ve kargo ayarları ekranları. */
    case SETTINGS_VIEW = 'settings.view';

    /** Paraşüt / kargo hesabı bağlama ve kaldırma. */
    case SETTINGS_MANAGE = 'settings.manage';

    /** Abonelik ekranı ve ödeme — yalnız sahip. */
    case BILLING_MANAGE = 'billing.manage';

    /** Ekip: davet, rol, çıkarma — yalnız sahip. */
    case TEAM_MANAGE = 'team.manage';
}
