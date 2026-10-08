<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Ticimax;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Ticimax WCF servisleri için SOAP 1.1 zarfı kurar ve yanıtı diziye çevirir.
 *
 * `SoapClient` KULLANILMAZ: ext-soap imajda yok, ama asıl gerekçe şu —
 * `SoapClient` `ChannelHttpClient`'ı atlar; `api_calls` günlüğü, sır
 * maskeleme, SSRF koruması ve testlerin `Http::fake()`'i devre dışı kalırdı.
 *
 * Şema: mağazaların canlı WSDL'i (`demo.ticimax.com/Servis/*.svc?singleWsdl`,
 * 8 Eki 2026). Resmî PDF'ler 2020–2021'den kalma ve WSDL'den farklı.
 *
 * ⚠️ ALAN SIRASI SÖZLEŞMEDİR. WCF DataContract alanları ORDİNAL alfabetik
 * sırayla bekler (`ParaBirimi10Id` < `ParaBirimi1Id` < `ParaBirimiID`) ve
 * sırası bozuk alanı HATA VERMEDEN yok sayar — değer sessizce varsayılana
 * (0) düşer. Bu yüzden karmaşık tiplerin alanları her zaman `ksort(SORT_STRING)`
 * ile yazılır.
 */
final class TicimaxSoap
{
    /** Servis sözleşmesi ve doğrudan parametreler. */
    public const NS_SERVICE = 'http://tempuri.org/';

    /** Karmaşık tiplerin (UrunFiltre, Varyasyon…) alanları. */
    public const NS_DATA = 'http://schemas.datacontract.org/2004/07/';

    /** `ArrayOfint` / `ArrayOfstring` öğeleri. */
    public const NS_ARRAYS = 'http://schemas.microsoft.com/2003/10/Serialization/Arrays';

    private const NS_ENVELOPE = 'http://schemas.xmlsoap.org/soap/envelope/';

    private const NS_XSI = 'http://www.w3.org/2001/XMLSchema-instance';

    /**
     * İstek zarfı.
     *
     * `$params` doğrudan parametrelerdir (`UyeKodu`, `f`, `s`, `siparisId`)
     * ve SIRALARI WSDL'deki gibi verilmelidir — bunlar DataContract değil
     * mesaj parçasıdır, alfabetik sıralanmaz. Değer dizi ise karmaşık tiptir.
     *
     * Dizi değeri: ilişkisel → karmaşık tip; `['@list' => 'Varyasyon',
     * 'items' => [...]]` → nesne listesi; `['@ints' => [...]]` /
     * `['@strings' => [...]]` → ilkel dizi.
     *
     * @param  array<string, mixed>  $params
     */
    public static function envelope(string $operation, array $params): string
    {
        $body = '';

        foreach ($params as $name => $value) {
            $body .= self::element((string) $name, $value, prefix: '');
        }

        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<s:Envelope xmlns:s="'.self::NS_ENVELOPE.'" xmlns:i="'.self::NS_XSI.'"'
            .' xmlns:d="'.self::NS_DATA.'" xmlns:a="'.self::NS_ARRAYS.'">'
            .'<s:Body><'.$operation.' xmlns="'.self::NS_SERVICE.'">'.$body.'</'.$operation.'></s:Body>'
            .'</s:Envelope>';
    }

    /** SOAPAction başlığı — `http://tempuri.org/IUrunServis/SelectUrun`. */
    public static function action(string $service, string $operation): string
    {
        return self::NS_SERVICE.'I'.$service.'/'.$operation;
    }

    /**
     * Yanıttaki `{Operation}Result` öğesi — diziye çevrilmiş.
     *
     * SOAP Fault İSTİSNA olur (`TicimaxSoapFault`); HTTP 500 ile gelse bile
     * önce fault okunur ki kanalın asıl mesajı kaybolmasın. Sonuç öğesi
     * hiç yoksa da istisna: boş sonuç "kanalda hiçbir şey yok" sanılırdı.
     */
    public static function result(string $xml, string $operation): mixed
    {
        $doc = new DOMDocument;

        if (trim($xml) === '' || @$doc->loadXML($xml, LIBXML_NONET) === false) {
            throw new TicimaxSoapFault('Ticimax yanıtı okunamadı (XML değil).', 'NoXml');
        }

        $xpath = new DOMXPath($doc);

        $fault = $xpath->query('//*[local-name()="Fault"]')->item(0);

        if ($fault instanceof DOMElement) {
            $text = trim((string) $xpath->evaluate('string(.//*[local-name()="faultstring"])', $fault));
            $code = trim((string) $xpath->evaluate('string(.//*[local-name()="faultcode"])', $fault));

            throw new TicimaxSoapFault('Ticimax: '.($text !== '' ? $text : 'SOAP hatası'), $code);
        }

        $node = $xpath->query('//*[local-name()="'.$operation.'Result"]')->item(0);

        if (! $node instanceof DOMElement) {
            throw new TicimaxSoapFault("Ticimax yanıtında {$operation}Result yok.", 'NoResult');
        }

        return self::toValue($node);
    }

    /**
     * Liste normalleştirme: WCF dizisi `['Varyasyon' => tek|liste]` gelir;
     * tek öğeli dizi liste DEĞİL nesne olarak çözülür (üçüncü taraf
     * kütüphanenin "tek veri varsa diziye al" notu). Burada her zaman liste.
     *
     * @return list<array<string, mixed>>
     */
    public static function items(mixed $container, string $child): array
    {
        if (! is_array($container) || ! array_key_exists($child, $container)) {
            return [];
        }

        $value = $container[$child];

        if (! is_array($value)) {
            return [];
        }

        $list = array_is_list($value) ? $value : [$value];

        return array_values(array_filter($list, 'is_array'));
    }

    /**
     * Öğe METİN olarak kurulur: DOM kopuk oluşturulan her öğede ad alanını
     * yeniden bildirir; önekler kökte bir kez tanımlı. Değerler kaçışlıdır.
     */
    private static function element(string $name, mixed $value, string $prefix = 'd:'): string
    {
        $tag = $prefix.$name;

        if ($value === null) {
            return '<'.$tag.' i:nil="true"/>';
        }

        if (! is_array($value)) {
            return '<'.$tag.'>'.self::escape(self::scalar($value)).'</'.$tag.'>';
        }

        $inner = '';

        if (isset($value['@ints']) || isset($value['@strings'])) {
            $type = isset($value['@ints']) ? 'int' : 'string';

            foreach ($value['@ints'] ?? $value['@strings'] as $item) {
                $inner .= '<a:'.$type.'>'.self::escape(self::scalar($item)).'</a:'.$type.'>';
            }
        } elseif (isset($value['@list'])) {
            foreach ($value['items'] ?? [] as $item) {
                $inner .= self::element((string) $value['@list'], $item);
            }
        } else {
            // Karmaşık tip: ORDİNAL alfabetik (sınıf notu).
            ksort($value, SORT_STRING);

            foreach ($value as $field => $fieldValue) {
                $inner .= self::element((string) $field, $fieldValue);
            }
        }

        return '<'.$tag.'>'.$inner.'</'.$tag.'>';
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_float($value) => rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.') ?: '0',
            default => (string) $value,
        };
    }

    /**
     * Öğe → değer: yapraksa metin (nil → null), değilse ilişkisel dizi;
     * aynı adla tekrar eden çocuk listeye toplanır.
     */
    private static function toValue(DOMElement $node): mixed
    {
        if ($node->getAttributeNS(self::NS_XSI, 'nil') === 'true') {
            return null;
        }

        $children = [];

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $children[] = $child;
            }
        }

        if ($children === []) {
            return $node->textContent;
        }

        $counts = array_count_values(array_map(static fn (DOMElement $c): string => (string) $c->localName, $children));
        $out = [];

        foreach ($children as $child) {
            $name = (string) $child->localName;

            if ($counts[$name] > 1) {
                $out[$name][] = self::toValue($child);
            } else {
                $out[$name] = self::toValue($child);
            }
        }

        return $out;
    }
}
