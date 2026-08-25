<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Shopify\ShopifyAdapter;
use App\Domain\Channels\Adapters\WooCommerce\WooCommerceAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelCredential;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Routing\ChannelLifecycleRouter;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Messaging\Actions\IngestInboxMessage;
use App\Domain\Messaging\Jobs\ProcessInboxMessage;
use App\Domain\Messaging\Models\InboxMessage;
use App\Domain\Orders\Actions\IngestChannelOrder;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderEvent;
use App\Domain\Orders\Support\IncomingOrder;
use App\Domain\Orders\Support\IncomingOrderLine;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Shopify `app/uninstalled` — slice 1.9.
 *
 * V3.0 · §06.7 · §19 (olay yönlendirme) · P1-2 · T-V3-22 · v2.2 §6 · §7.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ BU KONU SİPARİŞ OLAYI DEĞİLDİR — ve sessizce öyle sanılıyordu
 * ─────────────────────────────────────────────────────────────────────
 * `ShopifyOrderNormalizer::TOPIC_TO_TYPE` tablosunda `app/uninstalled`
 * YOKTUR ve bilinmeyen konu `updated` sayılır. Yani slice 1.9'dan ÖNCE bu
 * webhook geldiğinde olay bir SİPARİŞ GÜNCELLEMESİ sanılıyor, gövdedeki
 * `id` (mağazanın kimliği) sipariş kimliği yerine okunuyor ve
 * `resolveOrder()` onu bulamayıp yalnızca uyarı yazıyordu.
 *
 * Sonuç: token sessizce geçersizleşmiş olmasına rağmen bağlantı `active`
 * kalıyor, her istek 401 alıyor, `AUTHENTICATION` KALICI sayılıyor ve
 * satıcının tüm listing'leri TEKER TEKER ölüyordu. Panel "anahtarınız
 * yanlış" diyor — oysa satıcı hiçbir şey değiştirmemiştir, uygulamayı
 * kaldırmıştır (§06.7).
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ KATMAN KARARI — SİPARİŞ ROUTER'INDAN ÖNCE, AYRI BİR ROUTER
 * ─────────────────────────────────────────────────────────────────────
 * Dal `OrderEventRouter`'a KONMAZ: o sınıf Orders domain'idir ve bu olay
 * `channel_credentials` + `channel_connections` YAZAR. Modül sınırı kuralı
 * (CLAUDE.md) bir domain'in başka domain'in modeline yazmasını yasaklar.
 *
 * Mesaj yine de AYNI `inbox_messages` hattına girer (§19: "hepsi aynı
 * hatta; ikinci bir olay işleme sistemi AÇILMAZ") — yalnızca inbox
 * tüketicisi, sipariş router'ından ÖNCE lifecycle router'a sorar.
 */
final class ShopifyAppUninstalledTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────────────── §06.7 · asıl davranış

    /**
     * ⚠️ BAĞLANTI SİLİNMEZ, İŞARETLENİR — P1-2 · T-V3-22.
     *
     * Listing ve sipariş geçmişi bağlantıya bağlıdır; silinseydi satıcının
     * tüm geçmişi bir webhook'la yok olurdu ve bu GERİ ALINAMAZ. Satıcı
     * uygulamayı yeniden kurarsa `ConnectChannel` aynı satırı `firstOrNew`
     * ile yeniden kullanır (anahtar yenileme akışı) ve KOTADAN ETKİLENMEZ.
     */
    #[Test]
    public function the_connection_is_marked_not_deleted(): void
    {
        [$tenant, $connection] = $this->connectedShop();

        $this->uninstall($tenant, $connection);

        $row = TenantContext::runAsSystem(
            fn () => ChannelConnection::query()->find($connection->id)
        );

        $this->assertNotNull(
            $row,
            'Bağlantı SİLİNDİ — listing ve sipariş geçmişi ona bağlıydı ve '
            .'bu kayıp geri alınamaz (§06.7 · P1-2).',
        );
        $this->assertSame('inactive', $row->status);
    }

    /**
     * ⚠️ TOKEN İPTAL EDİLİR — `revoked_at` yazılır.
     *
     * Yazılmasaydı `TokenRefresher` taraması o kimlik bilgisini hâlâ
     * geçerli sayar ve her turda yenilemeye çalışırdı; kanal 401 döner ve
     * bağlantı "anahtarın yanlış" diyerek ölürdü.
     */
    #[Test]
    public function the_credential_is_revoked(): void
    {
        [$tenant, $connection] = $this->connectedShop();

        $this->uninstall($tenant, $connection);

        $credential = TenantContext::runAsSystem(
            fn () => ChannelCredential::query()
                ->where('channel_connection_id', $connection->id)
                ->first()
        );

        $this->assertNotNull($credential);
        $this->assertNotNull(
            $credential->revoked_at,
            '`revoked_at` yazılmadı — token geçersizken tarama onu yenilemeye '
            .'çalışır ve bağlantı kalıcı hataya düşer.',
        );
    }

    /**
     * ⚠️ SEBEP YAZILIR — satıcı NEDEN olduğunu görmeli.
     *
     * `last_error` panele Inertia prop'u olarak gider. Boş bırakılsaydı
     * satıcı bağlantısının neden durduğunu hiçbir yerde göremez ve
     * anahtarını yenilemeye çalışırdı — oysa sorun anahtarda değildir.
     */
    #[Test]
    public function the_reason_is_written_for_the_seller(): void
    {
        [$tenant, $connection] = $this->connectedShop();

        $this->uninstall($tenant, $connection);

        $row = TenantContext::runAsSystem(
            fn () => ChannelConnection::query()->find($connection->id)
        );

        $this->assertSame(
            'Uygulama Shopify mağazasından kaldırıldı.',
            $row->last_error,
        );
    }

    // ──────────────────────────────────── sipariş yoluna SIZMAZ (asıl tuzak)

    /**
     * ⚠️ OLAY SİPARİŞ ROUTER'INA HİÇ ULAŞMAZ.
     *
     * Bu testin varlık sebebi: `TOPIC_TO_TYPE` bilinmeyen konuyu `updated`
     * sayar. Lifecycle router olmasaydı olay bir sipariş güncellemesi
     * sanılır ve gövdedeki `id` — MAĞAZANIN kimliği — sipariş kimliği
     * yerine okunurdu.
     *
     * ⚠️ SIZINTI "SİPARİŞ YARATILDI MI" İLE ÖLÇÜLEMEZ — MUTASYON KAÇTI.
     * İlk yazımda bu test `Order::count() === 0` iddia ediyordu ve kapı
     * TAMAMEN kaldırıldığında bile YEŞİL kalıyordu: kaldırma gövdesinde
     * `line_items` yoktur, olay `updated` sayılır ve o yol zaten sipariş
     * YARATMAZ — yalnızca `resolveOrder()` uyarısı yazar. Yani iddia doğru
     * sonucu YANLIŞ SEBEPLE ölçüyordu (slice 1.8'in "iki paket iki satır"
     * tuzağının aynısı).
     *
     * Ayırt edici işaret, olayın MEVCUT bir siparişe DOKUNMASIDIR: mağaza
     * kimliğiyle aynı `external_id`'yi taşıyan gerçek bir sipariş kurulur.
     * Kapı kalkarsa olay o siparişi bulur, `UpdateOrderSnapshot` çalışır ve
     * `order_events` satırı yazılır — sızıntı GÖRÜNÜR olur.
     */
    #[Test]
    public function the_event_never_reaches_the_order_pipeline(): void
    {
        [$tenant, $connection] = $this->connectedShop();

        // Mağaza kimliğiyle AYNI dış kimliği taşıyan gerçek bir sipariş:
        // kaldırma olayı sipariş yoluna sızarsa TAM OLARAK bunu bulur.
        $order = $this->orderWithExternalId($tenant, $connection, '55555555');

        $before = $this->asTenant(
            $tenant,
            fn (): int => OrderEvent::query()->where('order_id', $order->id)->count()
        );

        $message = $this->uninstall($tenant, $connection);

        $this->asTenant($tenant, function () use ($message, $order, $before): void {
            $this->assertSame(
                $before,
                OrderEvent::query()->where('order_id', $order->id)->count(),
                'Kaldırma olayı sipariş yoluna sızdı — bilinmeyen konu '
                .'`updated` sayılıyor ve mağaza kimliği sipariş kimliği '
                .'sanılıyor demektir.',
            );

            $this->assertSame(
                'processed',
                (string) InboxMessage::query()->findOrFail($message->id)->status,
                'Mesaj `processed` değil — `inbox:recover` onu sonsuza kadar '
                .'yeniden dener.',
            );
        });
    }

    /**
     * ⚠️ SİPARİŞ KONUSU LIFECYCLE ROUTER'A TAKILMAZ.
     *
     * Kapı çok geniş yazılsaydı (ör. yalnızca kanal koduna bakan bir dal)
     * Shopify'ın TÜM webhook'ları sipariş yolundan çıkar ve siparişler HİÇ
     * işlenmezdi — kaldırma hatasından çok daha ağır bir sessiz kayıp.
     */
    #[Test]
    public function an_order_topic_is_not_claimed_by_the_lifecycle_router(): void
    {
        [$tenant, $connection] = $this->connectedShop();

        $message = $this->ingest($tenant, $connection, 'orders/create', 'evt-order-1', [
            'id' => 9001,
        ]);

        $claimed = $this->asTenant(
            $tenant,
            fn (): bool => app(ChannelLifecycleRouter::class)->route($message)
        );

        $this->assertFalse(
            $claimed,
            'Sipariş konusu lifecycle router tarafından yutuldu — o kanalın '
            .'siparişleri HİÇ işlenmez.',
        );
    }

    /**
     * ⚠️ BAŞKA KANALIN AYNI ADLI KONUSU BU YOLU AÇMAZ.
     *
     * Kapı kanal koduna DA bakar: `app/uninstalled` Shopify'ın konusudur
     * (§19 · yönlendirme tablosu — diğer beş kanalda ❌). Yalnızca konu
     * adına bakılsaydı başka bir kanalın aynı adlı olayı satıcının
     * bağlantısını sessizce kapatabilirdi.
     */
    #[Test]
    public function the_topic_alone_does_not_open_the_path_for_another_channel(): void
    {
        $tenant = $this->makeTenant();

        $connection = $this->asTenant($tenant, function (): ChannelConnection {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'woocommerce',
                'external_account_id' => 'magaza-'.uniqid().'.example.com',
                'status' => 'active',
            ]);

            app(CredentialVault::class)->store($connection, ['consumer_key' => 'ck_test']);

            return $connection;
        });

        $message = $this->ingest($tenant, $connection, 'app/uninstalled', 'evt-woo-1', [
            'id' => 4242,
        ]);

        $claimed = $this->asTenant(
            $tenant,
            fn (): bool => app(ChannelLifecycleRouter::class)->route($message)
        );

        $this->assertFalse($claimed);

        $row = TenantContext::runAsSystem(
            fn () => ChannelConnection::query()->find($connection->id)
        );

        $this->assertSame(
            'active',
            $row->status,
            'Başka kanalın aynı adlı konusu bağlantıyı kapattı.',
        );
    }

    // ───────────────────────────────────────────────────────────── tekrar

    /**
     * ⚠️ TEKRAR GELEN OLAY İKİNCİ KEZ ZARAR VERMEZ.
     *
     * Shopify webhook'ları EN AZ BİR KEZ gönderilir. İkinci tur zaten
     * iptal edilmiş kimlik bilgisini yeniden iptal etmeye çalışırsa
     * `activeCredential()` boş döner (o ilişki `revoked_at IS NULL`
     * filtresi taşır) ve akış patlamamalıdır.
     */
    #[Test]
    public function a_repeated_uninstall_is_harmless(): void
    {
        [$tenant, $connection] = $this->connectedShop();

        $this->uninstall($tenant, $connection);
        $this->uninstall($tenant, $connection, eventId: 'evt-uninstall-2');

        $row = TenantContext::runAsSystem(
            fn () => ChannelConnection::query()->find($connection->id)
        );

        $this->assertSame('inactive', $row->status);
        $this->assertSame(
            1,
            TenantContext::runAsSystem(
                fn (): int => ChannelCredential::query()
                    ->where('channel_connection_id', $connection->id)
                    ->whereNotNull('revoked_at')
                    ->count()
            ),
        );
    }

    // ───────────────────────────────────────── GERÇEK HTTP YOLU (uçtan uca)

    /**
     * ⚠️ İMZALI WEBHOOK GERÇEK ROTADAN GEÇER — HER KATMAN DAHİL.
     *
     * Diğer testler `ProcessInboxMessage`'ı elle çağırır ve HTTP katmanını
     * ATLAR: içerik tipi kapısı, hız sınırı, İMZA DOĞRULAMASI, olay
     * kimliği çıkarımı ve kuyruğa atma kararı sınanmadan kalır. Projenin
     * "gerçek çalıştırma" kuralı her turda tam bu boşlukta ölümcül hata
     * buldu (`supports_webhooks` ve `adapter_class` eager-load tuzakları).
     *
     * Burada asıl risk şudur: `app/uninstalled` gövdesi imzalanır ve
     * `extractEventType` başlıktan `x-shopify-topic` okur. Konu başlığı
     * okunamasaydı lifecycle router konuyu HİÇ eşleştiremez, olay sipariş
     * yoluna düşer ve bağlantı açık kalırdı — testler yine yeşil olurdu
     * çünkü hepsi konuyu elle veriyor.
     *
     * Kuyruk `sync` sürücüsündedir; iş derhal çalışır.
     */
    #[Test]
    public function a_signed_webhook_closes_the_connection_end_to_end(): void
    {
        [$tenant, $connection] = $this->connectedShop();

        $body = (string) json_encode(['id' => 55555555, 'domain' => 'deneme.myshopify.com']);

        $response = $this->call(
            method: 'POST',
            uri: '/webhooks/'.$connection->id,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SHOPIFY_TOPIC' => 'app/uninstalled',
                'HTTP_X_SHOPIFY_EVENT_ID' => 'evt-http-1',
                'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(
                    hash_hmac('sha256', $body, 'whsec-test', true)
                ),
            ],
            content: $body,
        );

        $response->assertStatus(202);

        $row = TenantContext::runAsSystem(
            fn () => ChannelConnection::query()->find($connection->id)
        );

        $this->assertSame(
            'inactive',
            $row->status,
            'İmzalı webhook gerçek rotadan geçtiğinde bağlantı KAPANMADI — '
            .'konu başlığı okunamıyor veya kapı hiç çalışmıyor olabilir.',
        );
    }

    // ────────────────────────────────────────────────────────── yardımcılar

    /**
     * Kaldırma olayını GERÇEK gelen hattan geçirir.
     *
     * `ProcessInboxMessage` üzerinden gidilir, lifecycle router doğrudan
     * çağrılmaz: bu slice'ın kapattığı sınır tam olarak "olay sipariş
     * router'ına ULAŞMADAN ele alınır" idi ve tüketiciyi atlayan bir test
     * o sırayı hiç sınamazdı (slice 1.8'in router dalı kuralının aynısı).
     */
    private function uninstall(
        Tenant $tenant,
        ChannelConnection $connection,
        string $eventId = 'evt-uninstall-1',
    ): InboxMessage {
        $message = $this->ingest($tenant, $connection, 'app/uninstalled', $eventId, [
            // Gövde MAĞAZA nesnesidir — `id` mağazanın kimliğidir, sipariş
            // kimliği DEĞİL. Sipariş yoluna sızarsa tam da bu okunurdu.
            'id' => 55555555,
            'name' => 'Deneme Mağaza',
            'domain' => 'deneme.myshopify.com',
        ]);

        (new ProcessInboxMessage($tenant->id, $message->id))->handle();

        return $message;
    }

    /**
     * Verilen dış kimlikle GERÇEK alım yolundan geçmiş bir sipariş.
     *
     * `Order` satırı ELLE YAZILMAZ (`OrderScreenTest`'in kuralı): elle
     * yazmak `stock_status` gibi alanları uydurmak demektir ve test gerçek
     * veriyi değil kendi varsayımını doğrular.
     */
    private function orderWithExternalId(
        Tenant $tenant,
        ChannelConnection $connection,
        string $externalId,
    ): Order {
        return $this->asTenant($tenant, function () use ($connection, $externalId): Order {
            $variant = Variant::factory()->create(['sku' => 'TSH-KIRMIZI-M']);

            $warehouseId = (string) Warehouse::query()
                ->where('is_default', true)
                ->value('id');

            (new IngestChannelOrder)->run(
                new IncomingOrder(
                    channelConnectionId: $connection->id,
                    externalId: $externalId,
                    lines: [new IncomingOrderLine(
                        externalLineId: '555',
                        sku: 'TSH-KIRMIZI-M',
                        title: 'Tişört',
                        quantity: 1,
                        variantId: $variant->id,
                        unitPrice: '19.90',
                        lineTotal: '19.90',
                    )],
                    grandTotal: '19.90',
                ),
                $warehouseId,
            );

            return Order::query()->where('external_id', $externalId)->firstOrFail();
        });
    }

    /** @param array<string, mixed> $payload */
    private function ingest(
        Tenant $tenant,
        ChannelConnection $connection,
        string $topic,
        string $eventId,
        array $payload,
    ): InboxMessage {
        return $this->asTenant($tenant, fn (): InboxMessage => app(IngestInboxMessage::class)->run(
            connection: $connection,
            source: 'webhook',
            externalEventId: $eventId,
            eventType: $topic,
            payload: (string) json_encode($payload),
            signatureValid: true,
        ));
    }

    /** @return array{0: Tenant, 1: ChannelConnection} */
    private function connectedShop(): array
    {
        $tenant = $this->makeTenant();

        $connection = $this->asTenant($tenant, function (): ChannelConnection {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'shopify',
                'external_account_id' => 'magaza-'.uniqid().'.myshopify.com',
                'status' => 'active',
                'settings' => ['location_gid' => 'gid://shopify/Location/12'],
            ]);

            // `webhook_secret` DE yazılır: imza doğrulaması onu okur ve
            // sır yoksa doğrulama "geçti" DEMEZ (§06.6 · güvenli taraf
            // REDDETMEKTİR) — uçtan uca test 401 alırdı.
            app(CredentialVault::class)->store($connection, [
                'access_token' => 'shpat_test',
                'webhook_secret' => 'whsec-test',
            ]);

            return $connection;
        });

        return [$tenant, $connection];
    }

    /**
     * Kiracı + kanal türleri.
     *
     * WooCommerce de kaydedilir: "başka kanalın aynı adlı konusu" testi
     * gerçek bir ikinci kanal ister — kanal kodunu uydurmak, kapının
     * gerçekten kanal koduna baktığını değil kendi varsayımını sınardı.
     */
    private function makeTenant(): Tenant
    {
        $this->asSystem(function (): void {
            ChannelType::query()->updateOrCreate(
                ['code' => 'shopify'],
                [
                    'name' => 'Shopify',
                    'kind' => 'storefront',
                    'adapter_class' => ShopifyAdapter::class,
                    'supports_webhooks' => true,
                    'is_active' => false,
                ],
            );

            ChannelType::query()->updateOrCreate(
                ['code' => 'woocommerce'],
                [
                    'name' => 'WooCommerce',
                    'kind' => 'storefront',
                    'adapter_class' => WooCommerceAdapter::class,
                    'supports_webhooks' => true,
                    'is_active' => true,
                ],
            );
        });

        return (new CreateTenant)->run(
            name: 'Shopify Kaldırma '.uniqid(),
            owner: User::factory()->create(),
        );
    }
}
