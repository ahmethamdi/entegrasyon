<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Etsy\EtsyAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Reconciliation\Enums\ReconciliationScope;
use App\Domain\Reconciliation\Models\ReconciliationItem;
use App\Domain\Reconciliation\Support\ReconcileActiveConnections;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\ListingSyncState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Etsy fiyat mutabakatı — slice 3.6'nın "YAZILDI mı ÇAĞRILIYOR mu" testi.
 *
 * ═════════════════════════════════════════════════════════════════════
 * ⚠️ "YAZILDI" DEMEK "ÇAĞRILIYOR" DEMEK DEĞİLDİR
 * ═════════════════════════════════════════════════════════════════════
 * `EtsyPricingTest` adapter gövdesini DOĞRUDAN çağırır ve doğru
 * çalıştığını kanıtlar. Bu dosya BAŞKA bir soru sorar: o gövde
 * ÇEKİRDEĞİN akışından gerçekten çağrılıyor mu?
 *
 * Slice 1.9'un kalıcı dersi buydu: `ReconcileActiveConnections` kanal
 * BİLMEZ (`status = 'active'` süzer, yeteneği `instanceof` ile okur) —
 * yani yeni kanal için yazılan TEK şey `fetchPrices` gövdesidir. Ama o
 * gövde hiç çağrılmıyorsa öylece durur ve hiçbir test bunu görmez.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ⚠️ FİYAT TURU `recently_sold` KULLANMAZ — aday BAŞKA yoldan gelir
 * ─────────────────────────────────────────────────────────────────────
 * Satış fiyatı DEĞİŞTİRMEZ; o sorgu `inventory_movements` üzerinde
 * çalışır ve fiyat turunda HİÇ koşmaz (§9). Stok testindeki "bir fazla
 * IMPORT edip bir SALE yaz" hilesi burada aday ÜRETMEZ — kanala hiç
 * istek gitmez ve test "taranıyor" derken hiçbir şey sınamamış olurdu.
 *
 * Fiyat adayı `stale_sync` yolundan gelir: `listing_sync_states` satırı
 * `domain = 'price'`, `is_dirty` (ÜRETİLMİŞ kolon: `desired_version >
 * synced_version`) ve `last_requested_at` eşiğin gerisinde olmalıdır.
 */
final class EtsyPriceReconciliationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ⚠️ ETSY BAĞLANTISI FİYAT TARAMASINDA SEÇİLİR ve FARK BULUNUR.
     *
     * Bizim kanonik fiyatımız 19.90, kanal 24.50 diyor. Bağlantı
     * seçilmeseydi tur SIFIR bağlantı işler ve hiçbir kalem doğmazdı —
     * `fetchPrices` yazılmış ama çağıran yok demektir.
     */
    #[Test]
    public function an_etsy_connection_is_swept_and_its_price_drift_is_recorded(): void
    {
        [$tenant, $variant, $connection] = $this->etsyContext(price: '19.90');

        $listing = $this->listingWithStalePriceState($tenant, $variant, $connection);

        // Kanal 24.50 diyor — bizdeki 19.90'dan FARKLI.
        $this->fakeInventory(amount: 2450);

        $processed = $this->asSystem(fn (): int => app(ReconcileActiveConnections::class)->sweep(
            scope: ReconciliationScope::HOT,
            budget: 50,
            domain: SyncDomain::PRICE,
        ));

        $this->assertSame(
            1,
            $processed,
            'Etsy bağlantısı fiyat taramasında HİÇ seçilmedi — `fetchPrices` '
            .'yazılmış ama çağıran yok demektir.',
        );

        $item = $this->asTenant($tenant, fn (): ?ReconciliationItem => ReconciliationItem::query()
            ->where('listing_id', $listing->id)
            ->orderByDesc('id')
            ->first());

        $this->assertNotNull($item, 'Fiyat mutabakat kalemi yazılmadı.');

        // ⚠️ DURUM `PRICE_CONFLICT` — `DRIFT_DETECTED` DEĞİL ve ONARIM
        // AÇILMAZ (§9). Stokta aynı fark sessizce onarılır; fiyatta
        // satıcının kampanyasını ezmek EN SIK ŞİKAYETTİR.
        $this->assertSame('price_conflict', mb_strtolower((string) $item->status));

        // ⚠️ SAYILAR `local_value` / `remote_value` JSONB'SİNDEDİR.
        // Olmayan kolon okunsaydı Eloquent null döner ve iddia sessizce
        // geçerdi — hiçbir şey ölçmeyen bir test.
        //
        // Asıl kanıt bu ikisidir: ikisi de doluysa `fetchPrices` gerçekten
        // çağrılmış ve Etsy'nin NESNE biçimindeki fiyatı gerçekten
        // ayrıştırılmıştır.
        $this->assertSame('19.90', (string) ($item->local_value['price'] ?? null));
        $this->assertSame('24.50', (string) ($item->remote_value['price'] ?? null));
    }

    /**
     * ⚠️ KANAL GERÇEKTEN OKUNUR — istek Etsy'nin ENVANTER uç noktasına
     * GİDER.
     *
     * `fetchPrices` sessizce boş dönseydi tur "fark yok" der ve her şey
     * sağlıklı görünürdü. İsteğin atıldığını doğrulamak, o gövdenin
     * gerçekten AKIŞTAN çağrıldığının tek kanıtıdır.
     *
     * ⚠️ ADRESİN `inventory` OLMASI BAŞLI BAŞINA BİR İDDİADIR: Etsy'de
     * fiyat ayrı bir uç noktada DEĞİL, envanter gövdesinin içindedir
     * (§11.3). İlan uç noktasına gitseydi çok varyantlı üründe yalnızca
     * EN DÜŞÜK fiyat okunur ve pahalı varyantlar her tur SAHTE çakışma
     * raporlardı.
     */
    #[Test]
    public function the_price_sweep_actually_reads_the_inventory_endpoint(): void
    {
        [$tenant, $variant, $connection] = $this->etsyContext(price: '19.90');

        $this->listingWithStalePriceState($tenant, $variant, $connection);

        $this->fakeInventory(amount: 1990);

        $this->asSystem(fn (): int => app(ReconcileActiveConnections::class)->sweep(
            scope: ReconciliationScope::HOT,
            budget: 50,
            domain: SyncDomain::PRICE,
        ));

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/inventory'));
    }

    /**
     * ⚠️ SAĞLIKSIZ BAĞLANTI TARANMAZ.
     *
     * Taransaydı her tur kimliksiz istek atar, 401 alır ve devre
     * kesiciyi boşuna açardı.
     */
    #[Test]
    public function an_inactive_connection_is_not_swept(): void
    {
        [$tenant, $variant, $connection] = $this->etsyContext(price: '19.90');

        $this->listingWithStalePriceState($tenant, $variant, $connection);

        $this->asTenant($tenant, fn () => $connection->forceFill(['status' => 'inactive'])->save());

        Http::fake();

        $processed = $this->asSystem(fn (): int => app(ReconcileActiveConnections::class)->sweep(
            scope: ReconciliationScope::HOT,
            budget: 50,
            domain: SyncDomain::PRICE,
        ));

        $this->assertSame(0, $processed);
        Http::assertNothingSent();
    }

    // ────────────────────────────────────────────────────────── yardımcılar

    private function fakeInventory(int $amount): void
    {
        Http::fake(['*' => Http::response(['products' => [[
            'product_id' => 5001,
            'sku' => 'TSH-M',
            'offerings' => [[
                'offering_id' => 7001,
                'quantity' => 5,
                'is_enabled' => true,
                // ⚠️ OKUMA NESNE VERİR. Ham `amount` okunsaydı 24.50 TL
                // bizde 2450 TL görünür ve HER tur sahte çakışma doğardı.
                'price' => ['amount' => $amount, 'divisor' => 100, 'currency_code' => 'TRY'],
            ]],
        ]]], 200)]);
    }

    /** @return array{0: Tenant, 1: Variant, 2: ChannelConnection} */
    private function etsyContext(string $price): array
    {
        $this->asSystem(fn (): ChannelType => ChannelType::query()->updateOrCreate(
            ['code' => 'etsy'],
            [
                'name' => 'Etsy',
                'kind' => 'marketplace',
                // GERÇEK ADAPTER — sahte bir adapter bu testin sorusunu
                // (Etsy'nin gövdeleri akıştan çağrılıyor mu) cevaplayamaz.
                'adapter_class' => EtsyAdapter::class,
                'supports_webhooks' => false,
                'is_active' => false,
            ],
        ));

        $tenant = (new CreateTenant)->run(
            name: 'Etsy Fiyat Mutabakat '.uniqid(),
            owner: User::factory()->create(),
        );

        $variant = $this->asTenant(
            $tenant,
            fn (): Variant => Variant::factory()->create(['sku' => 'TSH-M', 'price' => $price])
        );

        $connection = $this->asTenant($tenant, function (): ChannelConnection {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'etsy',
                'external_account_id' => 'etsy-'.uniqid(),
                'status' => 'active',
                'settings' => [
                    EtsyAdapter::KEYSTRING_KEY => 'key-abc',
                    EtsyAdapter::SHOP_ID_KEY => '777',
                ],
            ]);

            app(CredentialVault::class)->store($connection, [
                'access_token' => '12345.token',
                'refresh_token' => '12345.refresh',
            ]);

            return $connection;
        });

        return [$tenant, $variant, $connection];
    }

    /**
     * Listing + FİYAT adayı yapan sync state satırı.
     *
     * ⚠️ `is_dirty` ÜRETİLMİŞ KOLONDUR (`desired_version >
     * synced_version`) ve DOĞRUDAN YAZILAMAZ; iki sürüm alanı ayrıştırılır.
     *
     * ⚠️ `last_requested_at` GEÇMİŞE atılır: sıcak katmanın bekleme
     * eşiği bir saattir ve taze bir satır aday OLMAZ.
     */
    private function listingWithStalePriceState(
        Tenant $tenant,
        Variant $variant,
        ChannelConnection $connection,
    ): Listing {
        return $this->asTenant($tenant, function () use ($variant, $connection): Listing {
            $listing = Listing::factory()->create([
                'channel_connection_id' => $connection->id,
                'variant_id' => $variant->id,
                'external_id' => '5001',
                'external_parent_id' => '9001',
                'lifecycle_status' => 'live',
            ]);

            ListingSyncState::query()->updateOrCreate(
                ['listing_id' => $listing->id, 'domain' => SyncDomain::PRICE->value],
                [
                    'status' => 'pending',
                    'desired_version' => 2,
                    'synced_version' => 1,
                    'last_requested_at' => now()->subDay(),
                ],
            );

            return $listing;
        });
    }
}
