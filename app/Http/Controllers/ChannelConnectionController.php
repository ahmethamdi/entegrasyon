<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Billing\Actions\EnforceQuota;
use App\Domain\Billing\Enums\QuotaMetric;
use App\Domain\Billing\Exceptions\QuotaExceededException;
use App\Domain\Channels\Actions\CheckChannelHealth;
use App\Domain\Channels\Actions\ConnectChannel;
use App\Domain\Channels\Exceptions\AccountAlreadyConnectedException;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Models\ChannelType;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Channels\Support\ChannelConnectForm;
use App\Domain\Channels\Support\StoreUrl;
use App\Domain\Channels\Support\TokenStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Kanal bağlama ekranları — §13 · faz 1.4.
 *
 * DEĞİŞMEZ KURAL — INERTIA'YA MODEL GÖNDERİLMEZ:
 *   Yalnızca görünen alanlar gönderilir. Modeli olduğu gibi paylaşmak
 *   `settings` jsonb'sini ve ilişkili kimlik bilgisi kaydını HTTP yanıtına
 *   koyardı.
 *
 * DEĞİŞMEZ KURAL — YETENEKLER TİP SİSTEMİNDEN OKUNUR:
 *   `AdapterRegistry::capabilitiesFor()` `instanceof Supports*` ile çalışır;
 *   panelde "if type === 'woocommerce'" bloğu YAZILMAZ. Yeni kanal
 *   eklendiğinde bu controller ve Vue tarafı değişmez.
 *
 * Kiracı bağlamı `tenant` ara katmanı tarafından kurulur; tüm sorgular onun
 * scope'u altındadır ve başka kiracının bağlantısı görünmez.
 */
final class ChannelConnectionController extends Controller
{
    public function __construct(
        private readonly AdapterRegistry $registry,
    ) {}

    /** Bağlı kanallar ve sağlık durumları. */
    public function index(): InertiaResponse
    {
        return Inertia::render('Channels/Index', [
            'connections' => ChannelConnection::query()
                // adapter_class DA yüklenir: AdapterRegistry onu okuyarak
                // yetenekleri çözer. Yalnızca code,name seçilirse registry
                // "adapter sınıfı tanımlı değil" der ve yetenekler boş kalır.
                ->with('channelType:code,name,adapter_class')
                // §25 · token rozeti. AKTİF kimlik bilgisi eager-load
                // edilir; N+1 olmasın diye ilişki üzerinden okunur ve
                // YALNIZCA `expires_at` seçilir — şifreli gövde panele
                // HİÇ gitmemelidir (§19 · madde 3). `id` seçilmek
                // zorundadır, yoksa Eloquent ilişkiyi eşleyemez.
                ->with('activeCredential:id,channel_connection_id,expires_at')
                ->orderBy('channel_type_code')
                ->orderBy('label')
                ->get()
                ->map(fn (ChannelConnection $c): array => $this->presentConnection($c))
                ->all(),
        ]);
    }

    /** Bağlama formu. */
    public function create(): InertiaResponse
    {
        return Inertia::render('Channels/Create', [
            'channelTypes' => $this->activeChannelTypes(),
        ]);
    }

    /**
     * Mağazayı bağlar.
     *
     * SESSİZ BAŞARI YOK: sağlık kontrolü geçmezse kullanıcı uyarılır.
     * Bağlantı kaydedilir ama `pending` kalır ve senkron çalışmaz.
     */
    public function store(Request $request, ConnectChannel $connect): Response|RedirectResponse
    {
        // ⚠️ KANAL ÖNCE OKUNUR ama DOĞRULAMA TEK TURDUR.
        //
        // Kural kümesi kanala GÖRE değişir, bu yüzden kod önce ham
        // istekten okunur. Ama iki ayrı `validate()` çağrısı YAPILMAZ:
        // ilki başarısız olunca ikincisi HİÇ koşmaz ve boş bir formu
        // gönderen satıcı hataların YALNIZCA YARISINI görürdü —
        // eksikleri doldurup yeniden gönderir, bu kez ÖTEKİ yarıyı
        // alırdı. `missing_fields_fail_validation` tam olarak bunu
        // korur.
        //
        // Ham kod tanımsızsa alan kuralı üretilemez; o durumda yalnızca
        // taban kurallar koşar ve `channel_type_code` zaten reddedilir.
        $code = (string) $request->input('channel_type_code');

        $rules = [
            'channel_type_code' => [
                'required', 'string',
                Rule::exists('channel_types', 'code')->where('is_active', true),
                // ⚠️ KAPI SUNUCUDA DA VAR — PANEL TEK SAVUNMA DEĞİLDİR.
                //
                // Kanal `is_active = true` yapılıp form tanımı
                // unutulursa doğrudan POST atan bir istek onu BOŞ
                // kimlikle kasaya yazdırırdı: satır `pending` kalır,
                // satıcı bağlantıyı "kurulmuş" sanar ve her çağrı 401
                // alır — anahtar yanlış değil, HİÇ SORULMAMIŞTIR.
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && $value !== '' && ! ChannelConnectForm::isDefined($value)) {
                        $fail('Bu kanalın kimlik biçimi panelde tanımlı değil; şu an bağlanamıyor.');
                    }
                },
            ],
            'label' => ['required', 'string', 'max:120'],
            'store_url' => ['required', 'string', 'max:255'],

            // ⚠️ ALAN KURALLARI TANIMDAN TÜRETİLİR — ELLE YAZILMAZ.
            // Elle yazılsaydı form ile doğrulama ayrışır: alan sorulur
            // ama reddedilir, ya da hiç sorulmayan bir alan zorunlu
            // tutulur.
            ...ChannelConnectForm::isDefined($code)
                ? ChannelConnectForm::validationRules($code)
                : [],
        ];

        $fields = $request->validate($rules);

        // Plan kotası (§13 · Faz 4) — YALNIZCA GERÇEKTEN YENİ mağazada.
        //
        // `ConnectChannel` aynı hesabı `firstOrNew` ile yeniden kullanır
        // ve bu, ANAHTAR YENİLEME akışıdır: yeni bağlantı eklemez.
        // Ayrım yapılmasaydı kotası dolu bir satıcı süresi dolmuş
        // anahtarını güncelleyemez ve kanalı KALICI olarak ölürdü —
        // üstelik tam da ödeme yapmasını istediğimiz anda.
        if ($this->wouldAddNewConnection($code, $fields['store_url'])) {
            try {
                app(EnforceQuota::class)->check(QuotaMetric::CHANNELS);
            } catch (QuotaExceededException $e) {
                throw ValidationException::withMessages(['store_url' => $e->userMessage()]);
            }
        }

        // ⚠️ SIR İLE KİMLİK BURADA AYRIŞIR ve AYRI KOLONLARA GİDER.
        //
        // Sırlar ŞİFRELİ kasaya, kimlik alanları ŞİFRESİZ `settings`
        // kolonuna. Karışsalardı ya token panele Inertia prop'u olarak
        // giderdi (kasa şifrelemesi anlamsızlaşır) ya da adapter
        // `settings` içinde aradığı `location_gid`/`shop_id` değerini
        // BULAMAZ ve bağlantı sonsuza kadar `pending` kalırdı.
        $secrets = $this->pick($fields, ChannelConnectForm::secretFields($code));
        $settings = $this->pick($fields, ChannelConnectForm::identityFields($code));

        // OAuth kanalında sağlık kontrolü HENÜZ çalıştırılmaz: kimlik
        // bilgisi yoktur ve kontrol kimliksiz gider.
        $usesOauth = ChannelConnectForm::usesOauth($code);

        try {
            $connection = $connect->run(
                channelTypeCode: $code,
                label: $fields['label'],
                storeUrl: $fields['store_url'],
                secrets: $secrets,
                settings: $settings,
                checkHealth: ! $usesOauth,
            );
        } catch (AccountAlreadyConnectedException $e) {
            // Kısıt ihlalini alan hatasına çevir: kullanıcı 500 değil açıklama görür.
            throw ValidationException::withMessages(['store_url' => $e->getMessage()]);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['store_url' => $e->getMessage()]);
        } catch (Throwable $e) {
            // Veritabanı kısıtı yarışta devreye girdiyse de anlaşılır hata ver.
            if ($this->isAccountUniquenessViolation($e)) {
                throw ValidationException::withMessages([
                    'store_url' => 'Bu mağaza başka bir hesaba bağlı.',
                ]);
            }

            throw $e;
        }

        // ⚠️ OAUTH KANALINDA AKIŞ BURADA BİTMEZ — SATICI KANALA GİDER.
        //
        // `/channels`'a dönülseydi bağlantı `pending` görünür, satıcı
        // "kaydedildi ama cevap vermedi" uyarısını okur ve anahtarlarını
        // kontrol etmeye çalışırdı — oysa henüz hiçbir anahtar VERMEDİ ve
        // yapması gereken tek şey Etsy'de izni onaylamaktır.
        // ⚠️ YETKİLENDİRME ROTASINA `redirect()->route()` YAPILMAZ.
        //
        // O rota POST'tur ve BİLİNÇLİ olarak öyledir (yan etkisi vardır:
        // oturuma tek kullanımlık sır yazar; GET olsaydı tarayıcı ön
        // yüklemesi el sıkışmayı habersiz başlatır ve satıcının gerçek
        // denemesindeki `state`'i EZERDİ). Yönlendirme ise tarayıcıya
        // GET yaptırır: istek hiçbir rotaya UYMAZ, Laravel geri bounce
        // eder ve satıcı hatasız biçimde forma döner — bağlantı
        // kurulmuş olmasına rağmen HİÇBİR ŞEY OLMAMIŞ görünür.
        //
        // GERÇEK TARAYICI ÇALIŞTIRMASINDA bulundu. Testler göremedi:
        // `assertRedirect` yalnızca ADRESİ karşılaştırır, yönlendirmeyi
        // İZLEMEZ — "yazıldı ≠ çağrılıyor" kuralının bir biçimi.
        //
        // El sıkışmayı bu yüzden AYNI istekte başlatırız; satıcı tek
        // adımda Etsy'ye gider.
        if ($usesOauth) {
            return $this->startOauthHandshake($request, $code, $connection);
        }

        return redirect('/channels')->with(
            ...$this->connectionFlash($connection),
        );
    }

    /**
     * Sağlık kontrolünü elle tekrar çalıştırır.
     *
     * Kullanıcı anahtarı düzeltti veya mağaza tekrar ayağa kalktı; panelden
     * doğrulamanın yolu bu. Bağlantı iyileşirse `active` olur.
     */
    public function health(
        string $connection,
        CheckChannelHealth $checkHealth,
    ): RedirectResponse {
        // Kiracı scope'u altında aranır: başka kiracının bağlantısı 404.
        $model = ChannelConnection::query()->findOrFail($connection);

        $checked = $checkHealth->run($model);

        return redirect('/channels')->with(
            ...$this->connectionFlash($checked),
        );
    }

    // ─────────────────────────────────────────────────── yardımcılar

    /**
     * Doğrulanmış değerlerden alan tanımının istediklerini seçer.
     *
     * Doğrudan `$fields` gönderilseydi bir alan yanlış kolona düşerdi:
     * sır `settings`'e, kimlik kasaya. Seçim TANIMDAN yapılır, istekten
     * değil.
     *
     * ⚠️ EKSİK ALAN `null` OLARAK TAŞINMAZ — ATLANIR.
     *
     * Bugün her tanımlı alan `required` olduğu için buraya eksik bir ad
     * GELEMEZ ve iki davranış AYNI sonucu verir; mutasyon turu bunu
     * gösterdi (fark hiçbir testte görünmedi). Ayrım yine de AÇIKÇA
     * yazılır çünkü eşitlik bir TESADÜFE dayanıyor: bir alan yarın
     * isteğe bağlı yapılırsa `?? null` sessizce NULL bir sırrı kasaya
     * yazar ve `access_token = null` ile giden her istek 401 alır —
     * `AUTHENTICATION` KALICI sayılır ve satır "anahtarın yanlış"
     * diyerek ölür, oysa anahtar HİÇ VERİLMEMİŞTİR (`97a7eb7` hata
     * biçimi). Kasadaki NULL, kimliğin YOKLUĞUNDAN daha kötüdür:
     * `ConnectChannel` boş `secrets`'ı hiç yazmaz, ama içi NULL dolu
     * bir dizi "kimlik var" gibi görünür.
     *
     * @param  array<string, mixed>  $values
     * @param  array<int, array<string, mixed>>  $definitions
     * @return array<string, mixed>
     */
    private function pick(array $values, array $definitions): array
    {
        $picked = [];

        foreach ($definitions as $field) {
            $name = $field['name'];

            // Yalnızca GERÇEKTEN gelen değer taşınır.
            if (isset($values[$name])) {
                $picked[$name] = $values[$name];
            }
        }

        return $picked;
    }

    /**
     * El sıkışmayı başlatır ve satıcıyı kanalın onay ekranına yollar.
     *
     * ⚠️ İŞ AYNI CONTROLLER'DA TEKRARLANMAZ, SAHİBİNE DEVREDİLİR.
     * PKCE sırlarının üretimi, oturuma yazılması ve yetkilendirme
     * adresinin kurulması `EtsyOAuthController::redirect()` içinde
     * yaşar; kopyalansaydı iki yer ayrışır ve biri `state`'i oturuma
     * yazmayı unuttuğunda P0-10'un koruduğu doğrulama SESSİZCE devre
     * dışı kalırdı.
     *
     * ⚠️ KANAL ADI UYDURULMAZ. Eşleme kanal eklendikçe BÜYÜR ve tanımsız
     * kanal AÇIKÇA reddedilir — sessizce `/channels`'a dönseydi satıcı
     * bağlantısını kurulmuş sanar ve hiç yetkilendirmezdi.
     *
     * ⚠️ İKİ AKIŞ AYRI CONTROLLER'DADIR ve BİRLEŞTİRİLMEZ. Yüzeyde
     * benzerler ama eBay'de PKCE YOKTUR, istemci kimliği `Authorization:
     * Basic` başlığındadır, gövde form-encoded gider ve `client_id`
     * kasadadır (§13.3). Ortak bir "OAuth controller"a sıkıştırılsalardı
     * o sınıf kanal adına bakan dallarla dolar ve tam olarak bu projenin
     * yasakladığı biçime dönerdi.
     */
    private function startOauthHandshake(
        Request $request,
        string $channelTypeCode,
        ChannelConnection $connection,
    ): Response|RedirectResponse {
        return match ($channelTypeCode) {
            'etsy' => app(EtsyOAuthController::class)->redirect($request, $connection->id),
            'ebay' => app(EbayOAuthController::class)->redirect($request, $connection->id),
            default => throw new \LogicException(
                "`{$channelTypeCode}` OAuth kullandığını bildiriyor ama "
                .'yetkilendirme akışı tanımlı değil.'
            ),
        };
    }

    /**
     * Sağlık sonucuna göre flash mesajı.
     *
     * @return array{0: string, 1: string}
     */
    private function connectionFlash(ChannelConnection $connection): array
    {
        if ($connection->health_status === 'healthy') {
            return ['success', "{$connection->label} bağlandı ve kanal cevap veriyor."];
        }

        // Hata METNİ burada TEKRARLANMAZ: bağlantı kartı onu zaten gösteriyor.
        // Uzun bir cURL mesajını iki yerde göstermek asıl eylemi ("anahtarları
        // kontrol et") okunmaz hale getiriyordu.
        return ['warning', sprintf(
            '%s kaydedildi ama kanal cevap vermedi — bağlantı beklemede. '.
            'Aşağıdaki hataya bakıp anahtarları kontrol edin.',
            $connection->label,
        )];
    }

    /**
     * Panele gönderilen alanlar — kimlik bilgisi ve `settings` YOK.
     *
     * @return array<string, mixed>
     */
    private function presentConnection(ChannelConnection $connection): array
    {
        $expiresAt = $connection->activeCredential?->expires_at;
        $tokenStatus = TokenStatus::forExpiry($expiresAt);

        return [
            'id' => $connection->id,
            'label' => $connection->label,
            'channel' => $connection->channelType?->name ?? $connection->channel_type_code,
            'channelTypeCode' => $connection->channel_type_code,
            // Mağaza kimliği alan adıdır; sır değil ve kullanıcı onu tanır.
            'account' => $connection->external_account_id,
            'status' => $connection->status,
            'health' => $connection->health_status,
            'lastHealthyAt' => $connection->last_healthy_at?->toIso8601String(),
            'lastError' => $connection->last_error,
            'connectedAt' => $connection->connected_at?->toIso8601String(),
            'capabilities' => $this->capabilitiesOrEmpty($connection),

            // §25 · TOKEN ROZETİ — `status`'tan AYRI bir sorudur.
            //
            // `status` kanalın SON cevabını taşır; token ömrü bugün
            // çalışan bir bağlantıda bile yarın dolabilir ve o an hiçbir
            // kolon değişmez. İkisi tek alanda birleştirilseydi ya
            // çalışan bağlantı "bozuk" gösterilir ya da ölmek üzere olan
            // token HİÇ görünmezdi.
            //
            // ⚠️ YALNIZCA DURUM VE TARİH GİDER — kimlik bilgisinin
            // kendisi ASLA (§19 · madde 3): `channel_credentials`
            // şifreli kasadır ve bu dizi Inertia prop'u olarak
            // TARAYICIYA ulaşır.
            'tokenStatus' => $tokenStatus?->value,
            'tokenStatusLabel' => $tokenStatus?->label(),
            'tokenExpiresAt' => $expiresAt?->toIso8601String(),
        ];
    }

    /**
     * Yetenek haritası; adapter kurulamıyorsa boş.
     *
     * Bozuk bir adapter sınıfı tüm listeyi 500'e düşürmemeli — bağlantıyı
     * görmek tam da onu düzeltmek için gerekiyor.
     *
     * @return array<string, bool>
     */
    private function capabilitiesOrEmpty(ChannelConnection $connection): array
    {
        try {
            return $this->registry->capabilitiesFor($connection);
        } catch (Throwable $e) {
            // SESSİZCE YUTULMAZ. Bu catch bir görüntüleme korumasıdır, hata
            // gizleme aracı değil: adapter kurulamamasının gerçek nedeni
            // (eksik kolon, tanımsız sınıf) günlüğe yazılır. Geliştirme
            // sırasında tam bu satır, eager-load'da adapter_class'ın
            // seçilmediğini görmemi engellemişti.
            Log::warning('channels.capabilities_unavailable', [
                'connection' => $connection->id,
                'channel_type' => $connection->channel_type_code,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function activeChannelTypes(): array
    {
        return ChannelType::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['code', 'name', 'kind'])
            ->map(fn (ChannelType $type): array => [
                'code' => $type->code,
                'name' => $type->name,
                'kind' => $type->kind,
                // ⚠️ ALAN TANIMI EKRANA GİDER — Vue'da `if (code ===
                // '...')` YAZILMAZ. Yazılsaydı o blok sunucudaki
                // doğrulamadan AYRI yaşar ve biri değiştiğinde form
                // alanı sorar ama doğrulama reddederdi (ya da tersi).
                //
                // `connectable` ARTIK BİR TANIMIN VARLIĞIDIR: kanal
                // `is_active = true` yapılıp form tanımı unutulursa
                // satıcı sebebi görür — `PanelConnectSupport`'un dürüst
                // uyarısının KALICI hâli.
                ...ChannelConnectForm::present($type->code),
            ])
            ->all();
    }

    /**
     * Bu istek GERÇEKTEN yeni bir bağlantı mı açacak?
     *
     * `ConnectChannel` hesabı `(channel_type_code, external_account_id)`
     * ile arar ve bulursa YENİDEN KULLANIR. Kota yalnızca satır sayısını
     * ARTIRACAK istekte uygulanır; anahtar yenileme kotadan etkilenmez.
     *
     * Adres AYNI NORMALLEŞTİRME ile çözülür (`StoreUrl`): şema ve sondaki
     * eğik çizgi atılmadan karşılaştırılsaydı `https://magaza.com/` ile
     * `magaza.com` farklı sanılır ve yeniden bağlama kotaya takılırdı.
     * Ayrıştırılamayan adres burada SESSİZCE geçilir — hatayı
     * `ConnectChannel` zaten alan hatasına çevirir ve iki yerde
     * doğrulamak mesajı ikiye böler.
     */
    private function wouldAddNewConnection(string $channelTypeCode, string $storeUrl): bool
    {
        try {
            $host = StoreUrl::parse($storeUrl)->host;
        } catch (\InvalidArgumentException) {
            return true;
        }

        return ! ChannelConnection::query()
            ->where('channel_type_code', $channelTypeCode)
            ->where('external_account_id', $host)
            ->exists();
    }

    private function isAccountUniquenessViolation(Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'channel_connections_account_unique');
    }
}
