<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domain\Channels\Actions\ConnectChannel;
use App\Domain\Channels\Adapters\WooCommerce\WooCommerceAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Sync\Support\ChannelErrorText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * KALICI HATA METNİ MASKELENİR — §11 · "iki katmanlı maskeleme".
 *
 * Mimari Karar Dokümanı v2.2 · §11 · "Kişisel veri ve sır maskeleme" ve
 * "Minimum production kontrol listesi" → "Günlüklerde sır ve kişisel veri
 * taraması temiz (iki katman)".
 *
 * ─────────────────────────────────────────────────────────────────────
 * BU TESTİN VARLIK SEBEBİ — GERÇEK BİR SIZINTI BULUNDU
 * ─────────────────────────────────────────────────────────────────────
 * İki katmanlı maskeleme YALNIZCA `api_calls` yolunda uygulanmıştı.
 * `channel_connections.last_error`, `sync_attempts.error_message` ve
 * `listing_sync_states.last_error` ham `$e->getMessage()` yazıyordu.
 *
 * O metin masum değildir: Laravel'in `RequestException::prepareMessage()`
 * yanıt GÖVDESİNİN ilk 120 karakterini mesaja gömer (framework kaynağında
 * doğrulandı). Kanal 401 gövdesinde anahtarı yansıtırsa — Woo ve Trendyol
 * dahil pek çok kanalda yaygın davranış — sır şu zinciri izler:
 *
 *     kanal 401 gövdesi → RequestException mesajı → last_error kolonu
 *       → Inertia prop → TARAYICI
 *
 * Yani şifrelenmiş kasanın tüm anlamı kaybolur: sır şifresiz bir kolonda
 * düz metin durur, veritabanı yedeğine girer ve panelde GÖRÜNÜR.
 *
 * Sızıntının en olası anı, sırrın yanlış girildiği andır — yani tam olarak
 * kullanıcının anahtarı ekrana yazdığı ve hata metnini okuduğu an.
 *
 * ─────────────────────────────────────────────────────────────────────
 * NEDEN KATMAN 1 YETMEZ
 * ─────────────────────────────────────────────────────────────────────
 * Katman 1 anahtar ADINA bakar (`{"api_key": "..."}`). Buradaki sır bir
 * anahtarın değeri değil, DÜZ METNİN İÇİNDE geçen bir dizedir:
 * `Invalid consumer secret: cs_live_...`. Onu ancak katman 2 (bilinen sır
 * değerlerini gövdede ara-değiştir) yakalar. §11'in katman 2'yi
 * tanımlarken verdiği örnek birebir budur.
 */
final class ErrorMessageRedactionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * SIR, SAĞLIK KONTROLÜ HATASINDAN `last_error`'A SIZMAZ.
     *
     * Bu testin kırılması, sırrın veritabanına ve panele düz metin
     * yazıldığı anlamına gelir.
     *
     * NEDEN İSTİSNA YOLU SINANIYOR, DURUM KODU YOLU DEĞİL:
     *   Woo'nun `healthCheck()`'i 2xx olmayan yanıtta yalnızca
     *   `"HTTP {$status}"` üretir — gövde o dalda hiç okunmaz ve sır o
     *   yoldan SIZMAZ. Metni kanalın gövdesiyle dolduran dal
     *   İSTİSNA dalıdır (`catch`): `RequestException::prepareMessage()`
     *   yanıt gövdesinin ilk 120 karakterini mesaja gömer ve adapter'ın
     *   `$response->throw()` çağıran her yolu buradan geçer.
     *
     *   Bağlantı hatası da aynı dala düşer ve mesajı istemcinin ürettiği
     *   metindir — burada sırrın URL'de taşındığı kanallar için de
     *   maskeleme gerekir.
     */
    #[Test]
    public function channel_secret_never_reaches_last_error(): void
    {
        $tenant = $this->makeTenant();

        // Kanal, hata gövdesinde anahtarı YANSITIYOR — gerçek kanallarda
        // yaygın davranış ve maskelemenin var olma sebebi. İstisna
        // fırlatılır ki metin gövdeyi taşısın (sınıf başlığındaki gerekçe).
        Http::fake([
            '*' => fn () => throw new ConnectionException(
                'cURL error 7: Failed connecting to '.
                'https://ck_test_key:cs_super_secret_value@magaza.example.com'
            ),
        ]);

        $connection = $this->asTenant($tenant, fn (): ChannelConnection => $this->connect());

        // Sağlık kontrolü geçmedi: bağlantı beklemede kalmalı ve hata
        // metni taşımalı — maskeleme hatayı GİZLEMEZ, yalnızca sırrı siler.
        $this->assertSame('pending', $connection->status);
        $this->assertNotNull($connection->last_error);

        // Kolon ham okunur: model cast'i değil, DİSKTEKİ değer sınanır.
        $stored = $this->asSystem(fn (): ?string => DB::table('channel_connections')
            ->where('id', $connection->id)
            ->value('last_error'));

        $this->assertNotNull($stored);

        $this->assertStringNotContainsString(
            'cs_super_secret_value',
            $stored,
            'Kanal kimlik bilgisi last_error kolonuna düz metin yazıldı — '.
            'bu değer Inertia prop\'u olarak tarayıcıya da gider.',
        );

        // Hata TAMAMEN yutulmamalı: kullanıcı neyin yanlış gittiğini
        // görebilmeli, yoksa maskeleme teşhisi imkânsız kılar.
        $this->assertStringContainsString('[redacted]', $stored);
    }

    /**
     * KISA DEĞER MASKELENMEZ — yanlış eşleşme metni okunmaz kılar.
     *
     * §11: "kısa değerler yanlış eşleşir". `consumer_key` sekiz karakterden
     * kısaysa onu aramak hata metnindeki masum alt dizeleri de siler.
     */
    #[Test]
    public function short_secrets_do_not_corrupt_the_message(): void
    {
        $tenant = $this->makeTenant();

        Http::fake([
            '*' => fn () => throw new ConnectionException('Store is temporarily closed'),
        ]);

        // Sır KISA: sekiz karakterin altında.
        $connection = $this->asTenant($tenant, fn (): ChannelConnection => $this->connect(
            consumerKey: 'ck',
            consumerSecret: 'cs',
        ));

        $stored = $this->asSystem(fn (): ?string => DB::table('channel_connections')
            ->where('id', $connection->id)
            ->value('last_error'));

        // "closed" içindeki "c" ve "s" harfleri maskelenmemeli.
        $this->assertNotNull($stored);
        $this->assertStringContainsString('temporarily closed', $stored);
    }

    /**
     * MASKELEME BAĞLAM OLMADAN DA ÇALIŞIR.
     *
     * `ChannelErrorText` kuyruk işinden ve `runAsSystem()` taramasından da
     * çağrılır; kimlik bilgisini okumak için kiracı bağlamı beklerse
     * maskeleme SESSİZCE devre dışı kalır ve sır yine sızar. Bu, projede
     * daha önce gerçek bir üretim hatası olarak yaşandı (`97a7eb7`).
     */
    #[Test]
    public function redaction_works_without_tenant_context(): void
    {
        $tenant = $this->makeTenant();

        Http::fake(['*' => Http::response([], 200)]);

        $connection = $this->asTenant($tenant, fn (): ChannelConnection => $this->connect());

        // Bağlam YOK — kuyruk işinin başlangıç durumu.
        $redacted = app(ChannelErrorText::class)->redact(
            $connection,
            'Invalid consumer secret: cs_super_secret_value',
        );

        $this->assertNotNull($redacted);
        $this->assertStringNotContainsString('cs_super_secret_value', $redacted);
    }

    // ─────────────────────────────────────────────────── yardımcılar

    private function connect(
        string $consumerKey = 'ck_test_key',
        string $consumerSecret = 'cs_super_secret_value',
    ): ChannelConnection {
        return app(ConnectChannel::class)->run(
            channelTypeCode: 'woocommerce',
            label: 'Ana Mağaza',
            storeUrl: 'https://magaza.example.com',
            secrets: [
                'consumer_key' => $consumerKey,
                'consumer_secret' => $consumerSecret,
            ],
        );
    }

    private function makeTenant(string $name = 'Maskeleme'): Tenant
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
