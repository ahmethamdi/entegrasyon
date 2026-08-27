<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Inertia sayfa yolu — HARF DUYARLI doğrulama.
 *
 * ─────────────────────────────────────────────────────────────────────
 * BU TESTİN VARLIK SEBEBİ: "YALNIZCA CI'DA KIRILAN" HATA SINIFI
 * ─────────────────────────────────────────────────────────────────────
 * `config/inertia.php` paketin varsayılanıyla geldiğinde `js/pages`
 * (küçük `p`) diyordu; projenin gerçek dizini ise `js/Pages`. **macOS
 * dosya sistemi HARF DUYARSIZDIR** ve yolu yine bulduğu için hata
 * yerelde HİÇ görünmedi — tüm panel ekranı testleri yeşildi. CI'ın
 * Linux'u harf duyarlıdır ve `assertInertia(...)->component()`
 * çağıran her test orada "Inertia page component file does not exist"
 * ile DÜŞTÜ.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ `is_dir()` BU İŞİ GÖREMEZ — ÖLÇÜM HARF DUYARLI OLMALIDIR
 * ─────────────────────────────────────────────────────────────────────
 * `is_dir(resource_path('js/pages'))` macOS'ta `true` döner ve test
 * yanlış yapılandırmayla birlikte YEŞİL kalırdı — yani tam olarak
 * yakalamak istediği hatayı kaçırırdı. Bu yüzden karşılaştırma
 * `scandir()` çıktısındaki GERÇEK adla, `in_array` üzerinden yapılır:
 * dosya sistemi ne derse desin, dizinin yazılışı ölçülür.
 */
final class InertiaPagePathTest extends TestCase
{
    /**
     * ⚠️ YAPILANDIRILAN HER YOL DİSKTE **AYNI HARFLERLE** VAR OLMALIDIR.
     *
     * Ayrışırsa panel ekranı testleri yerelde yeşil, CI'da kırmızı olur
     * ve sebebi bir yapılandırma satırıdır — kodda değil.
     */
    #[Test]
    public function every_configured_page_path_exists_with_the_exact_same_case(): void
    {
        $paths = config('inertia.pages.paths');

        $this->assertNotEmpty($paths, 'Inertia sayfa yolu tanımsız.');

        foreach ($paths as $path) {
            $parent = dirname((string) $path);
            $name = basename((string) $path);

            $this->assertDirectoryExists($parent, "Üst dizin yok: {$parent}");

            $entries = scandir($parent);

            $this->assertIsArray($entries);
            $this->assertContains(
                $name,
                $entries,
                "Inertia sayfa dizini `{$name}` diskte BU YAZILIŞLA yok "
                .'(harf duyarlı karşılaştırma). macOS bunu tolere eder ama '
                .'CI\'ın Linux\'u ETMEZ: `assertInertia(...)->component()` '
                .'çağıran HER test orada düşer.',
            );
        }
    }

    /**
     * Testte sayfa varlığı ZORLANIYOR olmalıdır.
     *
     * `false` olsaydı yanlış yazılmış bir bileşen adı (`Chanels/Create`)
     * hiçbir testte görünmez ve satıcı boş bir ekranla karşılaşırdı.
     */
    #[Test]
    public function page_existence_is_enforced_in_tests(): void
    {
        $this->assertTrue(config('inertia.testing.ensure_pages_exist'));
    }
}
