<?php

declare(strict_types=1);

namespace App\Domain\Shipping\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Ayrıştırılmış SOAP cevabı — ad alanından bağımsız okuma.
 */
final class SoapReply
{
    private function __construct(private readonly DOMXPath $xpath) {}

    public static function parse(string $xml): ?self
    {
        if (trim($xml) === '' || ! str_contains($xml, 'Envelope')) {
            return null;
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            // Dış varlık yüklenmez (XXE): LIBXML_NONET, DTD yok sayılır.
            $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $ok ? new self(new DOMXPath($dom)) : null;
    }

    /** İlk `<ad>` öğesinin metni; yoksa null. */
    public function text(string $localName, ?DOMElement $within = null): ?string
    {
        $node = $this->first($localName, $within);

        return $node === null ? null : trim($node->textContent);
    }

    public function first(string $localName, ?DOMElement $within = null): ?DOMElement
    {
        $nodes = $this->all($localName, $within);

        return $nodes[0] ?? null;
    }

    /** @return list<DOMElement> */
    public function all(string $localName, ?DOMElement $within = null): array
    {
        $query = ($within === null ? '//' : './/')."*[local-name()='{$localName}']";
        $result = $within === null ? $this->xpath->query($query) : $this->xpath->query($query, $within);
        $out = [];

        foreach ($result === false ? [] : $result as $node) {
            if ($node instanceof DOMElement) {
                $out[] = $node;
            }
        }

        return $out;
    }
}
