<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Hepsiburada;

use App\Domain\Returns\Support\RemoteReturn;
use App\Domain\Returns\Support\RemoteReturnItem;
use App\Domain\Returns\Support\RemoteReturnPage;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use Throwable;

/**
 * Hepsiburada iade taleplerini OKUMA — S4 (canlı doğrulanmadı: HB kimlik
 * bilgisi yok).
 *
 * Kaynak: `docs/hepsiburada-openapi/talep-iade.json` (model.Claim) ve
 * portaldeki "Talep Önemli Bilgiler" (13 Eki okundu).
 *
 * KAPSAM: yalnız okuma. KARAR (accept/reject) YAZILMADI — belge kendisiyle
 * çelişiyor (gövdesiz onay bir yerde "değişim", bir yerde "iade" olarak
 * onaylanır) ve onay seçeneklerinden biri "ürün müşteride kalsın, para
 * iadesi"dir: kabul FİZİKSEL VARIŞI KANITLAMAZ. Bu yüzden S4 yolundan HB
 * stoğu dönmez; S4 öncesi `Accepted` stok yolu sipariş yoklamasında aynen
 * duruyor (bkz. fetchAcceptedClaims).
 *
 * Talep modelinde SİPARİŞ KALEMİ KİMLİĞİ YOK: eşleşme sipariş numarası +
 * SKU iledir (HB kodu → satıcı SKU'su); belirsizse kalem eşleşmez.
 */
trait ReadsClaims
{
    public function returnWindowDays(): int
    {
        return self::CLAIM_LOOKBACK_DAYS;
    }

    /** Süzgeç talebin AÇILIŞINA bakar: son 30 gün her turda yeniden okunur. */
    public function returnRescanDays(): int
    {
        return self::CLAIM_LOOKBACK_DAYS;
    }

    public function fetchReturnPage(CarbonInterface $from, CarbonInterface $to, int $page): RemoteReturnPage
    {
        $response = $this->client->get(
            endpoint: $this->orderPath(HepsiburadaEndpoints::CLAIMS),
            query: [
                'offset' => $page * self::ORDER_PAGE_SIZE,
                'limit' => self::ORDER_PAGE_SIZE,
                'beginDate' => CarbonImmutable::instance($from)->setTimezone(self::CHANNEL_TIMEZONE)->format('Y-m-d H:i'),
                'endDate' => CarbonImmutable::instance($to)->setTimezone(self::CHANNEL_TIMEZONE)->format('Y-m-d H:i'),
            ],
            headers: $this->defaultHeaders(),
        );

        // Hata sayfası "talep yok" sayılmaz.
        $response->throw();

        $body = $response->json();
        $claims = array_values(array_filter(array_is_list((array) $body) ? (array) $body : (array) ($body['items'] ?? []), 'is_array'));
        $skus = $this->merchantSkusByHbSku(array_map(static fn (array $c): string => trim((string) ($c['sku'] ?? '')), $claims));

        $returns = [];

        foreach ($claims as $claim) {
            $number = trim((string) ($claim['number'] ?? $claim['id'] ?? ''));

            if ($number === '') {
                continue;
            }

            $hbSku = trim((string) ($claim['sku'] ?? ''));

            $returns[] = new RemoteReturn(
                externalClaimId: $number,
                items: [new RemoteReturnItem(
                    // HB'de talep = tek kalem; ekonomik kimlik talep numarası.
                    externalItemId: $number,
                    quantity: max(0, (int) ($claim['quantity'] ?? 0)),
                    channelStatus: self::hbText($claim['status'] ?? null),
                    externalOrderLineId: null,
                    sku: $hbSku === '' ? null : ($skus[$hbSku] ?? strtoupper($hbSku)),
                    barcode: $hbSku === '' ? null : $hbSku,
                    productName: null,
                    reasonCode: self::hbText($claim['claimType'] ?? null),
                    reasonText: self::hbText($claim['claimType'] ?? null),
                    customerNote: self::hbText($claim['explanation'] ?? null, 2000),
                    acceptDetail: self::hbText($claim['finalizedWith'] ?? null),
                )],
                orderNumber: self::hbText($claim['orderNumber'] ?? null),
                packageId: null,
                claimType: self::hbText($claim['claimType'] ?? null),
                claimedAt: self::hbTime($claim['claimDate'] ?? null),
                modifiedAt: null,
            );
        }

        return new RemoteReturnPage($returns, $page, count($claims) >= self::ORDER_PAGE_SIZE);
    }

    /** HB'de talep numarasıyla tekil sorgu ucu belgelenmemiş. */
    public function fetchReturn(string $externalClaimId): ?RemoteReturn
    {
        return null;
    }

    private static function hbText(mixed $value, int $max = 64): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    private static function hbTime(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, self::CHANNEL_TIMEZONE)->utc()->toDateTimeImmutable();
        } catch (Throwable) {
            return null;
        }
    }
}
