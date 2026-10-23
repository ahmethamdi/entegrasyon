<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domain\Identity\Enums\Permission;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * S7 · panelin düğme gizlemesi (`can()`, `<WriteGuard>`, menü `perm:`)
 * `Permission` enum'unun metinlerini okur.
 *
 * İKİ SESSİZ HATA burada adıyla düşer:
 *  1. Yanlış yazılmış yetki (`'order.work'`) hiçbir rolde yoktur — düğme
 *     SAHİBE BİLE gizlenir, hata vermez.
 *  2. Yazma isteği atan bir ekran hiç `can()`/`WriteGuard` kullanmıyorsa
 *     depo/salt okunur üye düğmeyi görür ve tıklayınca 403 ekranı alır
 *     (S7'de kapatılan şikâyet). Yeni ekran bu listeye düşer; ya kapı
 *     eklenir ya da ekranın yalnız yetkili role açıldığı aşağıda yazılır.
 */
final class PanelPermissionStringsContractTest extends TestCase
{
    /**
     * Yazma isteği atan ama düğme kapısı GEREKMEYEN ekranlar: rotası
     * zaten yalnız yazma yetkisi olana açık (oluşturma ekranları,
     * abonelik, ekip) ya da kiracı paneli değil (giriş, süper yönetici).
     */
    private const EXEMPT = [
        'Admin/',
        'Auth/',
        'Billing/Index.vue',
        'Settings/Team.vue',
        'Campaigns/Create.vue',
        'Channels/Create.vue',
        'Products/Create.vue',
        'Suppliers/Create.vue',
    ];

    #[Test]
    public function every_permission_string_in_the_panel_is_a_real_permission(): void
    {
        $known = array_map(static fn (Permission $p): string => $p->value, Permission::cases());
        $unknown = [];
        $seen = 0;

        foreach ($this->vueFiles(resource_path('js')) as $path => $source) {
            preg_match_all("/(?:can\\(\\s*'|permission=\"|perm:\\s*')([a-z.]+)/", $source, $matches);

            foreach ($matches[1] as $value) {
                $seen++;

                if (! in_array($value, $known, true)) {
                    $unknown[] = basename($path).": {$value}";
                }
            }
        }

        $this->assertGreaterThan(20, $seen, 'Yetki metni bulunamadı — desen yanlış şeyi tarıyor.');
        $this->assertSame([], $unknown, 'Panelde Permission enum\'unda olmayan yetki metni.');
    }

    #[Test]
    public function every_screen_that_writes_hides_its_controls_by_role(): void
    {
        $pages = resource_path('js/Pages').'/';
        $unguarded = [];

        foreach ($this->vueFiles($pages) as $path => $source) {
            $relative = substr($path, strlen($pages));

            if ($this->exempt($relative) || preg_match('/\.(post|put|patch|delete)\(/', $source) !== 1) {
                continue;
            }

            if (! str_contains($source, 'can(') && ! str_contains($source, '<WriteGuard')) {
                $unguarded[] = $relative;
            }
        }

        $this->assertSame([], $unguarded, 'Yazma isteği atan ama düğmeleri role göre gizlemeyen ekran.');
    }

    private function exempt(string $relative): bool
    {
        foreach (self::EXEMPT as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @return iterable<string, string> */
    private function vueFiles(string $root): iterable
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (in_array($file->getExtension(), ['vue', 'js'], true)) {
                yield $file->getPathname() => (string) file_get_contents($file->getPathname());
            }
        }
    }
}
