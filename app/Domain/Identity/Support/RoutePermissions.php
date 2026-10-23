<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

use App\Domain\Identity\Enums\Permission as P;

/**
 * Panel rotası → gereken yetki. TEK TABLO (S7 · 23 Ekim 2026).
 *
 * `AuthorizeTenantRoute` her kiracı rotasında buraya bakar. TABLODA OLMAYAN
 * ROTA 403 ALIR — varsayılan KAPALI. Yeni rota eklenip buraya yazılmazsa
 * özellik sahibine bile kapalı doğar ve `TenantRouteAuthorizationContractTest`
 * eksik adı söyler; varsayılan açık olsaydı unutulan rota depo üyesine
 * sessizce yazma izni verirdi.
 *
 * GET bir ekranı açar (görme yetkisi), POST/PUT/DELETE iş görür (yazma
 * yetkisi). İstisnalar satırında yazılı: OAuth dönüşleri GET'tir ama
 * bağlantı kurar → yazma yetkisi ister.
 */
final class RoutePermissions
{
    /** @var array<string, P> */
    private const MAP = [
        'dashboard' => P::PANEL,
        'help' => P::PANEL,
        'tenants.switch' => P::PANEL,

        // Siparişler — depo üyesinin asıl işi.
        'orders.index' => P::ORDERS_VIEW,
        'orders.show' => P::ORDERS_VIEW,
        'orders.invoice.pdf' => P::ORDERS_VIEW,
        'returns.index' => P::ORDERS_VIEW,
        'questions.index' => P::ORDERS_VIEW,
        'fulfillment.batches.show' => P::ORDERS_VIEW,
        'orders.ship' => P::ORDERS_WORK,
        'orders.ship.retry' => P::ORDERS_WORK,
        'orders.invoice' => P::ORDERS_WORK,
        'orders.invoice.retry' => P::ORDERS_WORK,
        'orders.invoice.upload.retry' => P::ORDERS_WORK,
        'orders.carrier.store' => P::ORDERS_WORK,
        'orders.carrier.cancel' => P::ORDERS_WORK,
        'orders.carrier.handover' => P::ORDERS_WORK,
        // Etiket ve toplama listesi GET'tir ama depo İŞİDİR (yazdırılır).
        'orders.carrier.label' => P::ORDERS_WORK,
        'fulfillment.batches.store' => P::ORDERS_WORK,
        'fulfillment.batches.picking' => P::ORDERS_WORK,
        'fulfillment.batches.labels' => P::ORDERS_WORK,
        'returns.approve' => P::ORDERS_WORK,
        'returns.reject' => P::ORDERS_WORK,
        'questions.answer' => P::ORDERS_WORK,

        // Muhasebe kaydı — fatura iptali / iade kaydı depo işi DEĞİL.
        'orders.invoice.cancel' => P::FINANCE_MANAGE,
        'orders.invoice.return' => P::FINANCE_MANAGE,
        'orders.invoice.correction.retry' => P::FINANCE_MANAGE,
        'reports.contribution' => P::FINANCE_VIEW,
        'reports.contribution.show' => P::FINANCE_VIEW,

        // Stok listesi (5 Ekim'den beri Ürünler ekranı).
        'products.index' => P::STOCK_VIEW,
        'inventory.index' => P::STOCK_VIEW,

        // Katalog — görme.
        'products.edit' => P::CATALOG_VIEW,
        'products.channels.index' => P::CATALOG_VIEW,
        'products.import.index' => P::CATALOG_VIEW,
        'products.bulk-edit.index' => P::CATALOG_VIEW,
        'products.bulk-edit.export' => P::CATALOG_VIEW,
        'products.bulk-edit.show' => P::CATALOG_VIEW,
        'suppliers.index' => P::CATALOG_VIEW,
        'suppliers.edit' => P::CATALOG_VIEW,
        'suppliers.runs.show' => P::CATALOG_VIEW,
        'campaigns.index' => P::CATALOG_VIEW,
        'campaigns.products' => P::CATALOG_VIEW,
        'mappings.index' => P::CATALOG_VIEW,
        'approvals.index' => P::CATALOG_VIEW,
        'reconciliation.index' => P::CATALOG_VIEW,
        'failures.index' => P::CATALOG_VIEW,
        'metrics.index' => P::CATALOG_VIEW,

        // Katalog — yazma. "Oluştur" ekranları da yazma ister: açılıp
        // gönderilemeyen form salt okunur üyeye yalnız hayal kırıklığıdır.
        'products.create' => P::CATALOG_MANAGE,
        'products.store' => P::CATALOG_MANAGE,
        'products.update' => P::CATALOG_MANAGE,
        'products.variants.cost' => P::CATALOG_MANAGE,
        'products.channels.store' => P::CATALOG_MANAGE,
        'products.listings.price' => P::CATALOG_MANAGE,
        'products.channel-attributes.store' => P::CATALOG_MANAGE,
        'products.images.channels' => P::CATALOG_MANAGE,
        'products.ai.store' => P::CATALOG_MANAGE,
        'products.ai.apply' => P::CATALOG_MANAGE,
        'products.ai.dismiss' => P::CATALOG_MANAGE,
        'products.import.store' => P::CATALOG_MANAGE,
        'products.import.channel' => P::CATALOG_MANAGE,
        'products.bulk-edit.store' => P::CATALOG_MANAGE,
        'products.bulk-edit.apply' => P::CATALOG_MANAGE,
        'inventory.adjust' => P::CATALOG_MANAGE,
        'suppliers.create' => P::CATALOG_MANAGE,
        'suppliers.store' => P::CATALOG_MANAGE,
        'suppliers.update' => P::CATALOG_MANAGE,
        'suppliers.destroy' => P::CATALOG_MANAGE,
        'suppliers.sample' => P::CATALOG_MANAGE,
        'suppliers.preview' => P::CATALOG_MANAGE,
        'suppliers.apply' => P::CATALOG_MANAGE,
        'suppliers.activate' => P::CATALOG_MANAGE,
        'campaigns.create' => P::CATALOG_MANAGE,
        'campaigns.store' => P::CATALOG_MANAGE,
        'campaigns.cancel' => P::CATALOG_MANAGE,
        'mappings.category.store' => P::CATALOG_MANAGE,
        'mappings.attribute.store' => P::CATALOG_MANAGE,
        'mappings.attribute-value.store' => P::CATALOG_MANAGE,
        'reconciliation.price-conflict' => P::CATALOG_MANAGE,
        'failures.retry' => P::CATALOG_MANAGE,

        // Kanallar.
        'channels.index' => P::CHANNELS_VIEW,
        'channels.settings.edit' => P::CHANNELS_VIEW,
        'channels.pricing.edit' => P::CHANNELS_VIEW,
        'channels.create' => P::CHANNELS_MANAGE,
        'channels.store' => P::CHANNELS_MANAGE,
        'channels.health' => P::CHANNELS_MANAGE,
        'channels.outbound' => P::CHANNELS_MANAGE,
        'channels.settings.update' => P::CHANNELS_MANAGE,
        'channels.pricing.update' => P::CHANNELS_MANAGE,
        'channels.shopify.location' => P::CHANNELS_MANAGE,
        'channels.woocommerce.authorize' => P::CHANNELS_MANAGE,
        'channels.etsy.authorize' => P::CHANNELS_MANAGE,
        'channels.ebay.authorize' => P::CHANNELS_MANAGE,
        'channels.amazon.authorize' => P::CHANNELS_MANAGE,
        'channels.otto.authorize' => P::CHANNELS_MANAGE,
        'channels.shopify.authorize' => P::CHANNELS_MANAGE,
        // OAuth dönüşleri GET'tir ama bağlantıyı KURAR.
        'channels.woocommerce.return' => P::CHANNELS_MANAGE,
        'channels.etsy.callback' => P::CHANNELS_MANAGE,
        'channels.ebay.callback' => P::CHANNELS_MANAGE,
        'channels.amazon.callback' => P::CHANNELS_MANAGE,
        'channels.otto.callback' => P::CHANNELS_MANAGE,
        'channels.shopify.callback' => P::CHANNELS_MANAGE,
        'shopify.install.finish' => P::CHANNELS_MANAGE,

        // Ayarlar (fatura, kargo hesabı).
        'settings.invoicing' => P::SETTINGS_VIEW,
        'settings.shipping' => P::SETTINGS_VIEW,
        'settings.invoicing.update' => P::SETTINGS_MANAGE,
        'settings.invoicing.destroy' => P::SETTINGS_MANAGE,
        'settings.invoicing.parasut.authorize' => P::SETTINGS_MANAGE,
        'settings.invoicing.parasut.callback' => P::SETTINGS_MANAGE,
        'settings.shipping.store' => P::SETTINGS_MANAGE,
        'settings.shipping.destroy' => P::SETTINGS_MANAGE,

        // Abonelik — yalnız sahip.
        'billing.index' => P::BILLING_MANAGE,
        'billing.checkout' => P::BILLING_MANAGE,
        'billing.shopify.return' => P::BILLING_MANAGE,

        // Ekip — yalnız sahip.
        'team.index' => P::TEAM_MANAGE,
        'team.invite' => P::TEAM_MANAGE,
        'team.invitations.resend' => P::TEAM_MANAGE,
        'team.invitations.cancel' => P::TEAM_MANAGE,
        'team.members.role' => P::TEAM_MANAGE,
        'team.members.remove' => P::TEAM_MANAGE,
    ];

    public static function for(?string $routeName): ?P
    {
        return $routeName === null ? null : (self::MAP[$routeName] ?? null);
    }

    /** @return list<string> */
    public static function routeNames(): array
    {
        return array_keys(self::MAP);
    }
}
