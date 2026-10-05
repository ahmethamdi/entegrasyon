<?php

declare(strict_types=1);

namespace Tests\Unit\Privacy;

use App\Support\Privacy\PersonalDataMask;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Saklanan kanal gövdesinde alıcının kişisel verisi kalmaz; sipariş
 * işlemenin ihtiyaç duyduğu kimlikler ve kalemler kalır.
 */
final class PersonalDataMaskTest extends TestCase
{
    #[Test]
    public function trendyol_package_loses_buyer_identity_but_keeps_ids_and_lines(): void
    {
        $masked = PersonalDataMask::apply([
            'shipmentPackageId' => 3001,
            'customerId' => 77,
            'customerFirstName' => 'Ayşe',
            'customerLastName' => 'Yılmaz',
            'customerEmail' => 'ayse@example.com',
            'identityNumber' => '11111111111',
            'shipmentAddress' => ['fullName' => 'Ayşe Yılmaz', 'address1' => 'Bağdat Cd. 1', 'phone' => '0555'],
            'lines' => [['lineId' => 1, 'stockCode' => 'SKU-1', 'productName' => 'Kupa', 'quantity' => 2]],
        ]);

        $this->assertSame(3001, $masked['shipmentPackageId']);
        $this->assertSame(77, $masked['customerId']);
        $this->assertSame('SKU-1', $masked['lines'][0]['stockCode']);
        $this->assertSame('Kupa', $masked['lines'][0]['productName']);
        $this->assertSame(PersonalDataMask::MASK, $masked['shipmentAddress']);

        $json = json_encode($masked, JSON_UNESCAPED_UNICODE);
        foreach (['Ayşe', 'Yılmaz', 'ayse@example.com', '11111111111', 'Bağdat', '0555'] as $personal) {
            $this->assertStringNotContainsString($personal, (string) $json);
        }
    }

    #[Test]
    public function shopify_and_woo_orders_lose_names_addresses_and_contacts(): void
    {
        $masked = PersonalDataMask::apply([
            'id' => 5001,
            'email' => 'alici@example.com',
            'contact_email' => 'alici@example.com',
            'browser_ip' => '1.2.3.4',
            'customer' => ['id' => 9, 'first_name' => 'Ali', 'last_name' => 'Kaya', 'default_address' => ['name' => 'Ali Kaya']],
            'shipping_address' => ['name' => 'Ali Kaya', 'address1' => 'Sok. 2'],
            'billing' => ['first_name' => 'Ali', 'phone' => '123'],
            'line_items' => [['sku' => 'SKU-2', 'name' => 'Mum', 'quantity' => 1]],
        ]);

        $this->assertSame(5001, $masked['id']);
        $this->assertSame(9, $masked['customer']['id']);
        $this->assertSame('Mum', $masked['line_items'][0]['name']);
        $this->assertStringNotContainsString('Ali', (string) json_encode($masked));
        $this->assertStringNotContainsString('alici@', (string) json_encode($masked));
        $this->assertStringNotContainsString('1.2.3.4', (string) json_encode($masked));
    }
}
