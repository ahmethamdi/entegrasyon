<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Türkçe hata mesajları — §13 · Faz 4.
 *
 * Doküman maddeyi "Türkçe yardım dokümantasyonu ve hata mesajları — 12 sa"
 * diye tanımlar. Bu dosya İKİNCİ yarıyı korur.
 *
 * KAPATILAN BOŞLUK: panelin tamamı Türkçeydi ama doğrulama hataları
 * İNGİLİZCE dönüyordu. Türkçe bir formu dolduran satıcı zorunlu bir alanı
 * boş bıraktığında "The title field is required." görüyordu — hem yabancı
 * dil hem de alan adı HAM VERİTABANI KOLONU (`title`), ekranda yazan
 * etiket ("Ürün adı") değil. Gerçek tarayıcıda ölçüldü.
 *
 * DEĞİŞMEZ KURAL — SÖZLEŞME TESTİ, BEKLENEN METİNLE SINANIR:
 *   Bu testler mesajları LİTERAL metinle karşılaştırır, `__()` çağırıp
 *   sonucu kendisiyle kıyaslamaz. Sebep bu projede DÖRT KEZ yaşandı
 *   (`MetricScope`, `AlertKey`, `plans.limits`): yazan da okuyan da aynı
 *   kaynağı çağırdığında mutasyon İKİSİNİ BİRLİKTE kaydırır ve davranış
 *   testleri yeşil kalır. Burada tuzak daha da sessizdir — dil dosyası
 *   silinse Laravel İSTİSNA FIRLATMAZ, anahtarın kendisini basar
 *   ("validation.required") ya da İngilizceye düşer.
 *
 * DEĞİŞMEZ KURAL — ALAN ADI EKRANDAKİ ETİKETLE AYNI OLMALI:
 *   Mesaj Türkçe ama alan adı `title` kalsaydı satıcı formda "title" diye
 *   bir alan ARAYAMAZDI. `attributes` çevirisi mesaj çevirisi kadar
 *   önemlidir ve ayrıca sınanır.
 */
final class TurkishMessagesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ETKİN DİL TÜRKÇEDİR.
     *
     * Bu test `.env` + `config` BİRLİKTE ne üretiyor onu sınar; yani "bu
     * kurulumda mesajlar Türkçe mi" sorusunu cevaplar.
     */
    #[Test]
    public function the_effective_locale_is_turkish(): void
    {
        $this->assertSame('tr', config('app.locale'), 'Etkin dil Türkçe olmalı.');
        $this->assertSame(
            'tr',
            config('app.fallback_locale'),
            'Yedek dil de Türkçe olmalı — çevrilmemiş anahtar İngilizceye DÜŞMEMELİ.',
        );
    }

    /**
     * `config/app.php` İÇİNDEKİ VARSAYILAN DA TÜRKÇEDİR — AYRI BİR TEST.
     *
     * MUTASYONLA BULUNDU: yukarıdaki test tek başına YETMEZ. `config()`
     * etkin değeri döndürür ve `.env` `APP_LOCALE=tr` taşıdığı için,
     * `config/app.php`'deki varsayılan `en`'e geri çevrilse bile o test
     * YEŞİL kalıyordu. Yani `.env` ikinci bir savunma olarak mutasyonu
     * GİZLİYORDU — bu projede "iki savunma mutasyonu gizler" tuzağının
     * yeni tekrarı.
     *
     * Varsayılanın önemi şudur: `.env`'inde o satır OLMAYAN bir kurulum
     * (yeni sunucu, CI, yeni geliştirici) sessizce İngilizce mesaj
     * gösterirdi. Bu yüzden dosyanın KENDİSİ okunur, `config()` değil.
     */
    #[Test]
    public function the_configured_default_locale_is_turkish_even_without_env(): void
    {
        $source = file_get_contents(config_path('app.php'));

        $this->assertStringContainsString(
            "env('APP_LOCALE', 'tr')",
            $source,
            'config/app.php içindeki VARSAYILAN dil Türkçe olmalı — `.env` satırı olmayan kurulum İngilizceye düşmemeli.',
        );
        $this->assertStringContainsString(
            "env('APP_FALLBACK_LOCALE', 'tr')",
            $source,
            'Yedek dilin varsayılanı da Türkçe olmalı.',
        );
    }

    /** Zorunlu alan mesajı Türkçedir ve alan adı ekrandaki etiketi taşır. */
    #[Test]
    public function required_field_errors_are_turkish_with_screen_labels(): void
    {
        [, $user] = $this->makeTenant();

        $this->actingAs($user)
            ->from('/products/create')
            ->post('/products', []);

        $errors = session('errors')->getBag('default');

        $this->assertSame(
            'Ürün adı alanı zorunludur.',
            $errors->first('title'),
            'Zorunlu alan mesajı Türkçe olmalı ve alan adı "Ürün adı" olmalı.',
        );
        $this->assertSame(
            'Fiyat alanı zorunludur.',
            $errors->first('price'),
            'Alan adı ham kolon adı (`price`) değil ekrandaki etiket olmalı.',
        );
    }

    /**
     * ALANA ÖZGÜ MESAJ "NE YAPMALI" DER.
     *
     * Genel mesaj ("SKU alanı zorunludur") ne yanlış olduğunu söyler ama
     * NEDEN gerektiğini söylemez. §12'nin ölü mektup ekranı kuralının
     * aynısı: sınıf ve TAVSİYE birlikte gösterilir.
     */
    #[Test]
    public function field_specific_messages_explain_what_to_do(): void
    {
        [, $user] = $this->makeTenant();

        $this->actingAs($user)
            ->from('/products/create')
            ->post('/products', []);

        $errors = session('errors')->getBag('default');

        $this->assertSame(
            'SKU zorunludur — kanallarla eşleşmenin anahtarı budur.',
            $errors->first('sku'),
            'SKU mesajı neden gerektiğini de anlatmalı.',
        );
    }

    /** Kanal bağlama formunun mesajları da Türkçedir. */
    #[Test]
    public function channel_form_errors_are_turkish(): void
    {
        [, $user] = $this->makeTenant();

        $this->actingAs($user)
            ->from('/channels/create')
            ->post('/channels', []);

        $errors = session('errors')->getBag('default');

        $this->assertSame(
            'Bağlantı adı alanı zorunludur.',
            $errors->first('label'),
            'Kanal formundaki alan adları da Türkçe olmalı.',
        );
        $this->assertSame(
            'Mağaza adresi zorunludur (örnek: magazam.com).',
            $errors->first('store_url'),
            'Mağaza adresi mesajı beklenen BİÇİMİ de göstermeli.',
        );
    }

    /**
     * GİRİŞ HATASI TÜRKÇEDİR ve KASITLI OLARAK BELİRSİZDİR.
     *
     * "E-posta bulunamadı" ile "parola yanlış" ayrı ayrı söylenseydi
     * saldırgan hangi adreslerin kayıtlı olduğunu tek tek öğrenebilirdi.
     */
    #[Test]
    public function the_login_error_is_turkish_and_does_not_reveal_whether_the_account_exists(): void
    {
        $this->from('/login')->post('/login', [
            'email' => 'yok@example.com',
            'password' => 'yanlisparola',
        ]);

        $message = session('errors')->getBag('default')->first('email');

        $this->assertSame(
            'Bu bilgilerle eşleşen bir hesap bulunamadı.',
            $message,
            'Giriş hatası Türkçe olmalı ve hesabın var olup olmadığını AÇIK ETMEMELİ.',
        );
    }

    /** @return array{0: Tenant, 1: User} */
    private function makeTenant(): array
    {
        $user = User::factory()->create();

        $tenant = (new CreateTenant)->run(name: 'Test Şirket', owner: $user);

        return [$tenant, $user];
    }
}
