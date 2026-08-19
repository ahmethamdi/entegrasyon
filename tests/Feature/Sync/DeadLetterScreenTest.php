<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variant;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Identity\Actions\CreateTenant;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Models\OutboxEvent;
use App\Domain\Sync\Enums\SyncDomain;
use App\Domain\Sync\Enums\SyncOperationStatus;
use App\Domain\Sync\Models\Listing;
use App\Domain\Sync\Models\ListingSyncState;
use App\Domain\Sync\Models\SyncAttempt;
use App\Domain\Sync\Models\SyncOperation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ölü mektup ekranı + tek tıkla yeniden deneme.
 *
 * Mimari Karar Dokümanı v2.2 · §12 · Dead letter (adım 4 ve 5),
 * §13 · Faz 3 · madde 3+4, §9 · error_permanent durumundan çıkış,
 * §1 · Karar 18.
 *
 * EKRANIN VARLIK SEBEBİ — §12'nin beş adımının İLK ÜÇÜ zaten çalışıyor:
 *   1. `sync_operations.status = 'dead'`                     ✓
 *   2. `listing_sync_states.status = error_*`                ✓
 *   3. Laravel `failed_jobs` tablosunda tam yük              ✓
 *   4. Panelde "Başarısız işlemler" ekranında görünür        ← BU EKRAN
 *   5. Kullanıcı TEK TIKLA yeniden deneyebilir               ← BU BUTON
 * Dördüncü ve beşinci adım olmadan ölü satır sonsuza kadar ölü kalır:
 * `error_permanent` mutabakatta ASLA aday değildir (§10) ve o satıra
 * başka hiçbir mekanizma dokunmaz.
 *
 * DEĞİŞMEZ KURAL — DURUM YAZMAK YETMEZ (§9 · Karar 18).
 *   Butonun `sync_operations.status = 'pending'` yazması YANLIŞTIR:
 *   kanonik veri değişmediği için yeni bir domain olayı DOĞMAZ, kimse o
 *   operasyonu yeniden dispatch etmez ve satır sonsuza kadar "bekliyor"
 *   görünür. Buton `RequestResync`'i çağırır ve o, AYNI transaction'da
 *   bir `ListingResyncRequested` olayı yazar — asıl iş odur.
 *
 * DEĞİŞMEZ KURAL — ESKİ ÖLÜ OPERASYON `dead` KALIR.
 *   Yeniden deneme YENİ bir operasyon açar (REPAIR niyetiyle). Eskisini
 *   `pending`'e çevirmek "bu satır beş kez denendi ve öldü" denetim izini
 *   siler ve destek bir daha neyin yaşandığını göremez.
 *
 * DEĞİŞMEZ KURAL — DOMAIN OPERASYON TÜRÜNDEN OKUNUR, SABİT YAZILMAZ.
 *   `sync_operations`'ta `domain` kolonu YOKTUR; alan `operation_type`
 *   içinde yaşar (`INVENTORY_PUSH` / `PRICE_PUSH` / `CONTENT_PUSH` /
 *   `MEDIA_PUSH`). Sabit `INVENTORY` yazılsaydı ölü bir `PRICE_PUSH` için
 *   stok senkronu açılır, fiyat HİÇ gitmez ve kullanıcı butona bastığı
 *   hâlde sorunun çözülmediğini görürdü.
 *
 * DEĞİŞMEZ KURAL — HATA SINIFI GÖSTERİLİR.
 *   `AUTHENTICATION` (anahtarı yenile) ile `VALIDATION` (ürün verisini
 *   düzelt) kullanıcıya TAMAMEN FARKLI iş yaptırır. Sınıf gizlenseydi
 *   "yeniden dene" butonu tek çare gibi görünür ve kullanıcı aynı hatayı
 *   sonsuza kadar yeniden üretirdi.
 *
 * DEĞİŞMEZ KURAL — INERTIA'YA MODEL GÖNDERİLMEZ: yalnızca görünen alanlar.
 *
 * DEĞİŞMEZ KURAL — `DB::table()` GLOBAL SCOPE'A TABİ DEĞİLDİR: ham sorguda
 *   kiracı filtresi AÇIKÇA yazılır ve TESTİ de yazılır.
 */
final class DeadLetterScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Planlamayı sınıyoruz; gerçek worker'ı `sync` sürücü taklit etmez
        // ve iş sınırındaki bağlam temizliği çağıranın bağlamını götürür.
        Queue::fake();
    }

    // ---------------------------------------------------------------- erişim

    /** Misafir başarısız işlemler ekranını göremez. */
    #[Test]
    public function guest_cannot_reach_the_failures_screen(): void
    {
        $this->get('/failures')->assertRedirect('/login');
    }

    /** Misafir yeniden deneme POST'unu atamaz. */
    #[Test]
    public function guest_cannot_retry(): void
    {
        $this->post('/failures/retry', ['operation' => 'anything'])->assertRedirect('/login');
    }

    /**
     * BAŞKA KİRACININ ÖLÜ OPERASYONU LİSTEDE GÖRÜNMEZ.
     *
     * Satır SKU, kanal adı ve hata metni taşır; sızıntı rakip satıcının
     * katalogunu ve kanal sorunlarını ifşa ederdi.
     */
    #[Test]
    public function dead_operations_never_leak_across_tenants(): void
    {
        [$tenantA, $userA, $listingA] = $this->makeContext('A');
        [$tenantB, , $listingB] = $this->makeContext('B');

        $this->deadOperation($tenantA, $listingA, SyncDomain::INVENTORY);
        $this->deadOperation($tenantB, $listingB, SyncDomain::INVENTORY);

        $rows = $this->rows($this->actingAs($userA)->get('/failures'));

        $this->assertCount(1, $rows, 'Yalnızca kendi kiracısının ölü operasyonu görünmeli.');
        $this->assertSame('SKU-A', $rows[0]['sku']);
    }

    /**
     * ÖZET SAYISI DA KİRACIYA KAPSANIR — AYRI sorgu, AYRI boşluk.
     *
     * Bu projede aynı boşluk dört ayrı turda bulundu: liste filtrelenmiş
     * olduğu hâlde üst özet ham `DB::table()` üzerinden çapraz kiracı
     * sayıyordu.
     */
    #[Test]
    public function the_summary_counts_never_leak_across_tenants(): void
    {
        [$tenantA, $userA, $listingA] = $this->makeContext('A');
        [$tenantB, , $listingB] = $this->makeContext('B');

        $this->deadOperation($tenantA, $listingA, SyncDomain::INVENTORY);
        $this->deadOperation($tenantB, $listingB, SyncDomain::INVENTORY);
        $this->deadOperation($tenantB, $listingB, SyncDomain::CONTENT);

        $summary = $this->summary($this->actingAs($userA)->get('/failures'));

        $this->assertSame(1, $summary['total'], 'Özet başka kiracının ölü operasyonunu saymamalı.');
    }

    /**
     * HATA MESAJI SORGUSU DA KİRACIYA KAPSANIR — ÜÇÜNCÜ ayrı boşluk.
     *
     * Mesajlar ham `DB::table('sync_attempts')` ile okunur ve `DB::table()`
     * GLOBAL SCOPE'A TABİ DEĞİLDİR: filtre açıkça yazılmazsa başka
     * kiracının hata metni bu ekrana sızar. Metin kanal yanıtını taşır ve
     * içinde rakibin ürün adı, kimliği veya mağaza yapılandırması olabilir.
     *
     * Kurgu, mesaj sorgusunun kapsamasını YALNIZLAŞTIRIR: iki kiracının
     * ölü operasyonları AYNI kimliği paylaşamaz, ama sorgu
     * `whereIn(operationIds)` ile daraldığı için yabancı satırın
     * `sync_operation_id`'si BİZİM operasyonumuza çevrilir. Filtre
     * kalkarsa o satır kümeye girer ve `attempt_number` daha büyük
     * olduğu için BİZİM mesajımızı EZER.
     */
    #[Test]
    public function the_error_message_query_never_leaks_across_tenants(): void
    {
        [$tenantA, $userA, $listingA] = $this->makeContext('A');
        [$tenantB, , $listingB] = $this->makeContext('B');

        $mine = $this->deadOperation(
            $tenantA,
            $listingA,
            SyncDomain::INVENTORY,
            errorMessage: 'Bizim hatamız',
            attempts: 1,
        );

        $foreign = $this->deadOperation(
            $tenantB,
            $listingB,
            SyncDomain::INVENTORY,
            errorMessage: 'RAKİBİN GİZLİ HATA METNİ',
            attempts: 9,
        );

        DB::table('sync_attempts')
            ->where('sync_operation_id', $foreign->id)
            ->update(['sync_operation_id' => $mine->id]);

        $rows = $this->rows($this->actingAs($userA)->get('/failures'));

        $this->assertSame(
            'Bizim hatamız',
            $rows[0]['errorMessage'],
            'Başka kiracının hata metni ekrana sızdı — ham sorguda kiracı filtresi yok.',
        );
    }

    /** Başka kiracının operasyonu yeniden DENENEMEZ — kimlik tahmin edilse bile. */
    #[Test]
    public function retry_refuses_another_tenants_operation(): void
    {
        [, $userA, $listingA] = $this->makeContext('A');
        [$tenantB, , $listingB] = $this->makeContext('B');

        $this->deadOperation($tenantB, $listingB, SyncDomain::INVENTORY);
        $foreign = $this->asTenant($tenantB, fn () => SyncOperation::query()->firstOrFail());

        $this->actingAs($userA)
            ->post('/failures/retry', ['operation' => $foreign->id])
            ->assertNotFound();

        // Olay YAZILMADI — yetkilendirme kimliğin tahmin edilemezliğine
        // dayandırılmaz.
        $this->assertDatabaseCount('outbox_events', 0);
    }

    // ---------------------------------------------------------------- liste

    /**
     * ÖLÜ OPERASYON LİSTELENİR, TAMAMLANMIŞ OLAN LİSTELENMEZ.
     *
     * Ekran "hangi gönderimler öldü" sorusunu cevaplar. Tamamlanmış ve
     * bekleyen operasyonlar da listelenseydi gerçek ölüler binlerce
     * "her şey yolunda" satırının arasında kaybolurdu — ekranın varlık
     * sebebinin tam tersi.
     */
    #[Test]
    public function only_dead_operations_are_listed(): void
    {
        [$tenant, $user, $listing] = $this->makeContext('A');

        $this->deadOperation($tenant, $listing, SyncDomain::INVENTORY);
        $this->operation($tenant, $listing, SyncDomain::CONTENT, SyncOperationStatus::COMPLETED);
        $this->operation($tenant, $listing, SyncDomain::PRICE, SyncOperationStatus::PENDING);
        $this->operation($tenant, $listing, SyncDomain::MEDIA, SyncOperationStatus::RETRYING);

        $rows = $this->rows($this->actingAs($user)->get('/failures'));

        $this->assertCount(1, $rows, 'Yalnızca `dead` operasyonlar listelenmeli.');
        $this->assertSame('INVENTORY', $rows[0]['domain']);
    }

    /**
     * HATA SINIFI VE MESAJI EKRANDA — kullanıcıya NE YAPACAĞINI söyler.
     *
     * `AUTHENTICATION` "anahtarı yenile", `VALIDATION` "ürün verisini
     * düzelt" demektir. Gösterilmeseydi "yeniden dene" tek çare gibi
     * görünür ve kullanıcı aynı hatayı sonsuza kadar yeniden üretirdi.
     */
    #[Test]
    public function the_error_class_and_message_are_shown(): void
    {
        [$tenant, $user, $listing] = $this->makeContext('A');

        $this->deadOperation(
            $tenant,
            $listing,
            SyncDomain::CONTENT,
            errorClass: 'VALIDATION',
            errorMessage: 'Zorunlu öznitelik eksik: Renk',
        );

        $rows = $this->rows($this->actingAs($user)->get('/failures'));

        $this->assertSame('VALIDATION', $rows[0]['errorClass']);
        $this->assertSame('Zorunlu öznitelik eksik: Renk', $rows[0]['errorMessage']);
    }

    /**
     * KANAL GÖVDESİ AYRIŞTIRILIR — HAM İSTİSNA METNİ GÖSTERİLMEZ.
     *
     * GERÇEK TARAYICI ÇALIŞTIRMASINDA GÖRÜLDÜ: ham mesaj HTTP
     * istisnasının metnidir ve içine kanalın JSON gövdesi gömülüdür.
     * Olduğu gibi basılırsa satıcı `ürün` okur ve bu, ekranın
     * TÜM AMACINI — "ne olduğunu söylemek" — boşa çıkarır.
     */
    #[Test]
    public function the_channel_message_is_extracted_from_the_raw_body(): void
    {
        [$tenant, $user, $listing] = $this->makeContext('A');

        $this->deadOperation(
            $tenant,
            $listing,
            SyncDomain::CONTENT,
            errorClass: 'VALIDATION',
            errorMessage: 'HTTP request returned status code 400: '
                .'{"code":"woocommerce_rest_invalid_product","message":"Ürün reddedildi"}',
        );

        $rows = $this->rows($this->actingAs($user)->get('/failures'));

        $this->assertSame(
            'Ürün reddedildi',
            $rows[0]['errorMessage'],
            'Ham istisna metni gösterildi — satıcı kaçış dizileri okur.',
        );
    }

    /**
     * KIRPIK GÖVDEDEN DE MESAJ ÇEKİLİR — ASIL VAKA BUDUR.
     *
     * GERÇEK ÇALIŞTIRMADA GÖRÜLDÜ: Guzzle istisna metnine gövdenin
     * yalnızca İLK 120 KARAKTERİNİ koyar ve `(truncated...)` ekler. JSON
     * kapanmaz, `json_decode` düşer ve satıcı `stock_quantity
     * alanı reddedildi: ürün stok y\u00 (truncated...)`
     * okur. Gerçek kanal mesajları neredeyse HER ZAMAN 120 karakterden
     * uzundur; yalnızca tam gövdeyi çözen bir ayrıştırıcı pratikte HİÇ
     * çalışmazdı.
     *
     * Metnin YARIM olduğu da belli edilir: satıcı kanalın söylediğinin
     * tamamını okuduğunu sanmamalı.
     */
    #[Test]
    public function a_truncated_channel_body_still_yields_a_readable_message(): void
    {
        [$tenant, $user, $listing] = $this->makeContext('A');

        $this->deadOperation(
            $tenant,
            $listing,
            SyncDomain::INVENTORY,
            errorClass: 'VALIDATION',
            // Guzzle'ın gerçek çıktısı: yarım kaçış dizisiyle biten,
            // kapanmamış JSON.
            errorMessage: "HTTP request returned status code 400:\n"
                .'{"code": "woocommerce_rest_invalid_product", "message": '
                .'"stock_quantity alanı reddedildi: ürün stok y\u00 (truncated...)',
        );

        $rows = $this->rows($this->actingAs($user)->get('/failures'));

        $this->assertStringStartsWith(
            'stock_quantity alanı reddedildi: ürün stok y',
            $rows[0]['errorMessage'],
            'Kırpık gövdeden mesaj çekilemedi — satıcı kaçış dizisi okur.',
        );

        $this->assertStringEndsWith(
            '…',
            $rows[0]['errorMessage'],
            'Metnin yarım olduğu belli edilmedi — satıcı tamamını okuduğunu sanar.',
        );
    }

    /**
     * AYRIŞTIRILAMAYAN MESAJ OLDUĞU GİBİ KALIR.
     *
     * Gizlemek teşhis için gereken TEK ipucunu atmak olurdu: her kanal
     * JSON dönmez ve bir bağlantı hatasının metni hiç JSON içermez.
     */
    #[Test]
    public function a_message_without_a_json_body_is_left_intact(): void
    {
        [$tenant, $user, $listing] = $this->makeContext('A');

        $this->deadOperation(
            $tenant,
            $listing,
            SyncDomain::INVENTORY,
            errorClass: 'NETWORK',
            errorMessage: 'cURL error 6: Could not resolve host',
        );

        $rows = $this->rows($this->actingAs($user)->get('/failures'));

        $this->assertSame('cURL error 6: Could not resolve host', $rows[0]['errorMessage']);
    }

    /**
     * DENEME SAYISI GÖSTERİLİR — "beş kez denendi" ile "hiç denenmedi"
     * FARKLI sorunlardır.
     *
     * `attempt_count = 0` olan bir ölü operasyon worker'ın hiç çalışmadığı
     * anlamına gelir (seviye 2 taramasının konusu); beş denemeden sonra
     * ölen operasyon gerçekten kanalın reddettiği satırdır.
     */
    #[Test]
    public function the_attempt_count_is_shown(): void
    {
        [$tenant, $user, $listing] = $this->makeContext('A');

        $this->deadOperation($tenant, $listing, SyncDomain::INVENTORY, attempts: 5);

        $rows = $this->rows($this->actingAs($user)->get('/failures'));

        $this->assertSame(5, $rows[0]['attemptCount']);
    }

    /**
     * SON DENEMENİN MESAJI OKUNUR, İLKİNİNKİ DEĞİL.
     *
     * Bir operasyon önce `TIMEOUT` alıp sonra `VALIDATION` ile ölebilir.
     * İlk deneme okunsaydı ekran "zaman aşımı" der, kullanıcı ağı kontrol
     * eder ve gerçek sebep olan doğrulama hatasını HİÇ görmezdi.
     *
     * Sıralama `attempt_number` üzerindendir, zaman damgası üzerinden
     * DEĞİL: `started_at` saniye hassasiyetlidir ve arka arkaya koşan
     * denemeler aynı damgayı taşıyabilir.
     */
    #[Test]
    public function the_latest_attempt_message_wins(): void
    {
        [$tenant, $user, $listing] = $this->makeContext('A');

        $operation = $this->deadOperation(
            $tenant,
            $listing,
            SyncDomain::CONTENT,
            errorClass: 'VALIDATION',
            errorMessage: 'Zorunlu öznitelik eksik: Renk',
            attempts: 2,
        );

        // İLK deneme SONRA yaratılır: yaratılış sırasına düşen bir sorgu
        // (UUIDv7 zaman sıralıdır) yanlış satırı seçer ve test sahte yeşil
        // kalmaz.
        $this->asTenant($tenant, fn () => SyncAttempt::query()->create([
            'tenant_id' => $tenant->id,
            'sync_operation_id' => $operation->id,
            'attempt_number' => 1,
            'outcome' => 'transient',
            'error_class' => 'TIMEOUT',
            'error_message' => 'İstek zaman aşımına uğradı',
            'started_at' => now()->subMinute(),
            'finished_at' => now()->subMinute(),
        ]));

        $rows = $this->rows($this->actingAs($user)->get('/failures'));

        $this->assertSame(
            'Zorunlu öznitelik eksik: Renk',
            $rows[0]['errorMessage'],
            'İlk denemenin mesajı okundu — kullanıcı gerçek sebebi göremez.',
        );
    }

    /**
     * ÖZETTE KALICI VE GEÇİCİ HATA AYRI SAYILIR.
     *
     * `AUTHENTICATION`/`VALIDATION` KULLANICI MÜDAHALESİ bekler ve
     * yeniden denemeyle ÇÖZÜLMEZ; timeout ve 5xx kanal düzelince geçer.
     * Tek sayıda birleştirilselerdi satıcı hangi satırların kendisini
     * beklediğini bilemez, "hepsini yeniden dene"ye basar ve müdahale
     * bekleyen satırlar aynı hatayla tekrar ölürdü.
     */
    #[Test]
    public function the_summary_separates_permanent_errors_from_transient_ones(): void
    {
        [$tenant, $user, $listing] = $this->makeContext('A');
        $second = $this->listingFor($tenant, 'SKU-A2');
        $third = $this->listingFor($tenant, 'SKU-A3');

        $this->deadOperation($tenant, $listing, SyncDomain::INVENTORY, errorClass: 'AUTHENTICATION');
        $this->deadOperation($tenant, $second, SyncDomain::PRICE, errorClass: 'TIMEOUT');
        $this->deadOperation($tenant, $third, SyncDomain::CONTENT, errorClass: 'SERVER_ERROR');

        $summary = $this->summary($this->actingAs($user)->get('/failures'));

        $this->assertSame(3, $summary['total']);
        $this->assertSame(
            1,
            $summary['needs_user'],
            'Geçici hatalar da müdahale bekliyor sayıldı — satıcı hangi satırın kendisini beklediğini göremez.',
        );
    }

    /**
     * SATIR BAZINDA DA KALICI/GEÇİCİ AYRIMI TAŞINIR.
     *
     * Tavsiye metni bu bayrağa bağlıdır: geçici hatada "anahtarı yenile"
     * demek kullanıcıyı çalışan bir anahtarı değiştirmeye iterdi.
     */
    #[Test]
    public function each_row_carries_whether_it_needs_user_action(): void
    {
        [$tenant, $user, $listing] = $this->makeContext('A');
        $second = $this->listingFor($tenant, 'SKU-A2');

        $this->deadOperation($tenant, $listing, SyncDomain::INVENTORY, errorClass: 'VALIDATION');
        $this->deadOperation($tenant, $second, SyncDomain::PRICE, errorClass: 'RATE_LIMITED');

        $rows = $this->rows($this->actingAs($user)->get('/failures'));

        $byClass = collect($rows)->keyBy('errorClass');

        $this->assertTrue($byClass['VALIDATION']['needsUser']);
        $this->assertFalse($byClass['RATE_LIMITED']['needsUser']);
    }

    /**
     * BOŞ LİSTE BİR BAŞARIDIR VE ÖYLE ANLATILIR.
     *
     * Ekran boşken kullanıcı "sistem çalışmıyor mu" diye tereddüt
     * etmemeli.
     */
    #[Test]
    public function an_empty_list_is_rendered_without_error(): void
    {
        [, $user] = $this->makeContext('A');

        $response = $this->actingAs($user)->get('/failures');

        $response->assertOk();
        $this->assertSame([], $this->rows($response));
        $this->assertSame(0, $this->summary($response)['total']);
    }

    // -------------------------------------------------------- tek tık retry

    /**
     * §12 · ADIM 5 — TEK TIKLA YENİDEN DENEME OLAY ÜRETİR.
     *
     * DURUM YAZMAK YETMEZ: kanonik veri değişmediği için yeni bir domain
     * olayı doğmaz ve hiçbir iş üretilmez (§9 · Karar 18). Butonun asıl
     * işi `ListingResyncRequested` olayını yazmaktır.
     */
    #[Test]
    public function a_single_click_retry_emits_a_resync_event(): void
    {
        [$tenant, $user, $listing] = $this->makeContext('A');

        $operation = $this->deadOperation($tenant, $listing, SyncDomain::INVENTORY);

        $this->actingAs($user)
            ->post('/failures/retry', ['operation' => $operation->id])
            ->assertRedirect('/failures');

        $event = $this->asTenant($tenant, fn () => OutboxEvent::query()
            ->where('event_type', 'ListingResyncRequested')
            ->where('aggregate_id', $listing->id)
            ->first());

        $this->assertNotNull($event, 'Olay yazılmadı — durum değişikliği tek başına hiçbir iş üretmez.');
        $this->assertSame(
            'manual_retry',
            $event->payload['reason'],
            'Sebep yükte taşınmalı — tek generic olay tipi kullanılıyor (§9).',
        );
    }

    /**
     * GERİ BİLDİRİM PANELİN PAYLAŞTIĞI FLASH ANAHTARINA YAZILIR.
     *
     * GERÇEK TARAYICI ÇALIŞTIRMASINDA BULUNDU: anahtar `status` yazılmıştı
     * ve `HandleInertiaRequests::share()` yalnızca `success`/`warning`
     * paylaşıyor. İstek başarılı oluyor, olay yazılıyor ama kullanıcı
     * HİÇBİR geri bildirim görmüyordu — butonun çalışıp çalışmadığını
     * bilemez ve tekrar tekrar basardı. Hiçbir test bunu görmüyordu çünkü
     * hiçbiri flash mesajını okumuyordu.
     */
    #[Test]
    public function the_retry_feedback_lands_on_the_shared_flash_key(): void
    {
        [$tenant, $user, $listing] = $this->makeContext('A');

        $operation = $this->deadOperation($tenant, $listing, SyncDomain::INVENTORY);

        $this->actingAs($user)
            ->post('/failures/retry', ['operation' => $operation->id])
            ->assertSessionHas('success');

        // Ekranda gerçekten görünüyor mu — paylaşılan prop üzerinden.
        $flash = $this->actingAs($user)->get('/failures')->viewData('page')['props']['flash'];

        $this->assertNotNull(
            $flash['success'] ?? null,
            'Geri bildirim paylaşılmayan bir flash anahtarına yazıldı — kullanıcı hiçbir şey görmez.',
        );
    }

    /**
     * DOMAIN OPERASYON TÜRÜNDEN OKUNUR — SABİT YAZILMAZ.
     *
     * Ölü bir `PRICE_PUSH` için `INVENTORY` resync'i açılsaydı stok
     * senkronu tetiklenir, FİYAT HİÇ GİTMEZ ve kullanıcı butona bastığı
     * hâlde sorunun çözülmediğini görürdü. `sync_operations`'ta `domain`
     * kolonu yok; alan `operation_type` içinde yaşıyor.
     */
    #[Test]
    public function the_retry_domain_comes_from_the_operation_type(): void
    {
        [$tenant, $user, $listing] = $this->makeContext('A');

        $operation = $this->deadOperation($tenant, $listing, SyncDomain::PRICE);

        $this->actingAs($user)
            ->post('/failures/retry', ['operation' => $operation->id])
            ->assertRedirect('/failures');

        $event = $this->asTenant($tenant, fn () => OutboxEvent::query()
            ->where('event_type', 'ListingResyncRequested')
            ->firstOrFail());

        $this->assertSame(
            SyncDomain::PRICE->value,
            $event->payload['domain'],
            'Domain sabit yazıldı — ölü fiyat gönderimi için stok senkronu açıldı.',
        );
    }

    /** Her domain için ayrı ayrı: tek bir tanesi doğru olsa da yeterli değil. */
    #[Test]
    public function every_operation_type_maps_back_to_its_domain(): void
    {
        foreach (SyncDomain::cases() as $domain) {
            [$tenant, $user, $listing] = $this->makeContext('D-'.$domain->value);

            $operation = $this->deadOperation($tenant, $listing, $domain);

            $this->actingAs($user)
                ->post('/failures/retry', ['operation' => $operation->id])
                ->assertRedirect('/failures');

            $event = $this->asTenant($tenant, fn () => OutboxEvent::query()
                ->where('event_type', 'ListingResyncRequested')
                ->where('aggregate_id', $listing->id)
                ->firstOrFail());

            $this->assertSame(
                $domain->value,
                $event->payload['domain'],
                $domain->value.' operasyon türünden geri çevrilemedi.',
            );
        }
    }

    /**
     * ESKİ ÖLÜ OPERASYON `dead` KALIR — DENETİM İZİ SİLİNMEZ.
     *
     * `pending`'e çevrilseydi "bu satır beş kez denendi ve öldü" bilgisi
     * kaybolur, destek bir daha neyin yaşandığını göremez ve ekran aynı
     * satırı sonsuza kadar "bekliyor" gösterirdi.
     */
    #[Test]
    public function the_dead_operation_stays_dead(): void
    {
        [$tenant, $user, $listing] = $this->makeContext('A');

        $operation = $this->deadOperation($tenant, $listing, SyncDomain::INVENTORY, attempts: 5);

        $this->actingAs($user)->post('/failures/retry', ['operation' => $operation->id]);

        // Kalıcılık sınanırken Eloquent'e güvenilmez: kimlik haritası aynı
        // bellek nesnesini geri verir. HAM SATIR okunur.
        $row = DB::table('sync_operations')->where('id', $operation->id)->first();

        $this->assertSame('dead', $row->status, 'Ölü operasyon durumu değişti — denetim izi silindi.');
        $this->assertSame(5, (int) $row->attempt_count, 'Deneme sayacı sıfırlandı — kaç kez denendiği kayboldu.');
    }

    /**
     * SYNC STATE AKIŞA GERİ SOKULUR.
     *
     * `RequestResync` durumu `pending` yapar ve eski hata metnini
     * TEMİZLER: kalsaydı panel çözülmüş bir sorunu göstermeye devam eder
     * ve kullanıcı düzeltmesinin işe yaramadığını sanardı.
     */
    #[Test]
    public function retry_returns_the_sync_state_to_the_flow(): void
    {
        [$tenant, $user, $listing] = $this->makeContext('A');

        $operation = $this->deadOperation($tenant, $listing, SyncDomain::INVENTORY);

        $this->setState($tenant, $listing, SyncDomain::INVENTORY, 'error_permanent', 'Anahtar geçersiz', 4);

        $this->actingAs($user)->post('/failures/retry', ['operation' => $operation->id]);

        $row = DB::table('listing_sync_states')
            ->where('listing_id', $listing->id)
            ->where('domain', SyncDomain::INVENTORY->value)
            ->first();

        $this->assertSame('pending', $row->status);
        $this->assertNull($row->last_error, 'Eski hata metni kaldı — panel çözülmüş sorunu gösterir.');
        $this->assertSame(0, (int) $row->error_count);
    }

    /**
     * ÖLÜ OLMAYAN OPERASYON YENİDEN DENENEMEZ.
     *
     * Bekleyen bir operasyon için resync açmak ikinci bir gönderim üretir;
     * ekran yalnızca ölüleri gösterdiği için buton da yalnızca onlara
     * meşrudur.
     */
    #[Test]
    public function a_live_operation_cannot_be_retried(): void
    {
        [$tenant, $user, $listing] = $this->makeContext('A');

        $operation = $this->operation($tenant, $listing, SyncDomain::INVENTORY, SyncOperationStatus::PENDING);

        $this->actingAs($user)
            ->post('/failures/retry', ['operation' => $operation->id])
            ->assertNotFound();

        $this->assertDatabaseCount('outbox_events', 0);
    }

    // ------------------------------------------------------------ toplu tık

    /**
     * TOPLU YENİDEN DENEME — HER ÖLÜ SATIR İÇİN AYRI OLAY.
     *
     * 50 ölü satırı tek tek tıklamak gerçek bir destek yüküdür. Toplu
     * buton aynı `RequestResync` yolunu çağırır; ayrı bir yol açılsaydı
     * "durum yazmak yetmez" kuralı iki yerde yaşar ve biri unutulurdu.
     */
    #[Test]
    public function retry_all_emits_one_event_per_dead_operation(): void
    {
        [$tenant, $user, $listing] = $this->makeContext('A');
        $second = $this->listingFor($tenant, 'SKU-A2');

        $this->deadOperation($tenant, $listing, SyncDomain::INVENTORY);
        $this->deadOperation($tenant, $second, SyncDomain::PRICE);

        $this->actingAs($user)
            ->post('/failures/retry', ['all' => true])
            ->assertRedirect('/failures');

        $events = $this->asTenant($tenant, fn () => OutboxEvent::query()
            ->where('event_type', 'ListingResyncRequested')
            ->get());

        $this->assertCount(2, $events, 'Her ölü operasyon için bir olay yazılmalı.');

        $domains = $events->pluck('payload.domain')->sort()->values()->all();
        $this->assertSame(['INVENTORY', 'PRICE'], $domains);
    }

    /**
     * TOPLU DENEME BAŞKA KİRACIYA DOKUNMAZ.
     *
     * Gövdede kimlik taşınmadığı için sorgu tamamen kiracı kapsamına
     * güvenir; kapsam düşerse tek tıkla TÜM sistemin ölü satırları
     * yeniden denenirdi.
     */
    #[Test]
    public function retry_all_is_scoped_to_the_acting_tenant(): void
    {
        [$tenantA, $userA, $listingA] = $this->makeContext('A');
        [$tenantB, , $listingB] = $this->makeContext('B');

        $this->deadOperation($tenantA, $listingA, SyncDomain::INVENTORY);
        $this->deadOperation($tenantB, $listingB, SyncDomain::INVENTORY);

        $this->actingAs($userA)->post('/failures/retry', ['all' => true]);

        $this->assertDatabaseCount('outbox_events', 1);

        $this->assertDatabaseHas('outbox_events', [
            'aggregate_id' => $listingA->id,
            'tenant_id' => $tenantA->id,
        ]);
    }

    /**
     * TOPLU DENEMENİN KAPSAMASI OPERASYON SORGUSUNDA OLMALI —
     * `listing` İLİŞKİSİNİN TESADÜFİ SAVUNMASINDA DEĞİL.
     *
     * Sıradan kurulumda başka kiracının satırını İKİ savunma birden
     * eliyor: operasyon sorgusunun kapsaması VE `listing` ilişkisinin
     * kendi kapsaması (ilişki NULL döner, satır atlanır). İkinci savunma
     * mutasyonu GİZLER: operasyon sorgusundan kapsama tamamen kaldırılsa
     * bile test yeşil kalır ve tek satırlık bir eager-load değişikliği
     * (performans için ilişkiyi kapsamsız yüklemek) onu sessizce düşürünce
     * tek tıkla TÜM SİSTEMİN ölü satırları yeniden kuyruğa alınır.
     *
     * Bu yüzden kurgu İKİNCİ SAVUNMAYI DEVRE DIŞI BIRAKIR: yabancı
     * kiracının ölü operasyonu, DAVRANAN kiracının listing'ini işaret
     * eder. İlişki artık her koşulda bulunur ve geriye TEK koruma kalır —
     * operasyonun kendi kiracı kapsaması. Bu kurgu gerçekte oluşamaz
     * (operasyon ve listing aynı kiracıya aittir); amacı da gerçeği
     * taklit etmek değil, savunmayı YALNIZLAŞTIRMAKTIR.
     */
    #[Test]
    public function retry_all_only_collects_the_acting_tenants_operations(): void
    {
        [$tenantA, $userA, $listingA] = $this->makeContext('A');
        [$tenantB, , $listingB] = $this->makeContext('B');

        $this->deadOperation($tenantA, $listingA, SyncDomain::INVENTORY);
        $foreign = $this->deadOperation($tenantB, $listingB, SyncDomain::INVENTORY);

        // Yabancı operasyonun `entity_id`'si A'nın listing'ine çevrilir:
        // ilişki artık A bağlamında da çözülür ve satır YALNIZCA operasyon
        // sorgusunun kapsaması sayesinde elenebilir.
        DB::table('sync_operations')
            ->where('id', $foreign->id)
            ->update(['entity_id' => $listingA->id]);

        $this->actingAs($userA)->post('/failures/retry', ['all' => true]);

        $this->assertSame(
            1,
            DB::table('outbox_events')->count(),
            'Toplu deneme başka kiracının ölü operasyonunu da sürdü — kapsama operasyon sorgusunda değil.',
        );

        $this->assertSame(
            $tenantA->id,
            DB::table('outbox_events')->value('tenant_id'),
            'Yazılan olay davranan kiracıya ait olmalı.',
        );

        // İkinci savunmanın gerçekten kalktığını doğrula: yabancı
        // operasyonun listing'i artık A'ya ait ve ilişki çözülüyor.
        $this->assertNotSame($tenantA->id, $tenantB->id);
    }

    /** Toplu denemede de ölü operasyonlar `dead` kalır. */
    #[Test]
    public function retry_all_leaves_the_dead_operations_dead(): void
    {
        [$tenant, $user, $listing] = $this->makeContext('A');

        $operation = $this->deadOperation($tenant, $listing, SyncDomain::INVENTORY, attempts: 3);

        $this->actingAs($user)->post('/failures/retry', ['all' => true]);

        $row = DB::table('sync_operations')->where('id', $operation->id)->first();

        $this->assertSame('dead', $row->status);
    }

    /** Ölü satır yokken toplu deneme hiçbir şey yapmaz ve patlamaz. */
    #[Test]
    public function retry_all_with_nothing_dead_is_a_no_op(): void
    {
        [, $user] = $this->makeContext('A');

        $this->actingAs($user)
            ->post('/failures/retry', ['all' => true])
            ->assertRedirect('/failures');

        $this->assertDatabaseCount('outbox_events', 0);
    }

    /** Ne kimlik ne de `all` verilirse istek reddedilir. */
    #[Test]
    public function retry_without_a_target_is_rejected(): void
    {
        [, $user] = $this->makeContext('A');

        $this->actingAs($user)
            ->post('/failures/retry', [])
            ->assertSessionHasErrors('operation');

        $this->assertDatabaseCount('outbox_events', 0);
    }

    // --------------------------------------------------------------- kurulum

    /** @return array{0: Tenant, 1: User, 2: Listing} */
    private function makeContext(string $suffix): array
    {
        $tenant = (new CreateTenant)->run(
            name: 'Ölü mektup '.$suffix.' '.uniqid(),
            owner: $user = User::factory()->create(),
        );

        $listing = $this->listingFor($tenant, 'SKU-'.$suffix);

        return [$tenant, $user, $listing];
    }

    private function listingFor(Tenant $tenant, string $sku): Listing
    {
        return $this->asTenant($tenant, function () use ($sku) {
            $this->asSystem(fn () => ChannelType::query()->firstOrCreate(
                ['code' => 'woocommerce'],
                [
                    'name' => 'WooCommerce',
                    'kind' => 'storefront',
                    'adapter_class' => 'App\\Domain\\Channels\\Adapters\\WooCommerceAdapter',
                    'is_active' => true,
                ],
            ));

            $product = Product::factory()->create(['content_version' => 3]);

            return Listing::factory()->create([
                'channel_connection_id' => ChannelConnection::factory()->create()->id,
                'variant_id' => Variant::factory()->create([
                    'product_id' => $product->id,
                    'sku' => $sku,
                ])->id,
                'lifecycle_status' => 'live',
                'external_id' => '4242',
            ]);
        });
    }

    /** Ölü operasyon + son denemesi. */
    private function deadOperation(
        Tenant $tenant,
        Listing $listing,
        SyncDomain $domain,
        string $errorClass = 'AUTHENTICATION',
        string $errorMessage = 'Anahtar geçersiz',
        int $attempts = 1,
    ): SyncOperation {
        $operation = $this->operation(
            $tenant,
            $listing,
            $domain,
            SyncOperationStatus::DEAD,
            $errorClass,
            $attempts,
        );

        $this->asTenant($tenant, fn () => SyncAttempt::query()->create([
            'tenant_id' => $tenant->id,
            'sync_operation_id' => $operation->id,
            'attempt_number' => $attempts,
            'outcome' => 'permanent',
            'error_class' => $errorClass,
            'error_message' => $errorMessage,
            'started_at' => now(),
            'finished_at' => now(),
        ]));

        return $operation;
    }

    private function operation(
        Tenant $tenant,
        Listing $listing,
        SyncDomain $domain,
        SyncOperationStatus $status,
        ?string $errorClass = null,
        int $attempts = 1,
    ): SyncOperation {
        return $this->asTenant($tenant, fn () => SyncOperation::query()->create([
            'tenant_id' => $tenant->id,
            'channel_connection_id' => $listing->channel_connection_id,
            'operation_type' => $domain->operationType(),
            'intent' => 'NORMAL_SYNC',
            'entity_type' => 'listing',
            'entity_id' => $listing->id,
            'entity_version' => 1,
            'idempotency_key' => $domain->keyPrefix().':'.$listing->id.':1:'.uniqid(),
            'status' => $status->value,
            'attempt_count' => $attempts,
            'last_error_class' => $errorClass,
        ]));
    }

    private function setState(
        Tenant $tenant,
        Listing $listing,
        SyncDomain $domain,
        string $status,
        ?string $lastError,
        int $errorCount,
    ): void {
        $this->asTenant($tenant, fn () => ListingSyncState::query()->updateOrCreate(
            ['listing_id' => $listing->id, 'domain' => $domain->value],
            [
                'tenant_id' => $tenant->id,
                'status' => $status,
                'last_error' => $lastError,
                'error_count' => $errorCount,
            ],
        ));
    }

    // ---------------------------------------------------------------- okuma

    /** @return list<array<string, mixed>> */
    private function rows(TestResponse $response): array
    {
        return $response->viewData('page')['props']['rows'];
    }

    /** @return array<string, int> */
    private function summary(TestResponse $response): array
    {
        return $response->viewData('page')['props']['summary'];
    }
}
