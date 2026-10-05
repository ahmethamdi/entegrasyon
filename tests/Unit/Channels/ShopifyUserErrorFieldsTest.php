<?php

declare(strict_types=1);

namespace Tests\Unit\Channels;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Her mutation yalnız KENDİ hata tipinde var olan alanları ister.
 *
 * `userErrors` tipi mutation'a göre değişir: `productSet` →
 * `ProductSetUserError` (`code` VAR), `productUpdate` → `UserError`
 * (`code` YOK). Var olmayan alan istenirse Shopify sorguyu BÜTÜNÜYLE
 * reddeder ("Field 'code' doesn't exist on type 'UserError'"). 5 Ekim'de
 * gerçek mağazada bulundu: ürün içerik güncellemesi ve kargo takip
 * numarası Shopify'a HİÇ gitmiyordu; testler HTTP'yi sahtelediği için
 * göremiyordu.
 *
 * Liste 2026-01 şemasından (introspection, 34pazar-test) alındı. Yeni bir
 * mutation eklenince buraya hata tipiyle birlikte yazılmalı — liste dışı
 * mutation testi kırar ki biri şemaya bakmadan geçemesin.
 */
final class ShopifyUserErrorFieldsTest extends TestCase
{
    /** mutation adı => hata tipinde `code` var mı */
    private const ERROR_TYPE_HAS_CODE = [
        'productSet' => true,
        'productUpdate' => false,
        'productVariantsBulkUpdate' => true,
        'inventorySetOnHandQuantities' => true,
        'inventorySetQuantities' => true,
        'fulfillmentCreate' => false,
        'fulfillmentCreateV2' => false,   // şemada kullanımdan kalkmış işaretli
        'webhookSubscriptionCreate' => false,
    ];

    #[Test]
    public function mutations_only_request_fields_their_error_type_has(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../../app/Domain/Channels/Adapters/Shopify/ShopifyAdapter.php');

        // Yalnız GraphQL blokları taranır — yorumlardaki "mutation" kelimesi değil.
        preg_match_all("/<<<'GQL'(.*?)\\n\\s*GQL/s", $source, $blocks);

        $matches = [];

        foreach ($blocks[1] as $block) {
            if (preg_match('/^\s*mutation\b[^{]*\{\s*(\w+)\s*\(.*?userErrors\s*\{([^}]*)\}/s', $block, $match) === 1) {
                $matches[] = $match;
            }
        }

        $this->assertGreaterThanOrEqual(6, count($matches), 'Mutation bulunamadı — desen kaynakla uyumsuz.');

        foreach ($matches as [, $mutation, $fields]) {
            $this->assertArrayHasKey(
                $mutation,
                self::ERROR_TYPE_HAS_CODE,
                "`{$mutation}` listede yok: hata tipini şemadan bakıp ekle.",
            );

            if (! self::ERROR_TYPE_HAS_CODE[$mutation]) {
                $this->assertDoesNotMatchRegularExpression(
                    '/\bcode\b/',
                    $fields,
                    "`{$mutation}` hata tipinde `code` YOK — Shopify sorguyu reddeder.",
                );
            }
        }
    }
}
