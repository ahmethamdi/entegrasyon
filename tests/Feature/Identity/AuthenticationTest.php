<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Warehouse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kayıt, giriş ve kiracı bağlamı ara katmanı.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · faz 1.1, §11 · Güvenlik.
 *
 * DEĞİŞMEZ KURAL — KULLANICI KİRACISIZDIR:
 *   Bir kullanıcı `tenant_users` üzerinden birden fazla kiracıya bağlanabilir.
 *   `users` tablosunda tenant_id YOKTUR; kiracı bağlamı oturumdan gelir ve
 *   her istekte ara katmanla kurulur.
 *
 * DEĞİŞMEZ KURAL — BAĞLAM ÜYELİKTEN DOĞRULANIR:
 *   Oturumdaki kiracı kimliği kullanıcının üye OLMADIĞI bir kiracıyı
 *   gösteriyorsa bağlam kurulmaz. Aksi halde oturum çerezini kurcalayan
 *   biri başka kiracının verisine erişirdi — kiracı izolasyonunun HTTP
 *   katmanındaki karşılığı budur.
 *
 * DEĞİŞMEZ KURAL — İSTEK SONUNDA BAĞLAM TEMİZLENİR:
 *   `TenantContext` statiktir. Octane veya uzun ömürlü süreçte bağlam
 *   sonraki isteğe sızardı; kuyruk worker'larında aynı sorunu
 *   `QueueServiceProvider` kancaları çözüyor.
 */
final class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────────────────────────── kayıt

    /** Kayıt kullanıcıyı, kiracıyı ve varsayılan depoyu birlikte yaratır. */
    #[Test]
    public function registration_creates_user_tenant_and_default_warehouse(): void
    {
        $response = $this->post('/register', [
            'name' => 'Ahmet Yılmaz',
            'email' => 'ahmet@example.com',
            'password' => 'cok-gizli-parola',
            'password_confirmation' => 'cok-gizli-parola',
            'company' => 'Yılmaz Ticaret',
        ]);

        $response->assertRedirect('/');

        $user = User::query()->where('email', 'ahmet@example.com')->firstOrFail();

        $this->assertAuthenticatedAs($user);

        // Kiracı yaratıldı ve kullanıcı sahibi oldu.
        $tenant = $this->asSystem(fn () => Tenant::query()->firstOrFail());

        $this->assertSame('Yılmaz Ticaret', $tenant->name);
        $this->assertTrue($user->tenants()->where('tenants.id', $tenant->id)->exists());

        // CreateTenant varsayılan depoyu garanti eder — "en az bir varsayılan"
        // DB kısıtıyla zorlanmaz, bu action ile sağlanır.
        $this->assertSame(
            1,
            $this->asTenant($tenant, fn () => Warehouse::query()->where('is_default', true)->count()),
        );

        // Parola HASH'lenmiş saklanır.
        $this->assertNotSame('cok-gizli-parola', $user->password);
        $this->assertTrue(Hash::check('cok-gizli-parola', $user->password));
    }

    /** Aynı e-posta ikinci kez kayıt olamaz. */
    #[Test]
    public function registration_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'ahmet@example.com']);

        $response = $this->post('/register', [
            'name' => 'Başka Ahmet',
            'email' => 'ahmet@example.com',
            'password' => 'cok-gizli-parola',
            'password_confirmation' => 'cok-gizli-parola',
            'company' => 'Başka Şirket',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();

        // Yarım kiracı BIRAKILMAZ: doğrulama yaratmadan önce çalışır.
        $this->assertSame(0, $this->asSystem(fn () => Tenant::query()->count()));
    }

    /** Parola onayı tutmazsa kayıt olmaz. */
    #[Test]
    public function registration_requires_password_confirmation(): void
    {
        $response = $this->post('/register', [
            'name' => 'Ahmet',
            'email' => 'ahmet@example.com',
            'password' => 'cok-gizli-parola',
            'password_confirmation' => 'baska-parola',
            'company' => 'Şirket',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertGuest();
        $this->assertSame(0, User::query()->count());
    }

    // ─────────────────────────────────────────────────────────── giriş

    /** Doğru bilgilerle giriş oturumu açar. */
    #[Test]
    public function login_succeeds_with_correct_credentials(): void
    {
        [$user] = $this->makeUserWithTenant();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'gizli-parola-123',
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    /** Yanlış parola girişi reddeder ve oturum açmaz. */
    #[Test]
    public function login_fails_with_wrong_password(): void
    {
        [$user] = $this->makeUserWithTenant();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'yanlis-parola',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    /**
     * Giriş oturum kimliğini YENİLER — oturum sabitleme saldırısına karşı.
     *
     * Saldırgan kurbanın tarayıcısına bilinen bir oturum kimliği yerleştirip
     * kurban giriş yaptıktan sonra aynı kimlikle oturuma girebilirdi.
     */
    #[Test]
    public function login_regenerates_the_session_id(): void
    {
        [$user] = $this->makeUserWithTenant();

        // SALDIRIYI DOĞRUDAN KUR: saldırganın bildiği bir oturum kimliği
        // kurbanın tarayıcısına yerleştirilmiş olsun. Giriş sonrası aynı
        // kimlik hâlâ geçerliyse saldırgan o oturuma girebilir.
        //
        // MUTASYON NOTU — controller'daki `regenerate()` ÇAĞRISI
        // KALDIRILINCA BU TEST YEŞİL KALIR, ve kalmalıdır:
        //   Laravel'in SessionGuard::login() metodu zaten kendi içinde
        //   `session->regenerate(true)` çağırıyor (SessionGuard.php:588).
        //   Controller'daki çağrı İKİNCİ ve gereksizdir; kaldırılması
        //   davranışı değiştirmez. Kuralı koruyan şey bizim satırımız değil,
        //   framework'ün kendisi — bu test o garantiyi DOĞRULAR ve
        //   ileride bir sürüm yükseltmesi onu kaldırırsa kırmızıya döner.
        $this->startSession();

        $fixatedId = $this->app['session']->getId();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'gizli-parola-123',
        ])->assertRedirect('/');

        $this->assertNotSame(
            $fixatedId,
            $this->app['session']->getId(),
            'Giriş oturum kimliğini YENİLEMELİ — oturum sabitleme saldırısına açık.',
        );
    }

    /** Çıkış oturumu kapatır ve kiracı bağlamını bırakır. */
    #[Test]
    public function logout_ends_the_session(): void
    {
        [$user, $tenant] = $this->makeUserWithTenant();

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id]);

        $this->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
        $this->assertNull(session('tenant_id'));
    }

    // ─────────────────────────────────────────── kiracı bağlamı ara katmanı

    /** Giriş yapan kullanıcı için bağlam otomatik kurulur. */
    #[Test]
    public function tenant_context_is_established_for_authenticated_requests(): void
    {
        [$user, $tenant] = $this->makeUserWithTenant();

        $this->actingAs($user)->get('/')->assertOk();

        // Rota içinde bağlamın kurulu olduğunu doğrula.
        $seen = null;

        Route::middleware(['web', 'auth', 'tenant'])
            ->get('/_test/context', function () use (&$seen) {
                $seen = TenantContext::id();

                return response()->noContent();
            });

        $this->actingAs($user)->get('/_test/context')->assertNoContent();

        $this->assertSame($tenant->id, $seen, 'Ara katman bağlamı kurmalı.');
    }

    /**
     * Oturumdaki kiracı ÜYELİKTEN doğrulanır.
     *
     * Çerezi kurcalayan biri başka kiracının kimliğini yazarsa bağlam
     * kurulmaz — kiracı izolasyonunun HTTP katmanındaki karşılığı.
     */
    #[Test]
    public function forged_tenant_id_in_session_is_rejected(): void
    {
        [$user] = $this->makeUserWithTenant();
        [, $foreignTenant] = $this->makeUserWithTenant();

        $seen = 'DOKUNULMADI';

        Route::middleware(['web', 'auth', 'tenant'])
            ->get('/_test/forged', function () use (&$seen) {
                $seen = TenantContext::id();

                return response()->noContent();
            });

        // Kullanıcı bu kiracının ÜYESİ DEĞİL.
        $this->actingAs($user)
            ->withSession(['tenant_id' => $foreignTenant->id])
            ->get('/_test/forged');

        $this->assertNotSame(
            $foreignTenant->id,
            $seen,
            'Üye olunmayan kiracı bağlam olarak KURULMAMALI.',
        );
    }

    /** Kiracısı olmayan kullanıcı panele giremez. */
    #[Test]
    public function user_without_tenant_cannot_reach_the_panel(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')->assertRedirect('/login');
    }

    /** Giriş yapmamış ziyaretçi panele giremez. */
    #[Test]
    public function guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    /**
     * İstek bitince bağlam TEMİZLENİR.
     *
     * TenantContext statiktir; Octane veya uzun ömürlü süreçte bağlam
     * sonraki isteğe sızardı.
     */
    #[Test]
    public function tenant_context_is_cleared_after_the_request(): void
    {
        [$user] = $this->makeUserWithTenant();

        $this->actingAs($user)->get('/')->assertOk();

        $this->assertNull(
            TenantContext::id(),
            'İstek sonunda bağlam bırakılmalı — sonraki isteğe sızmamalı.',
        );
    }

    /**
     * Kullanıcı BAŞKA kiracının verisini panelde göremez.
     *
     * Bu, tenant scope'unun HTTP katmanında da geçerli olduğunun kanıtı.
     */
    #[Test]
    public function panel_never_shows_another_tenants_data(): void
    {
        [$userA, $tenantA] = $this->makeUserWithTenant();
        [, $tenantB] = $this->makeUserWithTenant();

        $seen = [];

        Route::middleware(['web', 'auth', 'tenant'])
            ->get('/_test/warehouses', function () use (&$seen) {
                $seen = Warehouse::query()->pluck('tenant_id')->unique()->all();

                return response()->noContent();
            });

        $this->actingAs($userA)->get('/_test/warehouses');

        $this->assertSame([$tenantA->id], $seen);
        $this->assertNotContains($tenantB->id, $seen);
    }

    // ---------------------------------------------------------------- yardımcılar

    /** @return array{0: User, 1: Tenant} */
    private function makeUserWithTenant(): array
    {
        $user = User::factory()->create([
            'password' => Hash::make('gizli-parola-123'),
        ]);

        $tenant = (new CreateTenant)->run(
            name: 'Şirket '.uniqid(),
            owner: $user,
        );

        return [$user, $tenant];
    }
}
