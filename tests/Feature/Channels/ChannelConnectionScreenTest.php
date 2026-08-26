<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Adapters\WooCommerce\WooCommerceAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelCredential;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kanal bağlama ekranı — panel tarafı.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · faz 1.4 · "WooCommerce bağlantı ekranı
 * ve sağlık kontrolü".
 *
 * DEĞİŞMEZ KURAL — INERTIA'YA SIR GÖNDERİLMEZ:
 *   Panel bağlantıyı gösterir ama kimlik bilgisini ASLA geri vermez. Modeli
 *   olduğu gibi paylaşmak şifreli yükü ve kasadaki her şeyi HTTP yanıtına
 *   koyardı; yalnızca görünen alanlar gönderilir.
 *
 * DEĞİŞMEZ KURAL — YETENEKLER TİP SİSTEMİNDEN OKUNUR:
 *   Panel `capabilities.taxonomy` yazar, `if type === 'woocommerce'` YAZMAZ.
 *   Yeni kanal eklendiğinde panel kodu değişmez.
 *
 * Rotalar `auth` + `tenant` ara katmanlarının arkasındadır: kiracı bağlamı
 * olmadan bağlantı listelenemez.
 */
final class ChannelConnectionScreenTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────────────────── erişim

    /** Misafir bağlantı ekranını göremez. */
    #[Test]
    public function guest_cannot_reach_the_channel_screens(): void
    {
        $this->get('/channels')->assertRedirect('/login');
        $this->get('/channels/create')->assertRedirect('/login');
        $this->post('/channels')->assertRedirect('/login');
    }

    // ─────────────────────────────────────────────────── liste

    /** Bağlantı listesi kanal sağlığını ve yetenekleri gösterir. */
    #[Test]
    public function connection_list_shows_health_and_capabilities(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $this->asTenant($tenant, fn () => ChannelConnection::factory()->create([
            'label' => 'Ana Mağaza',
            'health_status' => 'healthy',
        ]));

        $response = $this->actingAs($user)->get('/channels');

        $response->assertOk();

        $connections = $response->viewData('page')['props']['connections'];

        $this->assertCount(1, $connections);
        $this->assertSame('Ana Mağaza', $connections[0]['label']);
        $this->assertSame('healthy', $connections[0]['health']);

        // Yetenekler tip sisteminden gelir; Woo taksonomi desteklemez.
        $this->assertTrue($connections[0]['capabilities']['inventory']);
        $this->assertTrue($connections[0]['capabilities']['orders']);
        $this->assertFalse($connections[0]['capabilities']['taxonomy']);
        $this->assertFalse($connections[0]['capabilities']['approval']);
    }

    /**
     * KİMLİK BİLGİSİ PANELE ASLA GÖNDERİLMEZ.
     *
     * Şifreli yük bile gönderilmez: APP_KEY sızarsa yanıt gövdesi hazır
     * hedeftir ve panel onu hiçbir şey için kullanmaz.
     */
    #[Test]
    public function credentials_are_never_sent_to_the_panel(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $connection = $this->asTenant($tenant, fn () => ChannelConnection::factory()->create());

        $this->asTenant($tenant, fn () => app(CredentialVault::class)->store($connection, [
            'consumer_key' => 'ck_gizli_anahtar',
            'consumer_secret' => 'cs_cok_gizli_sir',
        ]));

        $response = $this->actingAs($user)->get('/channels');

        $response->assertOk();

        $body = $response->getContent();

        $this->assertStringNotContainsString('cs_cok_gizli_sir', $body);
        $this->assertStringNotContainsString('ck_gizli_anahtar', $body);
        $this->assertStringNotContainsString('encrypted_payload', $body);

        $connections = $response->viewData('page')['props']['connections'];

        $this->assertArrayNotHasKey('credentials', $connections[0]);
        $this->assertArrayNotHasKey('settings', $connections[0]);
    }

    /** Başka kiracının bağlantısı listede GÖRÜNMEZ. */
    #[Test]
    public function connections_of_other_tenants_are_not_visible(): void
    {
        [$tenantA, $userA] = $this->makeTenant('A');
        [$tenantB] = $this->makeTenant('B');

        $this->asTenant($tenantA, fn () => ChannelConnection::factory()->create(['label' => 'Benim']));
        $this->asTenant($tenantB, fn () => ChannelConnection::factory()->create(['label' => 'Başkasının']));

        $response = $this->actingAs($userA)->get('/channels');

        $connections = $response->viewData('page')['props']['connections'];

        $this->assertCount(1, $connections);
        $this->assertSame('Benim', $connections[0]['label']);
    }

    // ─────────────────────────────────────────────────── bağlama formu

    /** Bağlama formu yalnızca aktif kanal tiplerini sunar. */
    #[Test]
    public function create_form_offers_only_active_channel_types(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => 'trendyol'],
            [
                'name' => 'Trendyol',
                'kind' => 'marketplace',
                'adapter_class' => 'App\\Domain\\Channels\\Adapters\\TrendyolAdapter',
                'is_active' => false,
            ],
        ));

        $response = $this->actingAs($user)->get('/channels/create');

        $response->assertOk();

        $codes = array_column($response->viewData('page')['props']['channelTypes'], 'code');

        $this->assertContains('woocommerce', $codes);
        $this->assertNotContains('trendyol', $codes, 'Pasif kanal tipi sunulmamalı.');
    }

    // ──────────────────────────────── panelden bağlanabilirlik kapısı

    /**
     * ⚠️ AÇIK OLAN HER KANAL BAĞLANABİLİR OLMALIDIR.
     *
     * Bu test 25 Ağustos'ta TERSİNİ iddia ediyordu: form TEK bir kimlik
     * biçimi (Woo/Trendyol'un `consumer_key`/`consumer_secret` çifti)
     * bildiği için Etsy ve Shopify `connectable = false` işaretleniyor
     * ve satıcıya "bağlanamıyor" deniyordu. O bir GEÇİCİ dürüstlük
     * katmanıydı (`PanelConnectSupport`); form kanal başına
     * dallandırıldığında (A1 + A2) kaldırıldı.
     *
     * Kapı KALDIRILMADI, yalnızca CEVABI değişti: kanal `is_active =
     * true` yapılıp `ChannelConnectForm` tanımı unutulursa satıcı yine
     * sebebi görür. Kapının hiç olmaması, satıcının Etsy panelinde HİÇ
     * VAR OLMAYAN bir `ck_` anahtarını aramasına ve bulamayınca rastgele
     * bir değer girmesine yol açardı — sağlık kontrolü 401 alır ve sebep
     * "anahtarın yanlış" gibi görünürdü.
     */
    #[Test]
    public function every_active_channel_can_be_connected_from_the_panel(): void
    {
        [, $user] = $this->makeTenant();

        $this->activate('etsy', 'Etsy');
        $this->activate('shopify', 'Shopify');

        $response = $this->actingAs($user)->get('/channels/create');

        $types = collect($response->viewData('page')['props']['channelTypes'])
            ->keyBy('code');

        foreach (['woocommerce', 'etsy', 'shopify'] as $code) {
            $this->assertTrue(
                $types[$code]['connectable'],
                "`{$code}` panelde AÇIK ama form onun kimlik biçimini bilmiyor.",
            );
        }

        // ⚠️ ETSY SIR SORMAZ, YÖNLENDİRİR — Shopify'ın aksine.
        $this->assertTrue($types['etsy']['oauth']);
        $this->assertSame([], $types['etsy']['secretFields']);

        $this->assertFalse($types['shopify']['oauth']);
        $this->assertNotSame([], $types['shopify']['secretFields']);
    }

    /**
     * ⚠️ TANIMSIZ KANAL SUNUCUDA REDDEDİLİR — PANEL TEK SAVUNMA DEĞİLDİR.
     *
     * Kanal `is_active = true` yapılıp `ChannelConnectForm` tanımı
     * unutulursa doğrudan POST atan bir istek onu BOŞ kimlikle kasaya
     * yazdırırdı: satır `pending` kalır, satıcı bağlantıyı "kurulmuş"
     * sanar ve her çağrı 401 alır — anahtar yanlış değil, HİÇ
     * SORULMAMIŞTIR.
     */
    #[Test]
    public function a_channel_without_a_form_definition_is_rejected_server_side(): void
    {
        [$tenant, $user] = $this->makeTenant();

        // Tanımı OLMAYAN bir kanal açılmış olsun.
        $this->activate('tanimsiz-kanal', 'Tanımsız Kanal');

        $response = $this->actingAs($user)->post('/channels', [
            'channel_type_code' => 'tanimsiz-kanal',
            'label' => 'Mağazam',
            'store_url' => 'magaza.example.com',
            'consumer_key' => 'ck_uydurma',
            'consumer_secret' => 'cs_uydurma',
        ]);

        $response->assertSessionHasErrors('channel_type_code');

        $this->assertSame(
            0,
            $this->asTenant($tenant, fn (): int => ChannelConnection::query()
                ->where('channel_type_code', 'tanimsiz-kanal')
                ->count()),
            'Tanımsız kanal için bağlantı satırı YAZILDI.',
        );
    }

    /** Kanal tipini aktifleştirir — kapı testlerinin ön koşulu. */
    private function activate(string $code, string $name): void
    {
        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => $name,
                'kind' => 'marketplace',
                'adapter_class' => 'App\\Domain\\Channels\\Adapters\\'
                    .ucfirst($code).'\\'.ucfirst($code).'Adapter',
                'is_active' => true,
            ],
        ));
    }

    // ─────────────────────────────────────────────────── gönderim

    /** Geçerli form bağlantıyı kurar ve listeye yönlendirir. */
    #[Test]
    public function valid_submission_connects_the_store(): void
    {
        [$tenant, $user] = $this->makeTenant();

        Http::fake(['*' => Http::response([], 200)]);

        $response = $this->actingAs($user)->post('/channels', [
            'channel_type_code' => 'woocommerce',
            'label' => 'Ana Mağaza',
            'store_url' => 'https://magaza.example.com',
            'consumer_key' => 'ck_test',
            'consumer_secret' => 'cs_test',
        ]);

        $response->assertRedirect('/channels');

        $connection = $this->asTenant($tenant, fn () => ChannelConnection::query()->firstOrFail());

        $this->assertSame('active', $connection->status);
        $this->assertSame('magaza.example.com', $connection->external_account_id);
    }

    /**
     * SAĞLIK KONTROLÜ BAŞARISIZSA KULLANICI UYARILIR.
     *
     * Bağlantı kaydedilir ama `pending` kalır; kullanıcıya sessizce "başarılı"
     * denmez. Sessiz başarı en pahalı hata biçimidir: kullanıcı ürün
     * göndermeye başlar ve hiçbiri gitmez.
     */
    #[Test]
    public function failed_health_check_warns_the_user(): void
    {
        [$tenant, $user] = $this->makeTenant();

        Http::fake(['*' => Http::response(['message' => 'unauthorized'], 401)]);

        $response = $this->actingAs($user)->post('/channels', [
            'channel_type_code' => 'woocommerce',
            'label' => 'Ana Mağaza',
            'store_url' => 'https://magaza.example.com',
            'consumer_key' => 'ck_yanlis',
            'consumer_secret' => 'cs_yanlis',
        ]);

        $response->assertRedirect('/channels');
        $response->assertSessionHas('warning');

        $connection = $this->asTenant($tenant, fn () => ChannelConnection::query()->firstOrFail());

        $this->assertSame('pending', $connection->status);
    }

    /** Eksik alanlar doğrulama hatası verir; hiçbir şey yazılmaz. */
    #[Test]
    public function missing_fields_fail_validation(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $response = $this->actingAs($user)->post('/channels', [
            'channel_type_code' => 'woocommerce',
        ]);

        $response->assertSessionHasErrors(['label', 'store_url', 'consumer_key', 'consumer_secret']);

        $this->assertSame(
            0,
            $this->asTenant($tenant, fn (): int => ChannelConnection::query()->count()),
        );
        $this->assertSame(
            0,
            $this->asTenant($tenant, fn (): int => ChannelCredential::query()->count()),
        );
    }

    /** Bilinmeyen kanal tipi reddedilir. */
    #[Test]
    public function unknown_channel_type_is_rejected(): void
    {
        [, $user] = $this->makeTenant();

        $response = $this->actingAs($user)->post('/channels', [
            'channel_type_code' => 'hepsiburada',
            'label' => 'Test',
            'store_url' => 'https://x.example.com',
            'consumer_key' => 'ck',
            'consumer_secret' => 'cs',
        ]);

        $response->assertSessionHasErrors('channel_type_code');
    }

    /**
     * Başka kiracının bağladığı mağaza anlaşılır hata verir.
     *
     * Veritabanı kısıtı zaten engelliyor; kullanıcıya 500 yerine ne olduğunu
     * söyleyen bir doğrulama hatası gösterilir.
     */
    #[Test]
    public function store_already_connected_elsewhere_shows_a_field_error(): void
    {
        [$tenantA] = $this->makeTenant('A');
        [, $userB] = $this->makeTenant('B');

        Http::fake(['*' => Http::response([], 200)]);

        $this->asTenant($tenantA, fn () => ChannelConnection::factory()->create([
            'external_account_id' => 'paylasilan.example.com',
        ]));

        $response = $this->actingAs($userB)->post('/channels', [
            'channel_type_code' => 'woocommerce',
            'label' => 'Aynı Mağaza',
            'store_url' => 'https://paylasilan.example.com',
            'consumer_key' => 'ck',
            'consumer_secret' => 'cs',
        ]);

        $response->assertSessionHasErrors('store_url');
    }

    // ─────────────────────────────────────────────────── sağlık kontrolü tekrarı

    /** Kullanıcı sağlık kontrolünü elle tekrar çalıştırabilir. */
    #[Test]
    public function health_check_can_be_rerun_from_the_panel(): void
    {
        [$tenant, $user] = $this->makeTenant();

        $connection = $this->asTenant($tenant, fn () => ChannelConnection::factory()->create([
            'status' => 'pending',
            'health_status' => 'unhealthy',
            'last_error' => 'HTTP 401',
            'settings' => ['base_url' => 'https://magaza.example.com/wp-json/wc/v3'],
        ]));

        Http::fake(['*' => Http::response([], 200)]);

        $response = $this->actingAs($user)->post("/channels/{$connection->id}/health");

        $response->assertRedirect('/channels');

        $fresh = $this->asTenant($tenant, fn () => $connection->fresh());

        $this->assertSame('healthy', $fresh->health_status);
        $this->assertSame('active', $fresh->status, 'İyileşen bağlantı aktifleşir.');
        $this->assertNull($fresh->last_error, 'Eski hata temizlenmeli.');
    }

    /** Başka kiracının bağlantısında sağlık kontrolü çalıştırılamaz. */
    #[Test]
    public function health_check_cannot_target_another_tenants_connection(): void
    {
        [$tenantA] = $this->makeTenant('A');
        [, $userB] = $this->makeTenant('B');

        $connection = $this->asTenant($tenantA, fn () => ChannelConnection::factory()->create());

        $this->actingAs($userB)
            ->post("/channels/{$connection->id}/health")
            ->assertNotFound();
    }

    // ─────────────────────────────────────────────────── yardımcılar

    /** @return array{0: Tenant, 1: User} */
    private function makeTenant(string $name = 'Kanal'): array
    {
        $this->asSystem(fn () => ChannelType::query()->firstOrCreate(
            ['code' => 'woocommerce'],
            [
                'name' => 'WooCommerce',
                'kind' => 'storefront',
                'adapter_class' => WooCommerceAdapter::class,
                'supports_webhooks' => true,
                'is_active' => true,
            ],
        ));

        $user = User::factory()->create();

        $tenant = (new CreateTenant)->run(name: $name.' '.uniqid(), owner: $user);

        return [$tenant, $user];
    }
}
