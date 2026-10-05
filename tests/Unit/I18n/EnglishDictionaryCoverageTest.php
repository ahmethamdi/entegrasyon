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
 * Sunucu tarafında `app/` altındaki `__('…')` çağrıları da aynı sözlüğe
 * bakar (flash mesajları, form etiketleri, yardım metni, postalar).
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
            $source = (string) file_get_contents($file);
            $keys = str_ends_with($file, '.php') ? $this->serverKeysIn($source) : $this->keysIn($source);

            foreach ($keys as $key) {
                if (! array_key_exists($key, $dictionary)) {
                    $missing[] = sprintf('%s: %s', str_replace(realpath(self::ROOT).'/', '', (string) realpath($file)), $key);
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
            $expected = array_values(array_unique($source[1]));
            sort($expected);

            // `tekil|çoğul` biçiminde HER biçim ayrı denetlenir; yalnız
            // tekil biçim `:count`'u sayı yerine sözcükle söyleyebilir
            // ("last measurement").
            $forms = explode('|', (string) $value);

            foreach ($forms as $index => $form) {
                preg_match_all('/:([a-zA-Z_]+)/', $form, $target);
                $found = array_values(array_unique($target[1]));
                $wanted = $index === 0 && count($forms) > 1 ? array_values(array_diff($expected, ['count'])) : $expected;

                if ($index === 0 && count($forms) > 1) {
                    $found = array_values(array_diff($found, ['count']));
                }

                sort($found);

                $this->assertSame($wanted, $found, "Yer tutucular çeviride kayboldu: {$key}");
            }
        }
    }

    #[Test]
    public function plural_forms_are_only_used_with_a_count(): void
    {
        foreach ($this->dictionary() as $key => $value) {
            if (str_contains((string) $value, '|')) {
                $this->assertStringContainsString(':count', (string) $key, "Tekil|çoğul seçimi :count ister: {$key}");
                $this->assertCount(2, explode('|', (string) $value), "Tam iki biçim olmalı: {$key}");
            }
        }
    }

    /** @return list<string> */
    private function keysIn(string $source): array
    {
        preg_match_all("/(?:\\\$t|\\bt|\\bk)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/u", $source, $matches);

        return array_map(static fn (string $key): string => stripslashes($key), $matches[1]);
    }

    /** @return list<string> */
    private function serverKeysIn(string $source): array
    {
        preg_match_all("/\\b(?:__|trans_choice)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/u", $source, $matches);

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
        $roots = [
            self::ROOT.'/resources/js' => ['vue', 'js'],
            self::ROOT.'/app' => ['php'],
        ];

        foreach ($roots as $root => $extensions) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

            foreach ($iterator as $file) {
                if ($file->isFile() && in_array($file->getExtension(), $extensions, true)) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }
}
