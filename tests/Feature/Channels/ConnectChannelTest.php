<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Actions\ConnectChannel;
use App\Domain\Channels\Adapters\WooCommerce\WooCommerceAdapter;
use App\Domain\Channels\Exceptions\AccountAlreadyConnectedException;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelCredential;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kanal bağlama — §13 · faz 1.4.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · faz 1.4, §11 · kimlik bilgisi yönetimi.
 *
 * Dokümanın doğrulama ölçütü: "gerçek Woo mağazasına bağlanıyor, sırlar
 * loglarda görünmüyor".
 *
 * DEĞİŞMEZ KURAL — SAĞLIK KONTROLÜ GEÇMEDEN BAĞLANTI AKTİF OLMAZ:
 *   Kimlik bilgisi kasaya yazılır (çağrıyı yapabilmek için zorunlu) ama
 *   `status` yalnızca sağlık kontrolü geçerse `active` olur. Yanlış anahtarla
 *   kurulan bağlantı `pending` kalır ve `last_error` taşır. Aksi halde
 *   bağlantı panelde yeşil görünür, ilk gerçek senkronda 401 alır ve
 *   kullanıcı sorunun kaynağını çok sonra öğrenir.
 *
 * DEĞİŞMEZ KURAL — BİR MAĞAZA TEK KİRACIYA BAĞLANIR:
 *   `(channel_type_code, external_account_id)` global tekildir. Kısıt
 *   güvenlik amaçlıdır: aynı alan adı iki kiracıya bağlanabilseydi, alan
 *   adından kiracı çözen servis çağrıları (§11) belirsiz kalırdı.
 *
 * DEĞİŞMEZ KURAL — SIRLAR SADECE KASADA:
 *   `consumer_secret` `channel_connections.settings` içine YAZILMAZ; orası
 *   şifrelenmemiş jsonb'dir ve panele olduğu gibi gönderilir.
 */
final class ConnectChannelTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────────────────── mutlu yol

    /** Sağlık kontrolü geçerse bağlantı aktif olur ve sırlar kasaya yazılır. */
    #[Test]
    public function successful_health_check_activates_the_connection(): void
    {
        $tenant = $this->makeTenant();

        Http::fake([
            '*/wp-json/wc/v3/system_status*' => Http::response(['environment' => []], 200),
        ]);

        $connection = $this->asTenant($tenant, fn (): ChannelConnection => $this->connect());

        $this->assertSame('active', $connection->status);
        $this->assertSame('healthy', $connection->health_status);
        $this->assertNotNull($connection->connected_at);
        $this->assertNotNull($connection->last_healthy_at);
        $this->assertNull($connection->last_error);

        // Mağaza kimliği alan adından türer — kullanıcı elle girmez.
        $this->assertSame('magaza.example.com', $connection->external_account_id);

        // Sırlar KASADA ve şifreli.
        $secrets = $this->asTenant(
            $tenant,
            fn (): array => app(CredentialVault::class)->read($connection),
        );

        $this->assertSame('ck_test_key', $secrets['consumer_key']);
        $this->assertSame('cs_test_secret', $secrets['consumer_secret']);

        $stored = $this->asTenant(
            $tenant,
            fn (): ChannelCredential => ChannelCredential::query()->firstOrFail(),
        );

        $this->assertStringNotContainsString(
            'cs_test_secret',
            $stored->encrypted_payload,
            'Kimlik bilgisi düz metin saklanmamalı.',
        );
    }

    /**
     * SIRLAR `settings` İÇİNE YAZILMAZ.
     *
     * `settings` şifrelenmemiş jsonb'dir ve panele olduğu gibi gönderilir.
     * Oraya yazılan bir anahtar hem veritabanı yedeğinde hem HTTP yanıtında
     * düz metin görünür.
     */
    #[Test]
    public function secrets_never_land_in_the_settings_column(): void
    {
        $tenant = $this->makeTenant();

        Http::fake(['*' => Http::response([], 200)]);

        $connection = $this->asTenant($tenant, fn (): ChannelConnection => $this->connect());

        $rawSettings = $this->asSystem(fn (): string => (string) DB::table('channel_connections')
            ->where('id', $connection->id)
            ->value('settings'));

        $this->assertStringNotContainsString('cs_test_secret', $rawSettings);
        $this->assertStringNotContainsString('ck_test_key', $rawSettings);

        // Ama taban adres orada olmalı — istemci onu okur.
        $this->assertSame(
            'https://magaza.example.com/wp-json/wc/v3',
            $connection->settings['base_url'],
        );
    }

    // ─────────────────────────────────────────────────── sağlıksız yol

    /**
     * SAĞLIK KONTROLÜ BAŞARISIZSA BAĞLANTI AKTİF OLMAZ.
     *
     * Yanlış anahtar 401 döner. Bağlantı `pending` kalır, hata yazılır ve
     * panelde görünür — kullanıcı düzeltip tekrar dener.
     */
    #[Test]
    public function failed_health_check_leaves_the_connection_pending(): void
    {
        $tenant = $this->makeTenant();

        Http::fake([
            '*' => Http::response(['message' => 'Sorry, you cannot list resources.'], 401),
        ]);

        $connection = $this->asTenant($tenant, fn (): ChannelConnection => $this->connect());

        $this->assertSame('pending', $connection->status, 'Sağlıksız bağlantı aktif olmamalı.');
        $this->assertSame('unhealthy', $connection->health_status);
        $this->assertNull($connection->connected_at, 'Bağlanmamış bağlantıya tarih yazılmaz.');
        $this->assertNull($connection->last_healthy_at);
        $this->assertNotNull($connection->last_error, 'Hata kullanıcıya gösterilmek üzere yazılmalı.');
    }

    /**
     * Sağlıksız bağlantıda da kimlik bilgisi SAKLANIR.
     *
     * Kullanıcı mağazası geçici olarak kapalıyken bağlamayı denemiş olabilir;
     * anahtarı silmek onu her seferinde yeniden girmeye zorlar. Bağlantı
     * `pending` olduğu için senkron zaten çalışmaz.
     */
    #[Test]
    public function credentials_are_kept_even_when_health_check_fails(): void
    {
        $tenant = $this->makeTenant();

        Http::fake(['*' => Http::response([], 500)]);

        $connection = $this->asTenant($tenant, fn (): ChannelConnection => $this->connect());

        $this->assertSame(
            1,
            $this->asTenant($tenant, fn (): int => ChannelCredential::query()->count()),
        );
        $this->assertSame('pending', $connection->status);
    }

    /** Ağ hatası da sağlıksızlıktır — istisna dışarı sızmaz. */
    #[Test]
    public function network_failure_is_reported_as_unhealthy(): void
    {
        $tenant = $this->makeTenant();

        Http::fake(fn () => throw new ConnectionException('Bağlanılamadı'));

        $connection = $this->asTenant($tenant, fn (): ChannelConnection => $this->connect());

        $this->assertSame('pending', $connection->status);
        $this->assertSame('unhealthy', $connection->health_status);
        $this->assertNotNull($connection->last_error);
    }

    // ─────────────────────────────────────────────────── tekillik

    /**
     * AYNI MAĞAZA İKİNCİ KİRACIYA BAĞLANAMAZ (§11 · güvenlik kısıtı).
     *
     * Kısıt veritabanında da var; action onu anlaşılır bir istisnaya çevirir
     * ki panel kullanıcıya ne olduğunu söyleyebilsin.
     */
    #[Test]
    public function the_same_store_cannot_be_connected_by_two_tenants(): void
    {
        $first = $this->makeTenant('Birinci');
        $second = $this->makeTenant('İkinci');

        Http::fake(['*' => Http::response([], 200)]);

        $this->asTenant($first, fn (): ChannelConnection => $this->connect());

        $this->expectException(AccountAlreadyConnectedException::class);

        $this->asTenant($second, fn (): ChannelConnection => $this->connect());
    }

    /**
     * Aynı kiracı aynı mağazayı yeniden bağlarsa YENİ SATIR AÇILMAZ.
     *
     * Anahtar yenileme akışı budur: kullanıcı yeni bir anahtar çifti üretip
     * tekrar bağlar. İkinci satır açılsaydı `(tenant, type, account)` kısıtı
     * ihlal edilirdi ve listing'ler eski bağlantıya asılı kalırdı.
     */
    #[Test]
    public function reconnecting_the_same_store_updates_in_place(): void
    {
        $tenant = $this->makeTenant();

        Http::fake(['*' => Http::response([], 200)]);

        $first = $this->asTenant($tenant, fn (): ChannelConnection => $this->connect());

        $second = $this->asTenant($tenant, fn (): ChannelConnection => $this->connect(
            consumerKey: 'ck_yeni',
            consumerSecret: 'cs_yeni',
        ));

        $this->assertSame($first->id, $second->id, 'Aynı mağaza için yeni satır açılmamalı.');
        $this->assertSame(
            1,
            $this->asTenant($tenant, fn (): int => ChannelConnection::query()->count()),
        );

        // Kasada da tek AKTİF kayıt var ve yeni anahtarı taşıyor.
        $secrets = $this->asTenant(
            $tenant,
            fn (): array => app(CredentialVault::class)->read($second),
        );

        $this->assertSame('ck_yeni', $secrets['consumer_key']);
        $this->assertSame(
            1,
            $this->asTenant($tenant, fn (): int => ChannelCredential::query()
                ->whereNull('revoked_at')
                ->count()),
            'Kısmi tekil indeks tek aktif kimlik bilgisine izin verir.',
        );
    }

    // ─────────────────────────────────────────────────── alan adı normalleştirme

    /**
     * Mağaza adresi normalleştirilir.
     *
     * Kullanıcı adresi çok biçimde girer: şema ile veya olmadan, sondaki
     * eğik çizgiyle, büyük harfle. Normalleştirilmezse aynı mağaza iki
     * farklı `external_account_id` ile iki kez bağlanabilir ve global
     * tekillik kısıtı hiçbir şey korumaz.
     *
     * @param  string  $input
     */
    #[Test]
    public function store_url_is_normalised_before_uniqueness_is_checked(): void
    {
        $tenant = $this->makeTenant();

        Http::fake(['*' => Http::response([], 200)]);

        $first = $this->asTenant($tenant, fn (): ChannelConnection => $this->connect(
            storeUrl: 'https://magaza.example.com/',
        ));

        // Aynı mağaza, farklı yazım: yeni satır AÇILMAMALI.
        $second = $this->asTenant($tenant, fn (): ChannelConnection => $this->connect(
            storeUrl: 'HTTPS://MaGaZa.example.com',
        ));

        $this->assertSame($first->id, $second->id);
        $this->assertSame('magaza.example.com', $second->external_account_id);
    }

    /** Şemasız adres https varsayar — Woo Basic auth'u yalnızca HTTPS'te güvenli. */
    #[Test]
    public function scheme_less_url_defaults_to_https(): void
    {
        $tenant = $this->makeTenant();

        Http::fake(['*' => Http::response([], 200)]);

        $connection = $this->asTenant($tenant, fn (): ChannelConnection => $this->connect(
            storeUrl: 'magaza.example.com',
        ));

        $this->assertStringStartsWith('https://', $connection->settings['base_url']);
    }

    // ─────────────────────────────────────────────────── yardımcılar

    private function connect(
        string $storeUrl = 'https://magaza.example.com',
        string $consumerKey = 'ck_test_key',
        string $consumerSecret = 'cs_test_secret',
        string $label = 'Ana Mağaza',
    ): ChannelConnection {
        return app(ConnectChannel::class)->run(
            channelTypeCode: 'woocommerce',
            label: $label,
            storeUrl: $storeUrl,
            secrets: [
                'consumer_key' => $consumerKey,
                'consumer_secret' => $consumerSecret,
            ],
        );
    }

    private function makeTenant(string $name = 'Kanal'): Tenant
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

        return (new CreateTenant)->run(
            name: $name.' '.uniqid(),
            owner: User::factory()->create(),
        );
    }
}
