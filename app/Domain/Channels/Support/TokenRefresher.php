<?php

declare(strict_types=1);

namespace App\Domain\Channels\Support;

use App\Domain\Channels\Contracts\SupportsTokenRefresh;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Sync\Support\ChannelErrorText;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Süresi dolmak üzere olan kanal token'larını yeniler.
 *
 * V3.0 · §03 · Delta 3 · §20 · P0-5 · T-V3-15.
 *
 * ⚠️ DEĞİŞMEZ KURAL — YENİLEME İSTEK ANINDA DEĞİL, TARAMAYLA YAPILIR.
 *   İstek anında yenilenseydi aynı bağlantı için paralel koşan iki iş aynı
 *   anda yeniler, ikisi de yeni token alır ve KANAL İLKİNİ İPTAL EDER
 *   (Etsy ve eBay'de refresh token TEK KULLANIMLIKTIR). Sonuç: her iki iş
 *   de elinde geçersiz token'la kalır ve bağlantı ölür.
 *
 *   Koruma İKİ KATMANLIDIR ve ikisi de gereklidir:
 *     1. `withoutOverlapping` — aynı komutun ikinci kopyası hiç başlamaz
 *     2. `FOR UPDATE SKIP LOCKED` — çok sunuculu kurulumda iki tur aynı
 *        satırı ALAMAZ; ikincisi o satırı ATLAR, beklemez
 *
 *   `SKIP LOCKED` yerine düz `FOR UPDATE` kullanılsaydı ikinci tur birincinin
 *   bitmesini BEKLER ve ardından AYNI satırı ikinci kez yenilerdi — tam
 *   kaçınmak istediğimiz şey.
 *
 * SIKLIK EN KISA TTL'İN DÖRTTE BİRİDİR (Etsy 1 saat → 15 dk, §20). Yarısı
 * seçilseydi tek bir başarısız tur token'ın ölmesine yeterdi; dörtte bir
 * ÜÇ DENEME HAKKI verir.
 *
 * `runAsSystem()` İLE TÜM KİRACILARI GÖRÜR — bütünlük taramalarıyla aynı
 * gerekçe. Bağlam altında koşsaydı yalnızca tek kiracının token'ları
 * yenilenir ve geri kalanı sessizce ölürdü.
 *
 * BAŞARISIZ YENİLEME BAĞLANTIYI ÖLDÜRMEZ, İŞARETLER: `last_error` yazılır ve
 * tarama sonraki turda yeniden dener (§20). `revoked_at` yalnızca kanal "bu
 * token geçersiz" dediğinde yazılır — bunu adapter'ın hata sınıflandırması
 * belirler, tarama kendiliğinden karar vermez.
 */
final class TokenRefresher
{
    public function __construct(
        private readonly AdapterRegistry $registry,
        private readonly CredentialVault $vault,
        private readonly ChannelErrorText $errorText,
    ) {}

    /**
     * Bir tur çalışır ve yenilenen bağlantı sayısını döner.
     *
     * @return array{refreshed: int, failed: int, skipped: int}
     */
    public function run(int $limit = 100): array
    {
        return TenantContext::runAsSystem(function () use ($limit): array {
            $refreshed = 0;
            $failed = 0;
            $skipped = 0;

            foreach ($this->dueConnectionIds($limit) as $connectionId) {
                $outcome = $this->refreshOne($connectionId);

                match ($outcome) {
                    'refreshed' => $refreshed++,
                    'failed' => $failed++,
                    default => $skipped++,
                };
            }

            return ['refreshed' => $refreshed, 'failed' => $failed, 'skipped' => $skipped];
        });
    }

    /**
     * Yenilenmesi gereken bağlantıların kimlikleri.
     *
     * SORGU `channel_credentials` ÜZERİNDEN GİDER çünkü süre bilgisi orada
     * yaşar ve v2.2 §4 tam bu sorgu için `INDEX(expires_at) WHERE revoked_at
     * IS NULL` kısmi indeksini tanımlamıştır — bugüne kadar hiç kullanılmadı,
     * V3 onu kullanan ilk fazdır (§16 · DB Delta 2).
     *
     * `expires_at IS NULL` OLAN SATIR ADAY DEĞİLDİR: Woo, Trendyol ve
     * Hepsiburada kalıcı anahtar taşır ve süre bilgisi yazmaz. Aday
     * sayılsaydı her tur o bağlantıları da gezerdi ve `SupportsTokenRefresh`
     * uygulamadıkları için hepsi atlanırdı — boşuna kilit, boşuna sorgu.
     *
     * PAY (`lead`) BURADA SABİT DEĞİLDİR: adapter'ın `refreshLeadSeconds()`
     * değeri kanala göre değişir ama sorgu tek seferde çalışmalıdır. Bu
     * yüzden sorgu EN GENİŞ payı kullanır ve kesin karar `refreshOne()`
     * içinde, adapter okunduktan sonra verilir. Ters yapılsaydı ya kanal
     * başına ayrı sorgu atılırdı ya da dar paylı kanal geç yenilenirdi.
     *
     * @return list<string>
     */
    private function dueConnectionIds(int $limit): array
    {
        $rows = DB::table('channel_credentials')
            ->whereNull('revoked_at')
            ->whereNotNull('expires_at')
            ->whereRaw('expires_at <= clock_timestamp() + (? * interval \'1 second\')', [self::WIDEST_LEAD_SECONDS])
            ->orderBy('expires_at')
            ->limit($limit)
            ->pluck('channel_connection_id');

        return $rows->map(fn ($id): string => (string) $id)->all();
    }

    /**
     * Tek bağlantıyı yeniler — KİLİT ALTINDA.
     *
     * Transaction ve `FOR UPDATE SKIP LOCKED` bu metottadır: kilit yalnızca
     * ilgili satırı kapsar ve tur boyunca değil, bağlantı başına tutulur.
     * Tüm turu tek transaction'a sarmak, ilk bağlantının kilidini son
     * bağlantı bitene kadar tutardı ve ağ çağrıları saniyeler sürer.
     *
     * @return 'refreshed'|'failed'|'skipped'
     */
    private function refreshOne(string $connectionId): string
    {
        return DB::transaction(function () use ($connectionId): string {
            // KİLİT: ikinci tur bu satırı ATLAR, BEKLEMEZ.
            //
            // `lockForUpdate()` KULLANILAMAZ — o düz `FOR UPDATE` üretir ve
            // ikinci tur birincinin bitmesini BEKLEYİP ardından AYNI satırı
            // İKİNCİ KEZ yeniler; tam olarak kaçınmak istediğimiz şey.
            // Laravel'in `skipLocked()` diye bir metodu YOKTUR; ham kilit
            // ifadesi `lock()` ile verilir.
            $locked = DB::table('channel_credentials')
                ->where('channel_connection_id', $connectionId)
                ->whereNull('revoked_at')
                ->lock('for update skip locked')
                ->first();

            if ($locked === null) {
                // Başka bir tur bu satırı almış ya da arada iptal edilmiş.
                return 'skipped';
            }

            /** @var ChannelConnection|null $connection */
            $connection = ChannelConnection::query()
                ->with('channelType:code,name,adapter_class')
                ->find($connectionId);

            if ($connection === null) {
                return 'skipped';
            }

            $adapter = $this->registry->for($connection);

            // Yeteneği OLMAYAN kanal SESSİZCE atlanır — istisnaya bırakılsaydı
            // her tur, kalıcı anahtar taşıyan her bağlantı için bir uyarı
            // satırı yazar ve gerçek arızalar gürültüde kaybolurdu
            // (`ReconcileActiveConnections` kuralının aynısı).
            if (! $adapter instanceof SupportsTokenRefresh) {
                return 'skipped';
            }

            // KESİN PAY KARARI: sorgu en geniş payı kullandı, gerçek eşik
            // adapter'ındır. Henüz vakti gelmemişse dokunulmaz.
            $expiresAt = $locked->expires_at === null ? null : new \DateTimeImmutable((string) $locked->expires_at);

            if ($expiresAt !== null
                && $expiresAt->getTimestamp() > time() + $adapter->refreshLeadSeconds()) {
                return 'skipped';
            }

            try {
                $fresh = $adapter->refreshCredentials();
            } catch (Throwable $e) {
                // BAĞLANTI ÖLDÜRÜLMEZ, İŞARETLENİR: sonraki tur yeniden dener.
                // Hata metni MASKELENİR — kanal 401 gövdesinde anahtarı
                // yansıtabilir ve `last_error` panele Inertia prop'u olarak
                // gider (v2.2 · `ChannelErrorText` kuralı).
                $connection->forceFill([
                    'last_error' => $this->errorText->redact($connection, $e->getMessage()),
                ])->save();

                Log::warning('credentials.refresh_failed', [
                    'connection_id' => $connection->id,
                    'channel' => $connection->channel_type_code,
                ]);

                return 'failed';
            }

            $this->vault->store(
                $connection,
                $fresh->secrets,
                $fresh->scope ?? $locked->scope,
                $fresh->expiresAt,
            );

            return 'refreshed';
        });
    }

    /**
     * Sorgunun kullandığı EN GENİŞ pay.
     *
     * Kanal başına gerçek pay `refreshLeadSeconds()`'tan gelir; bu sabit
     * yalnızca aday havuzunu daraltır. eBay'in 2 saatlik token'ı en geniş
     * payı ister, o yüzden bir saat seçildi — dar tutulsaydı geniş paylı
     * kanal sorgudan hiç dönmez ve HİÇ yenilenmezdi.
     */
    private const WIDEST_LEAD_SECONDS = 3600;
}
