<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Adapters\Shopify\ShopifyAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Support\CredentialVault;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\ApplyMovement;
use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Reconciliation\Enums\ReconciliationScope;
use App\Domain\Reconciliation\Models\ReconciliationItem;
use App\Domain\Reconciliation\Support\ReconcileActiveConnections;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Shopify mutabakatı — slice 1.9'un İKİNCİ parçası.
 *
 * V3.0 · §06 · §22 ("yeni kanal için yazılan TEK şey `fetchInventory` /
 * `fetchPrices` gövdeleridir") · v2.2 §10 · §7.
 *
 * ─────────────────────────────────────────────────────────────────────
 * BU SLICE'TA YENİ KOD YOK — SORU "SEÇİLİYOR MU"
 * ─────────────────────────────────────────────────────────────────────
 * `fetchInventory` slice 1.5'te, `fetchPrices` slice 1.6'da yazıldı.
 * Mutabakat akışı KANAL BİLMEZ: `ReconcileActiveConnections` yalnızca
 * `status = 'active'` süzer ve yeteneği `instanceof` ile okur — hiçbir
 * yerde `if ($channel === '...')` yoktur.
 *
 * O yüzden burada sınanan şey yeni bir davranış değil, ENTEGRASYONUN
 * GERÇEKTEN KURULDUĞUDUR: Shopify bağlantısı taramada seçiliyor mu,
 * adapter'ın gövdeleri gerçek akıştan çağrılıyor mu ve sürüklenme kalemi
 * yazılıyor mu. Bu doğrulanmasaydı "yazıldı" denen iki gövde hiç
 * çağrılmadan öylece durabilirdi — kanalın stoğu sonsuza kadar
 * kontrolsüz kalırdı ve hiçbir test bunu göstermezdi.
 */
final class ShopifyReconciliationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ⚠️ SHOPIFY BAĞLANTISI TARAMADA SEÇİLİR ve SÜRÜKLENME BULUNUR.
     *
     * Kanonik bakiye 17, kanal 99 diyor: fark gerçektir ve kalem
     * `drift_detected` yazılmalıdır. Seçilmeseydi tur sıfır bağlantı işler
     * ve hiçbir kalem doğmazdı.
     */
    #[Test]
    public function a_shopify_connection_is_swept_and_its_drift_is_recorded(): void
    {
        [$tenant, $variant, $connection] = $this->shopifyContext();

        $listing = $this->listing($tenant, $variant, $connection, 'gid://shopify/ProductVariant/1');

        $this->seedStock($tenant, $variant, 17);

        // Kanal 99 diyor — bizdeki 17'den FARKLI.
        Http::fake(['*' => Http::response(['data' => ['nodes' => [[
            'id' => 'gid://shopify/ProductVariant/1',
            'inventoryQuantity' => 99,
            'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/1'],
        ]]]], 200)]);

        $processed = $this->asSystem(fn (): int => app(ReconcileActiveConnections::class)->sweep(
            scope: ReconciliationScope::HOT,
            budget: 50,
            domain: SyncDomain::INVENTORY,
        ));

        $this->assertSame(
            1,
            $processed,
            'Shopify bağlantısı taramada HİÇ seçilmedi — `fetchInventory` '
            .'yazılmış ama çağıran yok demektir.',
        );

        $item = $this->asTenant($tenant, fn (): ?ReconciliationItem => ReconciliationItem::query()
            ->where('listing_id', $listing->id)
            ->orderByDesc('id')
            ->first());

        $this->assertNotNull($item, 'Mutabakat kalemi yazılmadı.');

        // ⚠️ DURUM `REPAIR_QUEUED`, `DRIFT_DETECTED` DEĞİL — ve bu DOĞRUDUR.
        // §10'un beş adımı tek turda yürür: fark BULUNUR (detect/record),
        // sınıflandırılır ve onarım AÇILIR. Kalem son durumunu taşır.
        // `DRIFT_DETECTED` beklemek, akışın onarım adımına hiç gelmediğini
        // — yani `SupportsInventory` yolunun yarıda kaldığını — iddia
        // etmek olurdu.
        $this->assertSame('repair_queued', mb_strtolower((string) $item->status));

        // ⚠️ SAYILAR `local_value` / `remote_value` JSONB'SİNDEDİR.
        // `expected_quantity` / `remote_quantity` diye KOLON YOKTUR; öyle
        // okunsaydı Eloquent null döner, `(int)` onu 0'a çevirir ve iddia
        // "0 === 0" ile SESSİZCE geçerdi — hiçbir şey ölçmeyen bir test.
        //
        // Asıl kanıt bu iki sayıdır: beklenen bizim GİDEN değerimiz
        // (`max(available, 0)`), uzak değer kanalın söylediği. İkisi de
        // doluysa `fetchInventory` gerçekten çağrılmış ve yanıtı gerçekten
        // ayrıştırılmıştır.
        $this->assertSame(17, (int) ($item->local_value['expected_remote'] ?? null));
        $this->assertSame(99, (int) ($item->remote_value['quantity'] ?? null));
        $this->assertSame(82, (int) $item->drift_magnitude);
    }

    /**
     * ⚠️ KANAL GERÇEKTEN OKUNUR — istek Shopify'a GİDER.
     *
     * `fetchInventory` sessizce boş dönseydi tur "fark yok" der ve her
     * şey sağlıklı görünürdü. İsteğin atıldığını doğrulamak, o gövdenin
     * gerçekten AKIŞTAN çağrıldığının tek kanıtıdır.
     */
    #[Test]
    public function the_sweep_actually_reads_the_channel(): void
    {
        [$tenant, $variant, $connection] = $this->shopifyContext();

        $this->listing($tenant, $variant, $connection, 'gid://shopify/ProductVariant/1');
        $this->seedStock($tenant, $variant, 17);

        Http::fake(['*' => Http::response(['data' => ['nodes' => [[
            'id' => 'gid://shopify/ProductVariant/1',
            'inventoryQuantity' => 17,
            'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/1'],
        ]]]], 200)]);

        $this->asSystem(fn (): int => app(ReconcileActiveConnections::class)->sweep(
            scope: ReconciliationScope::HOT,
            budget: 50,
        ));

        Http::assertSent(fn ($request): bool => str_contains(
            (string) ($request->data()['query'] ?? ''),
            'nodes',
        ));
    }

    /**
     * ⚠️ KALDIRILMIŞ UYGULAMANIN BAĞLANTISI TARANMAZ.
     *
     * `app/uninstalled` bağlantıyı `inactive` yapar (§06.7) ve tarama
     * yalnızca `active` olanları alır. Taransaydı her tur kimliksiz istek
     * atar, 401 alır ve devre kesiciyi boşuna açardı — üstelik satıcı
     * uygulamayı kaldırdığı için yapacak hiçbir şey yoktur.
     */
    #[Test]
    public function an_uninstalled_connection_is_not_swept(): void
    {
        [$tenant, $variant, $connection] = $this->shopifyContext();

        $this->listing($tenant, $variant, $connection, 'gid://shopify/ProductVariant/1');
        $this->seedStock($tenant, $variant, 17);

        $this->asTenant($tenant, fn () => $connection->forceFill(['status' => 'inactive'])->save());

        Http::fake();

        $processed = $this->asSystem(fn (): int => app(ReconcileActiveConnections::class)->sweep(
            scope: ReconciliationScope::HOT,
            budget: 50,
        ));

        $this->assertSame(0, $processed);
        Http::assertNothingSent();
    }

    // ────────────────────────────────────────────────────────── yardımcılar

    /** @return array{0: Tenant, 1: Variant, 2: ChannelConnection} */
    private function shopifyContext(): array
    {
        $this->asSystem(fn (): ChannelType => ChannelType::query()->updateOrCreate(
            ['code' => 'shopify'],
            [
                'name' => 'Shopify',
                'kind' => 'storefront',
                // GERÇEK ADAPTER — sahte bir adapter bu testin sorusunu
                // (Shopify'ın gövdeleri akıştan çağrılıyor mu) cevaplayamaz.
                'adapter_class' => ShopifyAdapter::class,
                'supports_webhooks' => true,
                'is_active' => false,
            ],
        ));

        $tenant = (new CreateTenant)->run(
            name: 'Shopify Mutabakat '.uniqid(),
            owner: User::factory()->create(),
        );

        $variant = $this->asTenant($tenant, fn (): Variant => Variant::factory()->create());

        $connection = $this->asTenant($tenant, function (): ChannelConnection {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => 'shopify',
                'external_account_id' => 'magaza-'.uniqid().'.myshopify.com',
                'status' => 'active',
                'settings' => ['location_gid' => 'gid://shopify/Location/12'],
            ]);

            app(CredentialVault::class)->store($connection, ['access_token' => 'shpat_test']);

            return $connection;
        });

        return [$tenant, $variant, $connection];
    }

    private function listing(
        Tenant $tenant,
        Variant $variant,
        ChannelConnection $connection,
        string $externalId,
    ): Listing {
        return $this->asTenant($tenant, fn (): Listing => Listing::factory()->create([
            'channel_connection_id' => $connection->id,
            'variant_id' => $variant->id,
            'external_id' => $externalId,
            'lifecycle_status' => 'live',
        ]));
    }

    /**
     * Kanonik bakiyeyi `$quantity`'ye getirir ve satırı SICAK KATMAN ADAYI
     * yapar.
     *
     * ⚠️ AÇILIŞ IMPORT'U TEK BAŞINA ADAY ÜRETMEZ. Sıcak katmanın aday
     * sorgusu "son 30 dakikada SATILDI" der ve `inventory_movements`
     * üzerinde SALE arar (§10 · `ReconciliationScope`). Yalnızca IMPORT
     * yazılsaydı tur bağlantıyı seçer ama aday BULAMAZ, kanala hiç istek
     * atmaz ve test "Shopify taranıyor" derken aslında hiçbir şey
     * sınamamış olurdu.
     *
     * Bu yüzden bir fazla IMPORT edilip BİR adet satılır: bakiye istenen
     * değere oturur ve satır adaylık kapısını gerçek yoldan geçer.
     *
     * Açılış stoğu LEDGER üzerinden girer — `inventory_levels` satırına
     * doğrudan yazmak `on_hand = Σ on_hand_delta` eşitliğini bozar.
     */
    private function seedStock(Tenant $tenant, Variant $variant, int $quantity): void
    {
        $warehouseId = (string) $this->asTenant(
            $tenant,
            fn () => Warehouse::query()->where('is_default', true)->value('id')
        );

        $this->asTenant($tenant, fn () => app(ApplyMovement::class)->run(
            warehouseId: $warehouseId,
            variantId: $variant->id,
            type: MovementType::IMPORT,
            quantity: $quantity + 1,
            idempotencyKey: 'import:'.$variant->id,
            sourceType: 'test',
        ));

        $this->asTenant($tenant, fn () => app(ApplyMovement::class)->run(
            warehouseId: $warehouseId,
            variantId: $variant->id,
            type: MovementType::SALE,
            quantity: 1,
            idempotencyKey: 'recent-sale:'.$variant->id,
            sourceType: 'test',
        ));
    }
}
