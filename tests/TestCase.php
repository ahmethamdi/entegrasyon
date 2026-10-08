<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Billing\Contracts\PaymentGateway;
use App\Domain\Channels\Support\OutboundUrlGuard;
use App\Domain\Identity\Models\Tenant;
use App\Support\Privacy\SealedJson;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Billing\FakePaymentGateway;

abstract class TestCase extends BaseTestCase
{
    protected FakePaymentGateway $payments;

    /**
     * RefreshDatabase SİLMEDEN önce: veritabanı adı `_test` ile bitmiyorsa DUR.
     *
     * 8 Eki 2026: `bootstrap/cache/config.php` APP_ENV=local ile önbellekte
     * kaldı, phpunit.xml'in DB_DATABASE'i hiç okunmadı ve takım YEREL
     * geliştirme veritabanını sıfırladı. Önbellek her zaman temizlenmeyebilir;
     * bu kontrol hangi sebeple olursa olsun yanlış veritabanının silinmesini
     * engeller.
     *
     * `setUpTraits()` içinde: RefreshDatabase'in kendi `beforeRefreshingDatabase()`
     * kancası test sınıfındaki trait tarafından EZİLİR (trait > miras).
     */
    protected function setUpTraits()
    {
        $database = (string) DB::connection()->getDatabaseName();

        if (isset(class_uses_recursive(static::class)[RefreshDatabase::class]) && ! str_ends_with($database, '_test')) {
            fwrite(STDERR, "\nTEST DURDURULDU: veritabanı '{$database}' — yalnız *_test veritabanı sıfırlanır. "
                ."bootstrap/cache/config.php'yi sil (php artisan config:clear) ve tekrar koş.\n");

            exit(1);
        }

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Her test temiz bağlamla başlar. TenantContext statik olduğu için
        // testler arası sızıntı da bir risktir.
        TenantContext::clear();

        // Symfony'nin test isteği varsayılan olarak `Accept-Language: en-us`
        // taşır; panel dili tarayıcıdan türediği için (SetLocale) her test
        // sessizce İngilizce panel görürdü. Varsayılan Türk satıcının
        // tarayıcısı; İngilizce senaryo testi başlığı kendisi verir.
        $this->withHeader('Accept-Language', 'tr-TR,tr;q=0.9');

        // ⚠️ TEST GERÇEK KANALA İSTEK ATAMAZ (A11 ④b'de bulundu).
        //
        // Sahte yanıt tanımı bir uç noktayı kapsamayınca istek SESSİZCE
        // gerçek `apigw.trendyol.com`'a gitmiş ve 401 almıştı. Hem dışarıya
        // anahtar sızdırma riski hem de ağa bağlı, kırılgan test demekti.
        // Eşleşmeyen istek artık testi düşürür.
        Http::preventStrayRequests();

        // Aynı gerekçe, ödeme tarafı: Stripe SDK'sı curl kullanır ve
        // yukarıdaki kural onu YAKALAMAZ. Test ödeme sağlayıcısına çıkmaz.
        $this->app->instance(PaymentGateway::class, $this->payments = new FakePaymentGateway);

        // SSRF koruması istek anında DNS çözer; test ağa ÇIKMAZ. Her ad
        // genel bir adrese çözülür — iç ağ senaryosunu sınayan test kendi
        // çözümleyicisini bağlar.
        $this->app->instance(OutboundUrlGuard::class, new OutboundUrlGuard(static fn (): array => ['93.184.216.34']));
    }

    protected function tearDown(): void
    {
        try {
            $this->assertOrderDataEncryptedAtRest();
        } finally {
            TenantContext::clear();

            parent::tearDown();
        }
    }

    /**
     * ⚠️ SİPARİŞ VERİSİ DİSKTE ŞİFRELİ DURUR — HER TESTİN SONUNDA ÖLÇÜLÜR.
     *
     * App Store formunda "encrypt at rest = evet" dendi (6 Eki 2026). Bu
     * kolonlara yazımların çoğu `DB::table()` ile yapılır ve modelin
     * `encrypted:array` cast'inden GEÇMEZ; `SealedJson::seal()` unutulan
     * bir yol satırı sessizce DÜZ METİN yazar. Tek bir senaryo testi yalnız
     * kendi yolunu görürdü; bu değişmez test takımının sürdüğü HER yolu
     * (bugünküleri ve ileride eklenecekleri) yakalar.
     */
    private function assertOrderDataEncryptedAtRest(): void
    {
        if (! in_array(RefreshDatabase::class, class_uses_recursive($this), true)
            || $this->status()->isFailure() || $this->status()->isError()) {
            return;
        }

        foreach (['orders' => 'customer_ref', 'order_events' => 'payload', 'inbox_messages' => 'payload'] as $table => $column) {
            try {
                $rows = DB::table($table)->whereNotNull($column)->pluck($column, 'id');
            } catch (QueryException) {
                // Test bilerek bir DB hatası üretti ve transaction "aborted"
                // kaldı: okunamaz, ölçülecek bir şey de yok.
                return;
            }

            foreach ($rows as $id => $value) {
                try {
                    SealedJson::open((string) $value);
                } catch (DecryptException) {
                    $this->fail("{$table}.{$column} ({$id}) diskte ŞİFRESİZ: ham yazımda SealedJson::seal() unutulmuş.");
                }
            }
        }
    }

    /**
     * Kiracı bağlamı kurmadan model yaratmak için sistem bağlamı yardımcısı.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function asSystem(\Closure $callback): mixed
    {
        return TenantContext::runAsSystem($callback);
    }

    /** Belirli kiracı bağlamında çalıştırır. */
    protected function asTenant(Tenant|string $tenant, \Closure $callback): mixed
    {
        $id = $tenant instanceof Tenant ? $tenant->id : $tenant;

        return TenantContext::runFor($id, $callback);
    }
}
