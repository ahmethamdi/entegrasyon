<?php

declare(strict_types=1);

namespace Tests\Unit\I18n;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Panelde çevrilmek üzere işaretlenen her metnin İngilizcesi olmalı.
 *
 * Anahtar Türkçe metnin kendisidir; karşılığı yoksa İngilizce panelde
 * Türkçe basılır — hata vermez, yalnız inceleme ekibi (Shopify, İngilizce)
 * anlamadığı bir düğme görür. Bu test o sessiz boşluğu yayından önce yakalar.
 *
 * Yalnız SABİT anahtarlar taranır (`t('…')`, `$t('…')`, `k('…')`); değişkenle
 * çağrılan `t(item.label)` anahtarını tanımlandığı yerdeki `k('…')` verir.
 */
final class EnglishDictionaryCoverageTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    #[Test]
    public function every_marked_panel_string_has_an_english_translation(): void
    {
        $dictionary = $this->dictionary();
        $missing = [];

        foreach ($this->sourceFiles() as $file) {
            foreach ($this->keysIn((string) file_get_contents($file)) as $key) {
                if (! array_key_exists($key, $dictionary)) {
                    $missing[] = sprintf('%s: %s', str_replace(realpath(self::ROOT).'/', '', $file), $key);
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), "lang/en.json'da karşılığı olmayan metinler.");
    }

    #[Test]
    public function the_dictionary_has_no_empty_translations(): void
    {
        foreach ($this->dictionary() as $key => $value) {
            $this->assertNotSame('', trim((string) $value), "Boş çeviri: {$key}");
        }
    }

    #[Test]
    public function placeholders_survive_translation(): void
    {
        foreach ($this->dictionary() as $key => $value) {
            preg_match_all('/:([a-zA-Z_]+)/', (string) $key, $source);
            preg_match_all('/:([a-zA-Z_]+)/', (string) $value, $target);

            sort($source[1]);
            sort($target[1]);

            $this->assertSame($source[1], $target[1], "Yer tutucular çeviride kayboldu: {$key}");
        }
    }

    /** @return list<string> */
    private function keysIn(string $source): array
    {
        preg_match_all("/(?:\\\$t|\\bt|\\bk)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/u", $source, $matches);

        return array_map(static fn (string $key): string => stripslashes($key), $matches[1]);
    }

    /** @return array<string, string> */
    private function dictionary(): array
    {
        $decoded = json_decode((string) file_get_contents(self::ROOT.'/lang/en.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    /** @return list<string> */
    private function sourceFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::ROOT.'/resources/js'));

        foreach ($iterator as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['vue', 'js'], true)) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
