<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\N11;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * N11'in TEK SOAP çağrısı: `ReturnService.ClaimReturnList` (iade listesi).
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

        $response = $xpath->query('//*[local-name()="ClaimReturnListResponse"]')->item(0);

        if (! $response instanceof DOMElement) {
            throw new N11SoapFault('N11 yanıtında ClaimReturnListResponse yok.', 'NoResult');
        }

        $status = strtolower(trim((string) $xpath->evaluate('string(./*[local-name()="result"]/*[local-name()="status"])', $response)));

        if ($status !== 'success') {
            $code = trim((string) $xpath->evaluate('string(./*[local-name()="result"]/*[local-name()="errorCode"])', $response));
            $message = trim((string) $xpath->evaluate('string(./*[local-name()="result"]/*[local-name()="errorMessage"])', $response));

            throw new N11SoapFault('N11: '.($message !== '' ? $message : ($code !== '' ? $code : 'işlem reddedildi')), $code);
        }

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
}
