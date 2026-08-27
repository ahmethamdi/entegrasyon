<?php

declare(strict_types=1);

namespace Tests\Feature\Channels;

use App\Domain\Channels\Adapters\Etsy\EtsyAdapter;
use App\Domain\Channels\Adapters\WooCommerce\WooCommerceAdapter;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Support\Observability\CaptureMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `/channels` · token durumu rozeti — V3.0 · §25 · panel maddesi.
 *
 * §25'in istediği üç hâl:
 *   🟢 Geçerli   🟡 14 gün içinde dolacak   🔴 Yeniden yetkilendirme gerekli
 *
 * ─────────────────────────────────────────────────────────────────────
 * NEDEN ROZET GEREKLİ — METRİK TEK BAŞINA YETMEZ
 * ─────────────────────────────────────────────────────────────────────
 * Metrik ve uyarı e-postası ARIZAYI haber verir; rozet DURUMU gösterir.
 * Satıcı e-postayı kaçırmış olabilir (spam, tatil, adres değişikliği) ve
 * o zaman elinde kalan tek şey bu ekrandır. Bağlantı kartı zaten "aktif"
 * diyor — token ölmek üzereyken bile, çünkü `status` kanalın SON
 * cevabını taşır, token'ın ÖMRÜNÜ değil. İkisi ayrı sorulardır.
 */
final class TokenBadgeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ⚠️ SÜRESİ DOLMUŞ TOKEN "YENİDEN YETKİLENDİR" DER — kırmızı.
     *
     * Bu, satıcının BİR ŞEY YAPMASI gereken tek hâldir ve en üstte
     * durmalıdır: token öldüyse bağlantı çalışmıyordur ve kendiliğinden
     * düzelmez (yenileme de aynı ölü token'la denenir).
     */
    #[Test]
    public function an_expired_token_asks_for_reauthorization(): void
    {
        [$user] = $this->connectionWith('etsy', expiresAt: now()->subDay());

        $this->assertSame('expired', $this->badge($user));
    }

    /**
     * ⚠️ 14 GÜN İÇİNDE DOLACAK TOKEN SARI — pencere §25'ten gelir ve
     * metrikle AYNI sabittir.
     *
     * Ayrışsalardı panel sarı yanarken metrik susar (ya da tersi) olur
     * ve satıcı iki farklı gerçek görürdü.
     */
    #[Test]
    public function a_token_expiring_within_the_window_is_a_warning(): void
    {
        [$user] = $this->connectionWith('etsy', expiresAt: now()->addDays(10));

        $this->assertSame('expiring', $this->badge($user));
    }

    /**
     * ⚠️ PENCERE SABİTİ `CaptureMetrics`'TEN OKUNUR, ROZETTE YENİDEN
     * YAZILMAZ. Sınırın hemen dışı sarı DEĞİLDİR.
     */
    #[Test]
    public function a_token_beyond_the_window_is_valid(): void
    {
        [$user] = $this->connectionWith(
            'etsy',
            expiresAt: now()->addDays(CaptureMetrics::TOKEN_EXPIRY_WINDOW_DAYS + 5),
        );

        $this->assertSame('valid', $this->badge($user));
    }

    /**
     * ⚠️ SÜRESİZ TOKEN ROZET TAŞIMAZ — "geçerli" bile DEMEZ.
     *
     * Woo/Trendyol kalıcı anahtar taşır ve Shopify'ın offline token'ı
     * SÜRESİZDİR. "🟢 Geçerli" yazılsaydı satıcı orada izlenecek bir
     * ömür olduğunu sanır ve rozetin kaybolmasını beklerdi; oysa o
     * kanalda böyle bir kavram YOKTUR. Yokluk, yanlış bir güvenceden
     * iyidir.
     */
    #[Test]
    public function a_channel_without_expiry_shows_no_badge(): void
    {
        [$user] = $this->connectionWith('woocommerce', expiresAt: null);

        $this->assertNull($this->badge($user));
    }

    /**
     * ⚠️ KİMLİK BİLGİSİ HİÇ YOKSA DA ROZET YOKTUR.
     *
     * OAuth turunu henüz tamamlamamış bir Etsy bağlantısı bu hâldedir
     * (satır açıldı, token gelmedi). "Süresi dolmuş" gösterilseydi
     * satıcı hiç kurmadığı bir şeyi YENİDEN yetkilendirmeye
     * çağrılırdı — oysa yapması gereken ilk kez yetkilendirmektir ve
     * bağlantı `pending` durumu bunu zaten söylüyor.
     */
    #[Test]
    public function a_connection_without_a_credential_shows_no_badge(): void
    {
        [$user] = $this->connectionWith('etsy', expiresAt: null, withCredential: false);

        $this->assertNull($this->badge($user));
    }

    /**
     * ⚠️ İPTAL EDİLMİŞ KİMLİK BİLGİSİ ROZET ÜRETMEZ.
     *
     * `revoked_at` dolu satır artık kullanılmıyor (Shopify'ın
     * `app/uninstalled` yolu bunu yazar). Okunsaydı kaldırılmış bir
     * uygulamanın ölü token'ı sonsuza kadar kırmızı yanardı — oysa
     * bağlantının kendisi zaten `inactive` ve sebebi orada yazılı.
     */
    #[Test]
    public function a_revoked_credential_shows_no_badge(): void
    {
        [$user] = $this->connectionWith(
            'etsy',
            expiresAt: now()->addDays(3),
            revokedAt: now(),
        );

        $this->assertNull($this->badge($user));
    }

    /**
     * ⚠️ ROZET SIR TAŞIMAZ — yalnızca DURUM ve TARİH.
     *
     * `channel_credentials` şifreli kasadır ve panele Inertia prop'u
     * olarak gider. Şifreli gövde ya da anahtar adları sızsaydı kasanın
     * tüm anlamı kaybolurdu (§19 · madde 3).
     */
    #[Test]
    public function the_badge_never_leaks_the_credential(): void
    {
        [$user] = $this->connectionWith('etsy', expiresAt: now()->addDays(5));

        $props = $this->connectionProps($user);
        $json = json_encode($props, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('encrypted_payload', $json);
        $this->assertStringNotContainsString('sahte-sifreli-govde', $json);
        $this->assertArrayNotHasKey('credentials', $props);
    }

    // ──────────────────────────────────────────────────────── yardımcılar

    /** Ekrandaki token rozetinin durumu; rozet yoksa `null`. */
    private function badge(User $user): ?string
    {
        return $this->connectionProps($user)['tokenStatus'] ?? null;
    }

    /** @return array<string, mixed> */
    private function connectionProps(User $user): array
    {
        $response = $this->actingAs($user)->get('/channels');

        $connections = $response->viewData('page')['props']['connections'];

        return (array) ($connections[0] ?? []);
    }

    /** @return array{0: User, 1: string} */
    private function connectionWith(
        string $code,
        ?\DateTimeInterface $expiresAt,
        ?\DateTimeInterface $revokedAt = null,
        bool $withCredential = true,
    ): array {
        $this->asSystem(fn () => ChannelType::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => ucfirst($code),
                'kind' => $code === 'woocommerce' ? 'storefront' : 'marketplace',
                'adapter_class' => $code === 'etsy'
                    ? EtsyAdapter::class
                    : WooCommerceAdapter::class,
                'supports_webhooks' => $code !== 'etsy',
                'is_active' => true,
            ],
        ));

        $user = User::factory()->create();
        $tenant = (new CreateTenant)->run(name: 'Rozet '.uniqid(), owner: $user);

        $connectionId = $this->asTenant($tenant, function () use (
            $code, $expiresAt, $revokedAt, $withCredential, $tenant
        ): string {
            $connection = ChannelConnection::factory()->create([
                'channel_type_code' => $code,
                'external_account_id' => $code.'-'.uniqid(),
                'status' => 'active',
            ]);

            if ($withCredential) {
                DB::table('channel_credentials')->insert([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $tenant->id,
                    'channel_connection_id' => $connection->id,
                    'encrypted_payload' => 'sahte-sifreli-govde',
                    'key_version' => 1,
                    'expires_at' => $expiresAt,
                    'revoked_at' => $revokedAt,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $connection->id;
        });

        return [$user, $connectionId];
    }

    private function tenantFor(User $user): Tenant
    {
        return $user->tenants()->firstOrFail();
    }
}
