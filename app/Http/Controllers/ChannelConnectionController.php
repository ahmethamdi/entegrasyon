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
use App\Domain\Channels\Support\PanelConnectSupport;
use App\Domain\Channels\Support\StoreUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
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
    public function store(Request $request, ConnectChannel $connect): RedirectResponse
    {
        $validated = $request->validate([
            'channel_type_code' => [
                'required', 'string',
                Rule::exists('channel_types', 'code')->where('is_active', true),
            ],
            'label' => ['required', 'string', 'max:120'],
            'store_url' => ['required', 'string', 'max:255'],
            'consumer_key' => ['required', 'string', 'max:255'],
            'consumer_secret' => ['required', 'string', 'max:255'],
        ]);

        // ⚠️ KAPI SUNUCUDA DA VAR — PANEL TEK SAVUNMA DEĞİLDİR.
        //
        // Yalnızca formda gizlenseydi doğrudan POST atan bir istek Etsy
        // bağlantısını Woo anahtarlarıyla kasaya YAZARDI: satır
        // `pending` kalır ama kasada anlamsız bir sır durur ve satıcı
        // bağlantıyı "kurulmuş" sanardı.
        $reason = PanelConnectSupport::reasonFor($validated['channel_type_code']);

        if ($reason !== null) {
            throw ValidationException::withMessages(['channel_type_code' => $reason]);
        }

        // Plan kotası (§13 · Faz 4) — YALNIZCA GERÇEKTEN YENİ mağazada.
        //
        // `ConnectChannel` aynı hesabı `firstOrNew` ile yeniden kullanır
        // ve bu, ANAHTAR YENİLEME akışıdır: yeni bağlantı eklemez.
        // Ayrım yapılmasaydı kotası dolu bir satıcı süresi dolmuş
        // anahtarını güncelleyemez ve kanalı KALICI olarak ölürdü —
        // üstelik tam da ödeme yapmasını istediğimiz anda.
        if ($this->wouldAddNewConnection($validated['channel_type_code'], $validated['store_url'])) {
            try {
                app(EnforceQuota::class)->check(QuotaMetric::CHANNELS);
            } catch (QuotaExceededException $e) {
                throw ValidationException::withMessages(['store_url' => $e->userMessage()]);
            }
        }

        try {
            $connection = $connect->run(
                channelTypeCode: $validated['channel_type_code'],
                label: $validated['label'],
                storeUrl: $validated['store_url'],
                secrets: [
                    'consumer_key' => $validated['consumer_key'],
                    'consumer_secret' => $validated['consumer_secret'],
                ],
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
                // ⚠️ "AÇIK" İLE "BAĞLANABİLİR" AYRI ŞEYLERDİR. Kanal
                // listede görünür ama form onun kimlik biçimini
                // soramıyorsa satıcı OLMAYAN bir anahtarı arar ve
                // bulamayınca rastgele bir değer girer; sağlık kontrolü
                // 401 alır ve sebep "anahtarın yanlış" gibi görünür.
                'connectable' => PanelConnectSupport::isConnectable($type->code),
                'unavailable_reason' => PanelConnectSupport::reasonFor($type->code),
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
