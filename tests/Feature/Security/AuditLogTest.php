<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Actions\ConnectChannel;
use App\Domain\Channels\Adapters\WooCommerce\WooCommerceAdapter;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Actions\RecordAuditLog;
use App\Domain\Identity\Enums\AuditAction;
use App\Domain\Identity\Models\AuditLog;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\AdjustStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Denetim kaydı — §11'in "anlaşmazlıkta sorulan sorular".
 *
 * Mimari Karar Dokümanı v2.2 · §4 (şema) · §11 ("Denetim kaydı").
 *
 * §11 kapsamı DAR tutar: "Her satır değişikliğini kaydetmek gereksiz; bu
 * altı olay anlaşmazlık çıktığında sorulan sorular." Testler bu yüzden iki
 * şeyi birden korur — olayın YAZILDIĞINI ve yükün MASKELİ olduğunu.
 */
final class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────────────── kanal bağlama

    /** Yeni kanal bağlantısı denetim kaydı üretir. */
    #[Test]
    public function connecting_a_channel_is_audited(): void
    {
        $tenant = $this->makeTenant();

        Http::fake(['*' => Http::response(['environment' => []], 200)]);

        $this->asTenant($tenant, fn () => $this->connect());

        $log = $this->latestLog($tenant, AuditAction::CHANNEL_CONNECTED);

        $this->assertNotNull($log);
        $this->assertSame('channel_connections', $log->subject_type);
        $this->assertSame('magaza.example.com', $log->changes['external_account_id']);
    }

    /**
     * ANAHTAR YENİLEME AYRI BİR OLAYDIR.
     *
     * §11 "kanal bağlantısı ekleme" ile "kimlik bilgisi güncelleme"yi AYRI
     * maddeler olarak sayar. Tek olayda birleştirilselerdi "bu mağazaya kim,
     * ne zaman yeni anahtar verdi" sorusu cevapsız kalırdı — oysa anahtar
     * yenileme başlı başına bir güven olayıdır.
     */
    #[Test]
    public function refreshing_credentials_is_a_distinct_event(): void
    {
        $tenant = $this->makeTenant();

        Http::fake(['*' => Http::response(['environment' => []], 200)]);

        // Aynı mağaza iki kez bağlanır: ikincisi anahtar yenilemedir
        // (`ConnectChannel` yeni satır AÇMAZ, var olanı kullanır).
        $this->asTenant($tenant, fn () => $this->connect());
        $this->asTenant($tenant, fn () => $this->connect(consumerSecret: 'cs_yeni_anahtar'));

        $this->assertNotNull($this->latestLog($tenant, AuditAction::CHANNEL_CONNECTED));
        $this->assertNotNull($this->latestLog($tenant, AuditAction::CHANNEL_CREDENTIAL_UPDATED));
    }

    /**
     * SIR DENETİM KAYDINA DÜŞMEZ.
     *
     * Bu testin kırılması, kasa şifrelemesinin anlamsızlaştığı anlamına
     * gelir: sır şifresiz bir jsonb kolonunda düz metin durur ve denetim
     * ekranı onu panele taşır.
     */
    #[Test]
    public function secrets_never_land_in_the_audit_payload(): void
    {
        $tenant = $this->makeTenant();

        Http::fake(['*' => Http::response(['environment' => []], 200)]);

        $this->asTenant($tenant, fn () => $this->connect());

        // Kolon HAM okunur — model cast'i değil, diskteki değer sınanır.
        $raw = $this->asSystem(fn (): ?string => DB::table('audit_logs')
            ->where('tenant_id', $tenant->id)
            ->where('action', AuditAction::CHANNEL_CONNECTED->value)
            ->value('changes'));

        $this->assertNotNull($raw);
        $this->assertStringNotContainsString('cs_super_secret_value', $raw);

        // Ama anahtarların ADLARI kalmalı: "hangi anahtarlar verildi"
        // denetimin cevaplaması gereken sorulardan biridir.
        $this->assertStringContainsString('consumer_secret', $raw);
    }

    /**
     * YANLIŞLIKLA EKLENEN SIR ALANI MASKELENİR.
     *
     * Birinci savunma, çağıranın sırrı hiç göndermemesidir. Bu test
     * İKİNCİ savunmayı sınar: yük yine de bir sır alanı taşırsa
     * `RecordAuditLog` onu katman 1 ile maskeler.
     *
     * İki savunma birden olduğu için mutasyon gizlenebilir — bu yüzden
     * test action'ı DOĞRUDAN çağırır ve çağrı yerini devre dışı bırakır.
     */
    #[Test]
    public function the_payload_is_redacted_even_when_the_caller_passes_a_secret(): void
    {
        $tenant = $this->makeTenant();

        $this->asTenant($tenant, fn () => app(RecordAuditLog::class)->run(
            action: AuditAction::CHANNEL_CONNECTED,
            subjectType: 'channel_connections',
            subjectId: null,
            changes: [
                'api_secret' => 'sizmamali_bu_deger',
                'nested' => ['access_token' => 'bu_da_sizmamali'],
                'label' => 'Ana Mağaza',
            ],
        ));

        // Eylem TÜRÜYLE daraltılır: `makeTenant` kendi `tenant.created`
        // kaydını da yazar ve filtresiz sorgu onu seçerdi.
        $raw = $this->asSystem(fn (): ?string => DB::table('audit_logs')
            ->where('tenant_id', $tenant->id)
            ->where('action', AuditAction::CHANNEL_CONNECTED->value)
            ->value('changes'));

        $this->assertNotNull($raw);
        $this->assertStringNotContainsString('sizmamali_bu_deger', $raw);

        // İÇ İÇE yapı da maskelenmeli — katman 1 özyinelemelidir.
        $this->assertStringNotContainsString('bu_da_sizmamali', $raw);

        // Yapı korunur: sır olmayan alanlar silinmez.
        $this->assertStringContainsString('Ana Mağaza', $raw);
    }

    // ─────────────────────────────────────────────── stok düzeltme

    /**
     * ELLE STOK DÜZELTMESİ DENETLENİR — AKTÖRÜYLE BİRLİKTE.
     *
     * Ledger hareketi tek başına yetmez: `inventory_movements` NİCELİĞİ
     * taşır, denetim kaydı AKTÖRÜ taşır. "Bakiye neden değişti" sorusunun
     * ikinci yarısı budur.
     */
    #[Test]
    public function manual_stock_adjustment_is_audited_with_the_actor(): void
    {
        $owner = User::factory()->create();

        $tenant = (new CreateTenant)->run(name: 'Denetim '.uniqid(), owner: $owner);

        [$warehouseId, $variantId] = $this->asTenant($tenant, function () use ($tenant): array {
            $variant = Variant::factory()->create();

            return [$tenant->defaultWarehouse()->id, $variant->id];
        });

        $this->asTenant($tenant, fn () => app(AdjustStock::class)->run(
            warehouseId: $warehouseId,
            variantId: $variantId,
            quantity: 7,
            note: 'Sayım farkı',
            actorId: $owner->id,
        ));

        $log = $this->latestLog($tenant, AuditAction::STOCK_ADJUSTED);

        $this->assertNotNull($log);
        $this->assertSame('variants', $log->subject_type);
        $this->assertSame($variantId, $log->subject_id);
        $this->assertSame($owner->id, $log->user_id);
        $this->assertSame(7, $log->changes['quantity']);
        $this->assertSame('Sayım farkı', $log->changes['note']);
    }

    // ─────────────────────────────────────────────── kiracı izolasyonu

    /**
     * DENETİM KAYDI KİRACIYA AİTTİR.
     *
     * Başka kiracının denetim kaydını görmek en ağır izolasyon
     * ihlallerinden olurdu: kayıt tam olarak "kim ne yaptı" bilgisini
     * taşır.
     */
    #[Test]
    public function audit_logs_are_scoped_to_their_tenant(): void
    {
        $tenantA = $this->makeTenant('A');
        $tenantB = $this->makeTenant('B');

        Http::fake(['*' => Http::response(['environment' => []], 200)]);

        $this->asTenant($tenantB, fn () => $this->connect(host: 'b-magaza.example.com'));

        $seenByA = $this->asTenant($tenantA, fn (): int => AuditLog::query()
            ->where('action', AuditAction::CHANNEL_CONNECTED->value)
            ->count());

        $this->assertSame(0, $seenByA, 'B kiracısının denetim kaydı A\'ya göründü.');
    }

    /** Kiracı yaratma kendi denetim kaydını üretir — bağlam kurulmadan. */
    #[Test]
    public function tenant_creation_is_audited_without_an_established_context(): void
    {
        $owner = User::factory()->create();

        $tenant = (new CreateTenant)->run(name: 'Yeni Hesap', owner: $owner);

        $log = $this->latestLog($tenant, AuditAction::TENANT_CREATED);

        $this->assertNotNull($log, 'Kiracı yaratma kaydı yazılmadı — bağlam '.
            'henüz kurulmadığı için kiracı kimliği AÇIKÇA verilmeli.');

        $this->assertSame($owner->id, $log->user_id);
    }

    // ─────────────────────────────────────────────────── yardımcılar

    private function latestLog(Tenant $tenant, AuditAction $action): ?AuditLog
    {
        return $this->asSystem(fn (): ?AuditLog => AuditLog::query()
            ->where('tenant_id', $tenant->id)
            ->where('action', $action->value)
            ->latest('occurred_at')
            ->first());
    }

    private function connect(
        string $consumerSecret = 'cs_super_secret_value',
        string $host = 'magaza.example.com',
    ): object {
        return app(ConnectChannel::class)->run(
            channelTypeCode: 'woocommerce',
            label: 'Ana Mağaza',
            storeUrl: 'https://'.$host,
            secrets: [
                'consumer_key' => 'ck_test_key',
                'consumer_secret' => $consumerSecret,
            ],
        );
    }

    private function makeTenant(string $name = 'Denetim'): Tenant
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
