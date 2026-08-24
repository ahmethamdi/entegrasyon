<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Taşıma güvenliği — §11 · "HTTPS zorunlu, HSTS açık".
 *
 * Mimari Karar Dokümanı v2.2 · §11 · "Minimum production kontrol listesi".
 *
 * ─────────────────────────────────────────────────────────────────────
 * NEDEN BU KADAR ÖNEMLİ — OTURUM ÇEREZİ VE KANAL ANAHTARI
 * ─────────────────────────────────────────────────────────────────────
 * Düz HTTP'de iki şey birden açığa çıkar: satıcının OTURUM ÇEREZİ (yani
 * hesabın tamamı) ve kanal bağlama formuna yazılan API ANAHTARI. Woo
 * anahtar çiftini Basic auth ile taşır ve `StoreUrl` bu yüzden zaten
 * `https` dayatır (§13 · faz 1.4) — ama o kural KANALA GİDEN yönü korur,
 * satıcının TARAYICISINDAN gelen yönü değil.
 *
 * ─────────────────────────────────────────────────────────────────────
 * ÜRETİMDE ZORUNLU, YERELDE DEĞİL
 * ─────────────────────────────────────────────────────────────────────
 * Yerel geliştirme `http://localhost:8080` üzerinden çalışır ve zorlama
 * koşulsuz olsaydı tüm panel yerelde kırılırdı. Kapı bu yüzden ortama
 * bağlıdır ve testler İKİ YÖNÜ DE sınar: üretimde açık, yerelde kapalı.
 * Yalnızca "üretimde açık" sınansaydı, koşulun kaldırılıp her yerde açık
 * bırakılması testi kırmazdı.
 */
final class TransportSecurityTest extends TestCase
{
    /**
     * ÜRETİMDE ÜRETİLEN HER URL `https`'TİR.
     *
     * `forceScheme` yalnızca görünüşte kozmetiktir: form `action`'ları,
     * yönlendirmeler ve e-posta bağlantıları buradan üretilir. Zorlama
     * olmasaydı ters vekil arkasında Laravel şemayı `http` sanabilir ve
     * kullanıcıyı düz HTTP'ye yönlendiren bir giriş formu üretirdi.
     */
    #[Test]
    public function urls_are_forced_to_https_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->refreshApplicationWithProductionEnvironment();

        $this->assertStringStartsWith(
            'https://',
            URL::to('/login'),
            'Üretimde üretilen URL http kaldı — oturum çerezi ve kanal '.
            'anahtarı düz metin gidebilir.',
        );
    }

    /**
     * YERELDE ZORLAMA YOKTUR.
     *
     * Bu test kapının KOŞULLU olduğunu korur. Olmasaydı zorlamayı
     * koşulsuz hale getiren bir değişiklik testleri yeşil bırakır ve
     * yerel geliştirme sessizce kırılırdı.
     */
    #[Test]
    public function local_development_is_not_forced_to_https(): void
    {
        // Test ortamı üretim DEĞİLDİR — kapının koşulu tam olarak budur.
        $this->assertFalse($this->app->isProduction());

        $this->assertStringStartsWith('http://', URL::to('/login'));
    }

    /**
     * OTURUM ÇEREZİ ÜRETİMDE `secure` VE `http_only` TAŞIR.
     *
     * `secure` çerezin düz HTTP'de HİÇ gönderilmemesini sağlar; `http_only`
     * JavaScript'in onu okumasını engeller (XSS ile oturum çalma).
     * `.env.example` bu iki değeri BELGELER — belgelenmemiş bir ayar yeni
     * kurulumda sessizce varsayılana düşer ve kimse fark etmez.
     */
    #[Test]
    public function the_session_cookie_is_hardened_in_the_env_example(): void
    {
        // Dosyanın KENDİSİ okunur, `config()` değil: `config()` ETKİN
        // değeri döndürür ve yerel `.env` doğru olduğu sürece varsayılanı
        // bozan bir mutasyon gizlenirdi (projede yaşanmış tuzak —
        // `TurkishMessagesTest` aynı biçimi kullanır).
        $example = (string) file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString(
            'SESSION_SECURE_COOKIE',
            $example,
            '.env.example SESSION_SECURE_COOKIE belgelemiyor — yeni kurulum '.
            'oturum çerezini düz HTTP üzerinde gönderir.',
        );

        $this->assertStringContainsString('SESSION_HTTP_ONLY', $example);
        $this->assertStringContainsString('SESSION_SAME_SITE', $example);
    }

    /**
     * DOĞRULAMA HATASI KANAL ANAHTARINI OTURUMA FLASH ETMEZ.
     *
     * Mimari Karar Dokümanı v2.2 · §11 · "Tüm kimlik bilgileri şifreli;
     * düz metin taraması temiz".
     *
     * Laravel doğrulama hatasında TÜM istek girdisini oturuma flash eder
     * ki form yeniden doldurulabilsin. Varsayılan `dontFlash` listesi
     * yalnızca `password` ailesini kapsar — `consumer_secret` DEĞİL.
     *
     * Bedeli: kullanıcı formu eksik doldurup gönderdiğinde kanal anahtarı
     * oturum deposuna (bu projede VERİTABANI, `SESSION_DRIVER=database`)
     * DÜZ METİN yazılır. Kasada şifrelenen değer, kasaya hiç ulaşmadan
     * şifresiz bir tabloya düşer ve oturum süresi boyunca orada durur.
     */
    #[Test]
    public function validation_errors_never_flash_channel_secrets(): void
    {
        $user = User::factory()->create();

        $tenant = (new CreateTenant)->run(
            name: 'Flash '.uniqid(),
            owner: $user,
        );

        // `label` EKSİK — doğrulama hatası tetiklenir ve girdi flash edilir.
        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->post('/channels', [
                'channel_type_code' => 'woocommerce',
                'store_url' => 'https://magaza.example.com',
                'consumer_key' => 'ck_test_key',
                'consumer_secret' => 'cs_flash_sizmamali',
            ]);

        $response->assertSessionHasErrors('label');

        $flashed = session()->get('_old_input', []);

        $this->assertArrayNotHasKey(
            'consumer_secret',
            $flashed,
            'Kanal anahtarı oturuma düz metin flash edildi — SESSION_DRIVER '.
            'database olduğu için değer şifresiz bir tabloda durur.',
        );

        $this->assertArrayNotHasKey('consumer_key', $flashed);

        // Sır olmayan alanlar KORUNUR: form yeniden doldurulabilmeli.
        $this->assertSame('https://magaza.example.com', $flashed['store_url'] ?? null);
    }

    /** Uygulamayı üretim ortamıyla yeniden kurar. */
    private function refreshApplicationWithProductionEnvironment(): void
    {
        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';
        $_SERVER['APP_ENV'] = 'production';

        $this->refreshApplication();

        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
        $_SERVER['APP_ENV'] = 'testing';
    }
}
