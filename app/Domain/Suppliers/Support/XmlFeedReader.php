<?php

declare(strict_types=1);

namespace App\Domain\Suppliers\Support;

use App\Domain\Suppliers\Enums\SupplierReason;
use DOMElement;
use DOMNode;
use Generator;
use XMLReader;

/**
 * Tedarikçi XML'ini AKIŞLA okur — XML güvenilmeyen girdidir.
 *
 * GÜVENLİK (ADR-012 · §4):
 *   - DOCTYPE görülürse akış REDDEDİLİR. Varlık genişletme (billion
 *     laughs) ve dış varlık (XXE) DTD ister; toptancı ürün akışında DTD'nin
 *     meşru bir işi yoktur.
 *   - LIBXML_NONET: ağdan hiçbir şey yüklenmez. LIBXML_NOENT VERİLMEZ
 *     (varlıklar yerine konmaz), DTD yüklenmez/doğrulanmaz.
 *   - Belleğe yalnız TEK kayıt açılır (`expand()`), akışın tamamı asla.
 *   - Derinlik, kayıt başına alan sayısı ve değer uzunluğu sınırlı.
 *
 * YOL BİÇİMİ: kayıt yolu kökten tam yoldur ("Urunler/Urun"); alan yolları
 * kayda göredir ("Kod", "Fiyat", "Resimler/Resim", nitelik "@id",
 * "Kategori/@id"). Her yol BİR LİSTE değer taşır (tekrarlanan görsel vb.).
 *
 * İÇ İÇE VARYANT: `variantPath` verilirse ("Varyantlar/Varyant") her
 * varyant düğümü ayrı bir öğe olur: üst kaydın alanları + o varyantın
 * alanları (yolları "Varyantlar/Varyant/Stok" biçiminde). Varyant düğümü
 * olmayan kayıt tek öğedir.
 */
final class XmlFeedReader
{
    public const MAX_DEPTH = 64;

    public const MAX_FIELDS_PER_RECORD = 300;

    public const MAX_VALUE_LENGTH = 2000;

    /**
     * Kayıtları sırayla verir. Herhangi bir noktada biçim hatası →
     * SupplierFeedException(SUPPLIER_XML_INVALID); çağıran o ana kadar
     * okuduklarını UYGULAMAMALIDIR (doğrulama geçişi bunun içindir).
     *
     * @return Generator<int, array<string, list<string>>>
     */
    public function items(string $path, string $recordPath, ?string $variantPath = null): Generator
    {
        foreach ($this->records($path, $recordPath, tolerant: false) as $node) {
            yield from $this->explode($node, $variantPath);
        }
    }

    /**
     * Yapı keşfi için: element yollarının sayısı ve ilk kayıtlar. Kesik
     * (örnek) dosyada hata çıktığı yerde DURUR, okunanı döner.
     *
     * @return array{paths: array<string, array{count: int, has_children: bool}>, complete: bool, error: string|null}
     */
    public function survey(string $path, int $maxElements = 200_000): array
    {
        $reader = $this->open($path);
        $stack = [];
        $paths = [];
        $seen = 0;
        $error = null;

        try {
            while ($this->advance($reader)) {
                if ($reader->nodeType === XMLReader::ELEMENT) {
                    $stack[$reader->depth] = $reader->localName;
                    $stack = array_slice($stack, 0, $reader->depth + 1);
                    $full = implode('/', $stack);
                    $paths[$full] ??= ['count' => 0, 'has_children' => false];
                    $paths[$full]['count']++;

                    if ($reader->depth > 0) {
                        $parent = implode('/', array_slice($stack, 0, $reader->depth));
                        $paths[$parent]['has_children'] = true;
                    }

                    if (++$seen >= $maxElements) {
                        break;
                    }
                }
            }
        } catch (SupplierFeedException $e) {
            $error = $e->getMessage();
        } finally {
            $reader->close();
            libxml_clear_errors();
        }

        return ['paths' => $paths, 'complete' => $error === null, 'error' => $error];
    }

    /**
     * Örnek dosyadan ilk N öğe (kesikte hata çıkınca durur).
     *
     * @return list<array<string, list<string>>>
     */
    public function sampleItems(string $path, string $recordPath, ?string $variantPath, int $limit): array
    {
        $out = [];

        try {
            foreach ($this->records($path, $recordPath, tolerant: true) as $node) {
                foreach ($this->explode($node, $variantPath) as $item) {
                    $out[] = $item;

                    if (count($out) >= $limit) {
                        return $out;
                    }
                }
            }
        } catch (SupplierFeedException) {
            // Kesik örnek: okunanla yetin.
        }

        return $out;
    }

    /**
     * @return Generator<int, DOMElement>
     */
    private function records(string $path, string $recordPath, bool $tolerant): Generator
    {
        $target = trim($recordPath, '/');
        $reader = $this->open($path);
        $stack = [];

        try {
            $more = $this->advance($reader);

            while ($more) {
                if ($reader->nodeType === XMLReader::ELEMENT) {
                    $stack[$reader->depth] = $reader->localName;
                    $stack = array_slice($stack, 0, $reader->depth + 1);

                    if (implode('/', $stack) === $target) {
                        $node = @$reader->expand();

                        if (! $node instanceof DOMElement) {
                            throw new SupplierFeedException(SupplierReason::SUPPLIER_XML_INVALID, $this->lastError() ?? 'Kayıt açılamadı.');
                        }

                        yield $node;

                        // Alt ağacı atla: kayıt zaten açıldı.
                        $more = $this->skip($reader);

                        continue;
                    }
                }

                $more = $this->advance($reader);
            }
        } finally {
            $reader->close();

            if ($tolerant) {
                libxml_clear_errors();
            }
        }
    }

    private function open(string $path): XMLReader
    {
        libxml_use_internal_errors(true);
        libxml_clear_errors();

        // LIBXML_PARSEHUGE BİLİNÇLİ VERİLMEZ: libxml'in varsayılan
        // düğüm/metin boyutu sınırları bellek korumasının parçasıdır.
        $reader = XMLReader::open($path, null, LIBXML_NONET | LIBXML_COMPACT);

        if ($reader === false) {
            throw new SupplierFeedException(SupplierReason::SUPPLIER_XML_INVALID, 'Dosya açılamadı.');
        }

        $reader->setParserProperty(XMLReader::LOADDTD, false);
        $reader->setParserProperty(XMLReader::DEFAULTATTRS, false);
        $reader->setParserProperty(XMLReader::VALIDATE, false);
        $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);

        return $reader;
    }

    /** Bir sonraki düğüm; biçim hatası ve DOCTYPE'ta istisna. */
    private function advance(XMLReader $reader): bool
    {
        $ok = @$reader->read();

        return $this->checked($reader, $ok);
    }

    private function skip(XMLReader $reader): bool
    {
        $ok = @$reader->next();

        return $this->checked($reader, $ok);
    }

    private function checked(XMLReader $reader, bool $ok): bool
    {
        $error = $this->lastError();

        if ($error !== null) {
            throw new SupplierFeedException(SupplierReason::SUPPLIER_XML_INVALID, $error);
        }

        if (! $ok) {
            return false;
        }

        if ($reader->nodeType === XMLReader::DOC_TYPE) {
            throw new SupplierFeedException(SupplierReason::SUPPLIER_XML_INVALID, 'DOCTYPE içeren akış desteklenmez.');
        }

        if ($reader->nodeType === XMLReader::ENTITY_REF) {
            throw new SupplierFeedException(SupplierReason::SUPPLIER_XML_INVALID, 'Varlık başvurusu desteklenmez.');
        }

        if ($reader->depth > self::MAX_DEPTH) {
            throw new SupplierFeedException(SupplierReason::SUPPLIER_XML_INVALID, 'XML çok derin.');
        }

        return true;
    }

    private function lastError(): ?string
    {
        foreach (libxml_get_errors() as $error) {
            if ($error->level >= LIBXML_ERR_ERROR) {
                libxml_clear_errors();

                return sprintf('Satır %d: %s', $error->line, trim($error->message));
            }
        }

        return null;
    }

    /**
     * @return list<array<string, list<string>>>
     */
    private function explode(DOMElement $record, ?string $variantPath): array
    {
        $variantPath = $variantPath !== null ? trim($variantPath, '/') : null;

        if ($variantPath === null || $variantPath === '') {
            return [$this->flatten($record)];
        }

        $base = $this->flatten($record, '', $variantPath);
        $variants = $this->find($record, explode('/', $variantPath));

        if ($variants === []) {
            return [$base];
        }

        $items = [];

        foreach ($variants as $variant) {
            $items[] = $base + $this->flatten($variant, $variantPath.'/');
        }

        return $items;
    }

    /**
     * @param  list<string>  $segments
     * @return list<DOMElement>
     */
    private function find(DOMElement $from, array $segments): array
    {
        $current = [$from];

        foreach ($segments as $segment) {
            $next = [];

            foreach ($current as $element) {
                foreach ($element->childNodes as $child) {
                    if ($child instanceof DOMElement && $child->localName === $segment) {
                        $next[] = $child;
                    }
                }
            }

            $current = $next;
        }

        return $current;
    }

    /**
     * @return array<string, list<string>>
     */
    private function flatten(DOMElement $element, string $prefix = '', ?string $exclude = null): array
    {
        $out = [];
        $this->walk($element, rtrim($prefix, '/'), $exclude, $out);

        return $out;
    }

    /**
     * @param  array<string, list<string>>  $out
     */
    private function walk(DOMNode $element, string $path, ?string $exclude, array &$out): void
    {
        if ($element instanceof DOMElement) {
            foreach ($element->attributes ?? [] as $attribute) {
                $this->put($out, ($path === '' ? '' : $path.'/').'@'.$attribute->localName, $attribute->value);
            }
        }

        $hasElementChild = false;

        foreach ($element->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $hasElementChild = true;
            $childPath = ($path === '' ? '' : $path.'/').$child->localName;

            if ($exclude !== null && ($childPath === $exclude || str_starts_with($childPath, $exclude.'/'))) {
                continue;
            }

            $this->walk($child, $childPath, $exclude, $out);
        }

        if (! $hasElementChild && $path !== '') {
            $this->put($out, $path, $element->textContent);
        }
    }

    /**
     * @param  array<string, list<string>>  $out
     */
    private function put(array &$out, string $path, string $value): void
    {
        if (! isset($out[$path]) && count($out) >= self::MAX_FIELDS_PER_RECORD) {
            return;
        }

        $out[$path][] = mb_substr(trim($value), 0, self::MAX_VALUE_LENGTH);
    }
}
