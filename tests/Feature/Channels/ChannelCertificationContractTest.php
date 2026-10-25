<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Certification\CertificationEvidence;
use App\Domain\Channels\Certification\CertificationStatus;
use App\Domain\Channels\Certification\ChannelAdapters;
use App\Domain\Channels\Certification\ChannelCapability;
use App\Domain\Channels\Certification\ChannelCertification;
use App\Domain\Channels\Certification\TestCoverageScanner;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\SiteController;
use App\Support\Site\Solutions;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\ChannelTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Certification\UnsupportedShipmentAdapter;
use Tests\TestCase;

/**
 * S15 · SERTİFİKASYON SÖZLEŞMESİ.
 *
 * - Yeni kanal klasörüyle eklenince matrise kendiliğinden girer (seeder ile
 *   adaptör klasörü birebir).
 * - MOCK_TESTED kanıtı güncel ve gerçek test yöntemlerine işaret eder.
 * - Kanıtlı kayıt yalnız var olan kanal ve UYGULANMIŞ yetenek için yazılabilir.
 * - Sitede "Çalışır" diyen her satır matriste çalışır; çalışmayan satır
 *   "Çalışır" diyemez (eskiden beş satır her kanal için sabit true'ydu).
 */
final class ChannelCertificationContractTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_seeded_channel_has_an_adapter_folder_and_vice_versa(): void
    {
        $this->seed(ChannelTypeSeeder::class);

        $seeded = TenantContext::runAsSystem(fn () => ChannelType::query()->whereNotNull('adapter_class')->pluck('adapter_class', 'code')->all());
        ksort($seeded);

        $this->assertSame($seeded, ChannelAdapters::all(), 'Seeder ile Adapters/ klasörü ayrıştı — yeni kanal sertifikasyona girmez ya da hayalet kanal var.');
    }

    #[Test]
    public function the_matrix_covers_every_adapter_and_every_capability(): void
    {
        $matrix = (new ChannelCertification)->matrix();

        $this->assertSame(array_keys(ChannelAdapters::all()), array_keys($matrix));
        foreach ($matrix as $channel => $cells) {
            $this->assertSame(array_map(fn (ChannelCapability $c) => $c->value, ChannelCapability::cases()), array_keys($cells), $channel);
            foreach ($cells as $cell) {
                $this->assertInstanceOf(CertificationStatus::class, $cell['status']);
            }
        }
    }

    #[Test]
    public function the_test_coverage_ledger_is_fresh(): void
    {
        $fresh = TestCoverageScanner::encode((new TestCoverageScanner)->scan());
        $current = (string) @file_get_contents(base_path(TestCoverageScanner::LEDGER));

        $this->assertSame($fresh, $current, 'resources/certification/test-coverage.json güncel değil → php artisan certification:scan-tests');
    }

    #[Test]
    public function mock_tested_evidence_points_to_real_test_methods(): void
    {
        foreach (TestCoverageScanner::load() as $channel => $capabilities) {
            $this->assertArrayHasKey($channel, ChannelAdapters::all());
            foreach ($capabilities as $capability => $tests) {
                $this->assertNotNull(ChannelCapability::tryFrom($capability), $capability);
                foreach ($tests as $ref) {
                    [$file, $method] = explode('::', $ref);
                    $this->assertFileExists(base_path($file));
                    $this->assertMatchesRegularExpression('/function\s+'.preg_quote($method, '/').'\s*\(/', (string) file_get_contents(base_path($file)), $ref);
                }
            }
        }
    }

    #[Test]
    public function verified_records_only_exist_for_real_channels_and_implemented_capabilities(): void
    {
        $adapters = ChannelAdapters::all();

        foreach (CertificationEvidence::records() as $record) {
            $this->assertArrayHasKey($record['channel'], $adapters);
            $this->assertContains($record['status'], [CertificationStatus::SANDBOX_VERIFIED, CertificationStatus::PRODUCTION_VERIFIED, CertificationStatus::FAILED], 'kanıtlı kayıt yalnız gerçek deneme durumu taşır');
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $record['date']);
            $this->assertNotSame('', trim($record['evidence']));
            foreach ($record['capabilities'] as $capability) {
                $this->assertTrue($capability->implementedBy($adapters[$record['channel']]), "{$record['channel']} {$capability->value}: kodda olmayan yetenek doğrulanmış yazılamaz");
            }
        }
    }

    #[Test]
    public function known_real_verifications_are_reflected(): void
    {
        $c = new ChannelCertification;

        $this->assertSame(CertificationStatus::PRODUCTION_VERIFIED, $c->cell('trendyol', ChannelCapability::INVENTORY)['status']);
        $this->assertSame(CertificationStatus::FAILED, $c->cell('etsy', ChannelCapability::AUTH)['status']);
        // Doğrulanmamış hücre VERIFIED görünmez.
        $this->assertNotSame(CertificationStatus::PRODUCTION_VERIFIED, $c->cell('shopify', ChannelCapability::INVENTORY)['status']);
        $this->assertSame(CertificationStatus::NOT_IMPLEMENTED, $c->cell('idefix', ChannelCapability::SHIPMENT)['status']);
        $this->assertNotNull($c->cell('idefix', ChannelCapability::SHIPMENT)['note']);
    }

    #[Test]
    public function status_follows_its_sources_for_every_cell(): void
    {
        $coverage = TestCoverageScanner::load();
        $adapters = ChannelAdapters::all();
        $untested = 0;

        foreach ((new ChannelCertification)->matrix() as $channel => $cells) {
            foreach ($cells as $capability => $cell) {
                $cap = ChannelCapability::from($capability);
                $hasRecord = ChannelCertification::latestOf(CertificationEvidence::records(), $channel, $cap) !== null;
                $hasTests = ($coverage[$channel][$capability] ?? []) !== [];

                if (! $cap->implementedBy($adapters[$channel])) {
                    $this->assertSame(CertificationStatus::NOT_IMPLEMENTED, $cell['status'], "{$channel} {$capability}");
                } elseif ($hasRecord) {
                    $this->assertNotContains($cell['status'], [CertificationStatus::IMPLEMENTED, CertificationStatus::MOCK_TESTED], "{$channel} {$capability}");
                } elseif ($hasTests) {
                    $this->assertSame(CertificationStatus::MOCK_TESTED, $cell['status'], "{$channel} {$capability}");
                } else {
                    $this->assertSame(CertificationStatus::IMPLEMENTED, $cell['status'], "{$channel} {$capability}: testi yokken testli görünmemeli");
                    $untested++;
                }
            }
        }

        $this->assertGreaterThan(0, $untested, 'testsiz uygulanmış hücre bulunamadı — tarama her şeyi testli sayıyor olabilir');
    }

    #[Test]
    public function the_newest_record_wins_a_cell(): void
    {
        $record = fn (string $date, CertificationStatus $status) => ['channel' => 'trendyol', 'capabilities' => [ChannelCapability::PRICE], 'status' => $status, 'date' => $date, 'by' => 'x', 'evidence' => 'y'];
        $records = [$record('2026-10-01', CertificationStatus::FAILED), $record('2026-10-20', CertificationStatus::PRODUCTION_VERIFIED), $record('2026-10-10', CertificationStatus::SANDBOX_VERIFIED)];

        $this->assertSame(CertificationStatus::PRODUCTION_VERIFIED, ChannelCertification::latestOf($records, 'trendyol', ChannelCapability::PRICE)['status']);
        $this->assertNull(ChannelCertification::latestOf($records, 'trendyol', ChannelCapability::INVENTORY));
    }

    #[Test]
    public function a_declared_unsupported_capability_is_not_supported_not_failed(): void
    {
        $c = new ChannelCertification(['sahte' => UnsupportedShipmentAdapter::class]);

        $cell = $c->cell('sahte', ChannelCapability::SHIPMENT);
        $this->assertSame(CertificationStatus::NOT_SUPPORTED, $cell['status']);
        $this->assertStringContainsString('takip numarası', (string) $cell['note']);
        $this->assertFalse($cell['status']->works());
        $this->assertSame(CertificationStatus::NOT_IMPLEMENTED, $c->cell('sahte', ChannelCapability::INVENTORY)['status']);
    }

    #[Test]
    public function the_site_channel_page_never_claims_what_the_matrix_does_not_have(): void
    {
        $this->seed(ChannelTypeSeeder::class);
        $c = new ChannelCertification;
        $rowTitles = ['Stok' => 'inventory', 'Siparişler' => 'order_import', 'Ürün yayınlama' => 'publishing', 'Fiyat' => 'price', 'Kargo takip numarası' => 'shipment'];
        $checked = 0;

        foreach (TenantContext::runAsSystem(fn () => ChannelType::query()->where('is_active', true)->get()) as $type) {
            if (! $type->isOffered()) {
                continue;
            }

            $html = $this->get(route('site.channel', $type->code))->assertOk()->getContent();
            preg_match_all('#<dt class="heading-3[^"]*">([^<]+)</dt>.*?<span class="badge badge--(active|soon)">#s', $html, $m, PREG_SET_ORDER);

            $this->assertCount(5, $m, "{$type->code}: yetenek satırları okunamadı");
            foreach ($m as [, $title, $badge]) {
                $capability = ChannelCapability::from($rowTitles[trim($title)]);
                $this->assertSame($c->works($type->code, $capability), $badge === 'active', "{$type->code} · {$title}: sitede ".($badge === 'active' ? '"Çalışır"' : '"Kanalda girilir"').' ama matris tersini söylüyor');
            }
            $checked++;
        }

        $this->assertGreaterThan(5, $checked);
    }

    #[Test]
    public function a_channel_without_publishing_does_not_say_it_publishes(): void
    {
        $this->seed(ChannelTypeSeeder::class);
        $this->assertFalse((new ChannelCertification)->works('idefix', ChannelCapability::PUBLISHING));

        $html = $this->get(route('site.channel', 'idefix'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#Ürün yayınlama</dt>.*?badge--soon#s', $html);
        $this->assertStringContainsString('mevcut ilanlarının stok ve fiyatını yönetir', $html);
    }

    #[Test]
    public function the_home_table_and_solution_pages_follow_the_matrix(): void
    {
        $this->seed(ChannelTypeSeeder::class);
        $c = new ChannelCertification;

        // Ana sayfa kanal tablosu: satırdaki her ✓/— matrisin aynısı.
        $rows = collect($this->get('/')->assertOk()->viewData('channels'))->where('available', true);
        $this->assertGreaterThan(5, $rows->count());
        foreach ($rows as $row) {
            foreach (SiteController::SITE_CAPABILITIES as $capability) {
                $this->assertSame($c->works($row['code'], $capability), $row['works'][$capability->value], "ana sayfa {$row['code']} · {$capability->value}");
            }
        }

        $interfaceToCapability = [
            'SupportsInventory' => ChannelCapability::INVENTORY,
            'SupportsOrders' => ChannelCapability::ORDER_IMPORT,
            'SupportsPricing' => ChannelCapability::PRICE,
            'SupportsFulfillment' => ChannelCapability::SHIPMENT,
        ];
        foreach (Solutions::all() as $solution) {
            $rule = $solution['channels'] ?? null;
            if (! is_string($rule) || ! isset($interfaceToCapability[$rule])) {
                continue;
            }
            foreach (Solutions::channels($solution) as $channel) {
                $this->assertTrue($c->works($channel['code'], $interfaceToCapability[$rule]), "çözüm sayfası {$channel['code']} için {$rule} diyor, matris çalışmıyor diyor");
            }
        }
    }

    #[Test]
    public function the_seller_channel_list_shows_only_what_works_in_plain_words(): void
    {
        $this->seed(ChannelTypeSeeder::class);
        $seller = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'Satıcı', owner: $seller);
        $this->asTenant($tenant, fn () => ChannelConnection::factory()->create(['channel_type_code' => 'idefix', 'status' => 'active']));

        $connection = $this->actingAs($seller)->get('/channels')->assertOk()->viewData('page')['props']['connections'][0];

        $this->assertContains('Siparişler diğer kanallarla aynı listeye düşer', $connection['works']);
        $this->assertNotContains('Ürünü panelden bu kanala gönderebilirsin', $connection['works'], 'İdefix yayınlamıyor');
        $this->assertNotContains('Kargo takip numarası panelden bu kanala iletilir', $connection['works']);
    }

    #[Test]
    public function only_super_admins_see_the_matrix_and_sellers_get_plain_sentences(): void
    {
        config(['entegrasyon.super_admin_emails' => 'admin@34devs.test']);
        $this->seed(ChannelTypeSeeder::class);

        $seller = User::factory()->create();
        (new CreateTenant)->run(name: 'Satıcı', owner: $seller);
        $this->actingAs($seller)->get('/admin/certification')->assertForbidden();

        $admin = User::factory()->create(['email' => 'admin@34devs.test']);
        $page = $this->actingAs($admin)->get('/admin/certification')->assertOk()->viewData('page');

        $this->assertSame('Admin/Certification', $page['component']);
        $this->assertCount(count(ChannelAdapters::all()), $page['props']['rows']);
        $trendyol = collect($page['props']['rows'])->firstWhere('code', 'trendyol');
        $this->assertSame('PRODUCTION_VERIFIED', $trendyol['cells']['inventory']['status']);
        $this->assertArrayHasKey('api_errors', $trendyol['errors']);

        // Satıcı cümlelerinde iç durum adı yok.
        foreach (ChannelCapability::cases() as $capability) {
            $text = (string) $capability->sellerText();
            foreach (CertificationStatus::cases() as $status) {
                $this->assertStringNotContainsString($status->value, $text);
            }
        }
    }
}
