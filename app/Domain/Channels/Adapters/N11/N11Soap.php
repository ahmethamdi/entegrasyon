<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\N11;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * N11'in SOAP çağrıları: `ReturnService.ClaimReturnList` (iade listesi) ve
 * `SellerInvoiceService.SaveLinkSellerInvoice` (fatura bağlantısı, S6).
 *
 * Neden SOAP: iade REST statüsünde görünmez — eski "İade Edildi"
 * statüleri REST'te `Delivered` döner (`docs/YENI-KANALLAR-API-NOTLARI.md`
 * §4 · Sipariş, tuzak 5). Stok geri yükleme için tek kaynak bu liste.
 *
 * Şema: canlı WSDL (`https://api.n11.com/ws/ReturnService.wsdl`, 8 Eki 2026
 * okundu) + developer.n11.com "İade Talepleri Servisi" örnek isteği.
 *   - İstek kökü `sch:ClaimReturnListRequest` (`http://www.n11.com/ws/schemas`),
 *     şema `elementFormDefault="unqualified"` → çocuk öğeler ÖNEKSİZ ve
 *     ad alanısız (`<auth>`, `<searchData>`…). Önekli yazılsaydı sunucu
 *     alanları görmezdi.
 *   - Adres WSDL'deki `soap:address`: `https://api.n11.com/ws/returnService/`.
 *   - `soapAction=""`.
 *
 * `SoapClient` KULLANILMAZ (Ticimax ile aynı gerekçe): `ChannelHttpClient`
 * atlanır, `api_calls` günlüğü, sır maskeleme ve `Http::fake()` devre dışı
 * kalırdı.
 */
final class N11Soap
{
    public const NS_SCHEMA = 'http://www.n11.com/ws/schemas';

    public const RETURN_SERVICE_URL = 'https://api.n11.com/ws/returnService/';

    public const INVOICE_SERVICE_URL = 'https://api.n11.com/ws/sellerInvoiceService/';

    private const NS_ENVELOPE = 'http://schemas.xmlsoap.org/soap/envelope/';

    /**
     * `ClaimReturnList` zarfı.
     *
     * Tarihler `dd/mm/yyyy` (resmi sayfa). `sender` AÇIKÇA yazılır —
     * boşsa N11 `SELLER` varsayar ama varsayılana dayanmak sınıf notundaki
     * n11depom kararını görünmez kılardı.
     */
    public static function claimReturnList(
        string $appKey,
        string $appSecret,
        string $status,
        string $startDate,
        string $endDate,
        string $sender,
        int $page,
    ): string {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<soapenv:Envelope xmlns:soapenv="'.self::NS_ENVELOPE.'" xmlns:sch="'.self::NS_SCHEMA.'">'
            .'<soapenv:Header/><soapenv:Body><sch:ClaimReturnListRequest>'
            .'<auth><appKey>'.$e($appKey).'</appKey><appSecret>'.$e($appSecret).'</appSecret></auth>'
            .'<searchData><status>'.$e($status).'</status><executer></executer><searchInfoType></searchInfoType>'
            .'<searchQuery></searchQuery><sender>'.$e($sender).'</sender>'
            .'<period><startDate>'.$e($startDate).'</startDate><endDate>'.$e($endDate).'</endDate></period></searchData>'
            .'<pagingData><currentPage>'.$page.'</currentPage></pagingData>'
            .'</sch:ClaimReturnListRequest></soapenv:Body></soapenv:Envelope>';
    }

    /**
     * Yanıt → `['claims' => list<array>, 'pageCount' => int]`.
     *
     * Fault ve `result.status != success` İSTİSNADIR: boş liste "iade yok"
     * sanılır ve geri gelen ürünün stoğu hiç eklenmezdi. Yanıt öğesi hiç
     * yoksa da istisna.
     *
     * @return array{claims: list<array<string, string>>, pageCount: int}
     */
    public static function parseClaimReturnList(string $xml): array
    {
        [$xpath, $response] = self::successResponse($xml, 'ClaimReturnListResponse');

        $claims = [];

        foreach ($xpath->query('./*[local-name()="claimReturnList"]/*[local-name()="claimReturn"]', $response) as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $claim = [];

            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    $claim[(string) $child->localName] = trim($child->textContent);
                }
            }

            $claims[] = $claim;
        }

        $pageCount = (int) trim((string) $xpath->evaluate('string(./*[local-name()="pagingData"]/*[local-name()="pageCount"])', $response));

        return ['claims' => $claims, 'pageCount' => max(0, $pageCount)];
    }

    /**
     * `SellerInvoiceService.SaveLinkSellerInvoice` zarfı (fatura bağlantısı).
     *
     * Şema: canlı WSDL (`https://api.n11.com/ws/SellerInvoiceService.wsdl`,
     * 20 Eki 2026 okundu, adres `https://api.n11.com/ws/sellerInvoiceService/`)
     * + developer.n11.com "Fatura Linki Gönderme". Numara ve tarih yurt
     * içinde isteğe bağlı, e-ihracatta zorunlu; tarih ms, GMT+3.
     */
    public static function saveLinkSellerInvoice(
        string $appKey,
        string $appSecret,
        string $url,
        string $orderNumber,
        ?string $invoiceNumber,
        ?int $invoiceDateTimeMs,
    ): string {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<soapenv:Envelope xmlns:soapenv="'.self::NS_ENVELOPE.'" xmlns:sch="'.self::NS_SCHEMA.'">'
            .'<soapenv:Header/><soapenv:Body><sch:SaveLinkSellerInvoiceRequest>'
            .'<auth><appKey>'.$e($appKey).'</appKey><appSecret>'.$e($appSecret).'</appSecret></auth>'
            .'<url>'.$e($url).'</url><orderNumber>'.$e($orderNumber).'</orderNumber>'
            .($invoiceNumber === null ? '' : '<invoiceNumber>'.$e($invoiceNumber).'</invoiceNumber>')
            .($invoiceDateTimeMs === null ? '' : '<invoiceDateTime>'.$invoiceDateTimeMs.'</invoiceDateTime>')
            .'</sch:SaveLinkSellerInvoiceRequest></soapenv:Body></soapenv:Envelope>';
    }

    /** `SaveLinkSellerInvoice` yanıtı — başarı değilse istisna. */
    public static function parseSaveLinkSellerInvoice(string $xml): void
    {
        self::successResponse($xml, 'SaveLinkSellerInvoiceResponse');
    }

    /**
     * Fault ve `result.status != success` İSTİSNADIR; yanıt öğesi hiç
     * yoksa da istisna.
     *
     * @return array{0: DOMXPath, 1: DOMElement}
     */
    private static function successResponse(string $xml, string $name): array
    {
        $doc = new DOMDocument;

        if (trim($xml) === '' || @$doc->loadXML($xml, LIBXML_NONET) === false) {
            // `/ws/` bazı uçlarda kimlik reddini düz metin 403 ile döner.
            throw new N11SoapFault('N11 SOAP yanıtı okunamadı: '.mb_substr(trim($xml), 0, 120), 'NoXml');
        }

        $xpath = new DOMXPath($doc);
        $fault = $xpath->query('//*[local-name()="Fault"]')->item(0);

        if ($fault instanceof DOMElement) {
            $text = trim((string) $xpath->evaluate('string(.//*[local-name()="faultstring"])', $fault));

            throw new N11SoapFault('N11: '.($text !== '' ? $text : 'SOAP hatası'), 'Fault');
        }

        $response = $xpath->query('//*[local-name()="'.$name.'"]')->item(0);

        if (! $response instanceof DOMElement) {
            throw new N11SoapFault("N11 yanıtında {$name} yok.", 'NoResult');
        }

        $status = strtolower(trim((string) $xpath->evaluate('string(./*[local-name()="result"]/*[local-name()="status"])', $response)));

        if ($status !== 'success') {
            $code = trim((string) $xpath->evaluate('string(./*[local-name()="result"]/*[local-name()="errorCode"])', $response));
            $message = trim((string) $xpath->evaluate('string(./*[local-name()="result"]/*[local-name()="errorMessage"])', $response));

            throw new N11SoapFault('N11: '.($message !== '' ? $message : ($code !== '' ? $code : 'işlem reddedildi')), $code);
        }

        return [$xpath, $response];
    }
}
