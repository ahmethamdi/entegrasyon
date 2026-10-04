<?php

declare(strict_types=1);

namespace App\Support\Site;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\StringContainerHelper;
use Throwable;

/**
 * Blog yazıları — `resources/content/blog/{slug}.md` dosyalarından okunur.
 *
 * NEDEN VERİTABANI DEĞİL: yazılar az ve seyrek değişir; dosyada durunca
 * git geçmişiyle birlikte gözden geçirilir ve yayına deploy ile çıkar.
 * Panelde içerik yönetimi kurmak bu ölçekte gereksiz yük olurdu.
 *
 * Dosya biçimi: `---` arasında basit ön bilgi (title, description,
 * published_at, updated_at, tags, image), ardından Markdown gövde.
 *
 * ÖN BİLGİ İÇİN symfony/yaml KULLANILMAZ: paket yalnız `require-dev`'de;
 * üretimde `composer install --no-dev` sonrası blog sessizce boş kalırdı.
 * Biz yalnız `anahtar: değer` ve `[a, b]` listesi kullanıyoruz — küçük
 * ayrıştırıcı yeterli.
 */
final class Blog
{
    /**
     * Ayrıştırıcı sürümü — önbellek anahtarına girer. Markdown ayarı veya
     * DTO alanı değişince artırılır; yoksa dosya değişmediği için eski
     * HTML önbellekten gelmeye devam ederdi.
     */
    private const PARSER_VERSION = 1;

    /** Okuma süresi hesabı — Türkçe düzyazı için yaygın kabul edilen hız. */
    private const WORDS_PER_MINUTE = 200;

    private readonly string $path;

    private ?MarkdownConverter $converter = null;

    /** @var list<BlogPost>|null istek içi ara bellek */
    private ?array $loaded = null;

    public function __construct(?string $path = null)
    {
        // Testler `site.blog_path`'i fikstür dizinine çevirir; gerçek
        // içerikle oynamadan "gelecek tarihli yazı gizli mi" denenebilsin.
        $this->path = rtrim($path ?? (string) config('site.blog_path', resource_path('content/blog')), '/');
    }

    /**
     * Yayımlanmış yazılar, en yeni önce.
     *
     * Yayın tarihi gelecekte olan yazı GİZLİDİR: yazıyı önceden hazırlayıp
     * deploy etmek mümkün olsun, tarih gelince kendiliğinden görünsün.
     *
     * @return list<BlogPost>
     */
    public function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        $now = CarbonImmutable::now();
        $posts = [];

        foreach (glob($this->path.'/*.md') ?: [] as $file) {
            $post = $this->load($file);

            if ($post !== null && $post->publishedAt->lessThanOrEqualTo($now)) {
                $posts[] = $post;
            }
        }

        // Aynı gün iki yazı varsa sıra her istekte aynı kalsın diye slug ikinci anahtar.
        usort($posts, fn (BlogPost $a, BlogPost $b): int => [$b->publishedAt->getTimestamp(), $a->slug] <=> [$a->publishedAt->getTimestamp(), $b->slug]);

        return $this->loaded = $posts;
    }

    public function find(string $slug): ?BlogPost
    {
        foreach ($this->all() as $post) {
            if ($post->slug === $slug) {
                return $post;
            }
        }

        return null;
    }

    /**
     * İlgili yazılar — önce ortak etiket sayısı, eşitse yenilik.
     *
     * Ortak etiketi olmayan yazı da listeye girer (sona): yazı sayısı azken
     * "ilgili yazılar" bölümünün boş kalması okuru çıkmaz sokakta bırakırdı.
     *
     * @return list<BlogPost>
     */
    public function related(BlogPost $post, int $limit): array
    {
        $candidates = array_values(array_filter($this->all(), fn (BlogPost $p): bool => $p->slug !== $post->slug));

        $scored = array_map(fn (BlogPost $p): array => [
            'post' => $p,
            'shared' => count(array_intersect($p->tags, $post->tags)),
        ], $candidates);

        // all() zaten yeniden eskiye sıralı; usort kararlı (PHP 8+) olduğu
        // için eşit puanlılarda yenilik sırası korunur.
        usort($scored, fn (array $a, array $b): int => $b['shared'] <=> $a['shared']);

        return array_slice(array_map(fn (array $s): BlogPost => $s['post'], $scored), 0, max(0, $limit));
    }

    // ─────────────────────────────────────────────────── dosya okuma

    /**
     * Tek dosyayı ayrıştırır; sonuç dosyanın değişme zamanına bağlı
     * önbelleğe yazılır. Dosya değişince anahtar değişir, eski kayıt
     * kendiliğinden kullanılmaz olur — elle önbellek temizlemek gerekmez.
     */
    private function load(string $file): ?BlogPost
    {
        $mtime = (int) @filemtime($file);
        $key = sprintf('site.blog.v%d.%s.%d', self::PARSER_VERSION, md5($file), $mtime);

        return cache()->rememberForever($key, fn (): ?BlogPost => $this->parse($file));
    }

    private function parse(string $file): ?BlogPost
    {
        $raw = (string) file_get_contents($file);
        $raw = str_replace("\r\n", "\n", $raw);

        if (! preg_match('/\A---\n(.*?)\n---\n(.*)\z/s', $raw, $m)) {
            // Ön bilgisi olmayan dosya yayımlanmaz: başlıksız/tarihsiz bir
            // sayfa arama sonucunda bozuk görünürdü. Sessiz geçmek yerine
            // günlüğe yazılır ki yazar fark etsin.
            logger()->warning('Blog dosyasında ön bilgi yok, atlandı', ['file' => $file]);

            return null;
        }

        $meta = $this->parseFrontMatter($m[1]);
        $body = $m[2];

        $slug = basename($file, '.md');

        if (! isset($meta['title'], $meta['published_at']) || ! preg_match('/\A[a-z0-9-]+\z/', $slug)) {
            logger()->warning('Blog dosyası eksik ya da geçersiz, atlandı', ['file' => $file]);

            return null;
        }

        try {
            $publishedAt = CarbonImmutable::parse((string) $meta['published_at'], config('app.timezone'));
            $updatedAt = isset($meta['updated_at']) ? CarbonImmutable::parse((string) $meta['updated_at'], config('app.timezone')) : null;
        } catch (Throwable) {
            logger()->warning('Blog dosyasında tarih okunamadı, atlandı', ['file' => $file]);

            return null;
        }

        $words = str_word_count(strip_tags(Str::ascii($body)));
        $tags = is_array($meta['tags'] ?? null) ? $meta['tags'] : [];

        return new BlogPost(
            slug: $slug,
            title: (string) $meta['title'],
            description: (string) ($meta['description'] ?? ''),
            html: $this->converter()->convert($body)->getContent(),
            publishedAt: $publishedAt,
            updatedAt: $updatedAt,
            tags: array_values(array_map('strval', $tags)),
            readingMinutes: max(1, (int) ceil($words / self::WORDS_PER_MINUTE)),
            image: isset($meta['image']) ? (string) $meta['image'] : null,
            wordCount: $words,
        );
    }

    /**
     * `anahtar: değer` satırları; değer tırnaklıysa tırnak atılır,
     * `[a, b]` liste olur. Bilinmeyen biçim dize olarak kalır.
     *
     * @return array<string, string|list<string>>
     */
    private function parseFrontMatter(string $block): array
    {
        $meta = [];

        foreach (explode("\n", $block) as $line) {
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }

            if (! preg_match('/\A([a-z_]+)\s*:\s*(.*)\z/', trim($line), $m)) {
                continue;
            }

            $value = trim($m[2]);

            if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
                $items = array_map(fn (string $v): string => $this->unquote(trim($v)), explode(',', substr($value, 1, -1)));
                $meta[$m[1]] = array_values(array_filter($items, fn (string $v): bool => $v !== ''));

                continue;
            }

            $meta[$m[1]] = $this->unquote($value);
        }

        return $meta;
    }

    private function unquote(string $value): string
    {
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            return substr($value, 1, -1);
        }

        return $value;
    }

    /**
     * Markdown → HTML.
     *
     * - `html_input: strip`: içerik dosyası bile olsa ham HTML geçmez;
     *   bir kopyala-yapıştır hatası sayfaya betik sokamasın.
     * - GFM: tablolar ve otomatik bağlantılar yazılarda kullanılıyor.
     * - Başlık kimlikleri: "#stok-kodu" gibi bağlantı verilebilsin. Kimlik
     *   ASCII'ye çevrilir (Str::slug) — "ş", "ı" içeren kimlik bazı
     *   paylaşım araçlarında kodlanıp okunmaz hale geliyor.
     */
    private function converter(): MarkdownConverter
    {
        if ($this->converter !== null) {
            return $this->converter;
        }

        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 50,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);

        $environment->addEventListener(DocumentParsedEvent::class, function (DocumentParsedEvent $event): void {
            $used = [];

            foreach ($event->getDocument()->iterator() as $node) {
                if (! $node instanceof Heading || $node->getLevel() < 2) {
                    continue;
                }

                $base = Str::slug(StringContainerHelper::getChildText($node)) ?: 'bolum';
                $id = $base;

                // Aynı başlık iki kez geçerse kimlik çakışmasın.
                for ($i = 2; isset($used[$id]); $i++) {
                    $id = $base.'-'.$i;
                }

                $used[$id] = true;
                $node->data->set('attributes/id', $id);
            }
        });

        return $this->converter = new MarkdownConverter($environment);
    }
}
