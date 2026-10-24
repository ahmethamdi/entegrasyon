<?php

declare(strict_types=1);

namespace Tests\Feature\Site;

use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\User;
use Database\Seeders\ChannelTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SÖZLEŞME — "Beta" hiçbir yerde yazmaz (kullanıcı kararı 24 Eki 2026:
 * "her şeyi beta değil bağlı olarak yapalım, o badge'ları kaldıralım").
 *
 * Grep değil, RENDER ÇIKTISI ölçülür: sitenin her kanal/çözüm/rehber
 * sayfası ve panelin kanal + kargo ekranları. Veritabanında eski bir
 * `is_beta = true` satırı BİLEREK bırakılır — rozet bayraktan geri
 * doğarsa (biri şablona yeniden `@if ($ch['beta'])` eklerse) test yakalar.
 */
final class NoBetaBadgeContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChannelTypeSeeder::class);
        DB::table('channel_types')->update(['is_active' => true]);
        // Canlıda kalmış eski değeri taklit eder: görünüm bayrağa bakmamalı.
        DB::table('channel_types')->whereIn('code', ChannelType::UNVERIFIED_CODES)->update(['is_beta' => true]);
    }

    #[Test]
    public function no_public_page_says_beta(): void
    {
        $paths = ['/', '/en', '/entegrasyonlar', '/fiyatlar', '/yardim', '/en/pricing'];

        foreach (DB::table('channel_types')->pluck('code') as $code) {
            $paths[] = "/entegrasyonlar/{$code}";
            $paths[] = "/yardim/{$code}";
        }

        foreach (array_keys(require resource_path('content/solutions.php')) as $slug) {
            $paths[] = "/cozumler/{$slug}";
            $paths[] = "/en/solutions/{$slug}";
        }

        $checked = 0;

        foreach ($paths as $path) {
            $response = $this->get($path);

            // Rehberi olmayan kanal / EN karşılığı olmayan sayfa 404 verebilir;
            // ölçülen yalnız gerçekten yayınlanan sayfalardır.
            if ($response->getStatusCode() !== 200) {
                continue;
            }

            $checked++;
            $this->assertNoBeta((string) $response->getContent(), $path);
        }

        $this->assertGreaterThan(30, $checked, 'Sözleşme yeterince sayfa ölçmedi — rota adları değişmiş olabilir.');
    }

    #[Test]
    public function no_panel_channel_or_shipping_screen_says_beta(): void
    {
        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'Rozet '.uniqid(), owner: $user);

        $this->asTenant($tenant, fn (): ChannelConnection => ChannelConnection::factory()->create([
            'channel_type_code' => 'ebay',
            'external_account_id' => 'ebay-'.uniqid(),
        ]));

        foreach (['/channels', '/channels/create', '/settings/shipping'] as $path) {
            $response = $this->actingAs($user)->get($path)->assertOk();

            // Inertia sayfa verisi (props + paylaşılan çeviriler) — HTML'in
            // içine ne biçimde gömüldüğünden bağımsız, sunucunun gönderdiği.
            $data = $response->viewData('page');
            $this->assertIsArray($data, "{$path} Inertia sayfası değil.");
            $this->assertNotEmpty($data['props'] ?? [], "{$path} props boş — ölçüm boşa gider.");
            $page = (string) json_encode($data, JSON_UNESCAPED_UNICODE);

            $this->assertStringNotContainsString('"beta"', $page, "{$path} hâlâ `beta` alanı gönderiyor.");
            $this->assertDoesNotMatchRegularExpression('/\bbeta\b/iu', $page, "{$path} sayfa verisinde hâlâ \"Beta\" var.");
        }
    }

    private function assertNoBeta(string $html, string $path): void
    {
        // Görünen metin + öznitelik değerleri (title="…") — etiket adları hariç.
        // Etiket sınırı boşluk olur: "Aktif</span><span>Beta" bitişik okunmasın.
        $text = html_entity_decode(strip_tags(str_replace('<', ' <', preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? '')));
        $attributes = implode(' ', preg_match_all('/\s(?:title|aria-label|alt)="([^"]*)"/i', $html, $m) ? $m[1] : []);

        $this->assertDoesNotMatchRegularExpression('/\bbeta\b/iu', $text.' '.$attributes, "{$path} sayfasında hâlâ \"Beta\" yazıyor.");
    }
}
