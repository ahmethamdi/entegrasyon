<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

use App\Domain\Channels\Contracts\AdapterResult;
use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Sync\Enums\ErrorClass;
use RuntimeException;
use Throwable;

/**
 * Adapter'ın İSTİSNA FIRLATMADAN döndürdüğü başarısızlık.
 *
 * Mimari Karar Dokümanı v2.2 · §7 · "yazılmamış/başarısız yetenek sessizce
 * başarılı dönmez".
 *
 * ═════════════════════════════════════════════════════════════════════
 * NEDEN VAR — İKİ HATA YOLU, ÇEKİRDEK YALNIZCA BİRİNİ DİNLİYORDU
 * ═════════════════════════════════════════════════════════════════════
 * Adapter başarısızlığı iki biçimde bildirir: istisna fırlatır VEYA
 * `AdapterResult::failure()` döner (Shopify "inventory item kimliği yok",
 * Etsy "envanter boş okundu", eBay "pazar para birimi bilinmiyor"…).
 * Push işleri yalnızca istisnayı yakalıyordu; dönen sonuç `failed()`
 * olsa bile `recordSuccess` çağrılır, operasyon COMPLETED olur,
 * `synced_version` ilerler ve satır kanala HİÇBİR ŞEY gitmemişken
 * "senkron" görünürdü — projenin en pahalı hata biçimi.
 *
 * Çözüm sonucu İSTİSNA YOLUNA SOKMAKTIR: iş başına ikinci bir hata dalı
 * yazmak yerine sonuç bu istisnaya çevrilir ve mevcut `catch` bloğu
 * (kayıt → devre kesici → RetryPolicy → markDead) olduğu gibi çalışır.
 *
 * ⚠️ SINIF ADAPTER'IN SÖYLEDİĞİDİR, `classifyError()` YENİDEN SORULMAZ.
 * Adapter sınıfı zaten verdi; istisnayı ona geri sormak `RuntimeException`
 * görür ve çoğu adapter onu `NETWORK` (geçici) sayar — kalıcı bir
 * VALIDATION hatası boşuna beş kez denenirdi.
 */
final class AdapterReportedFailure extends RuntimeException
{
    private function __construct(
        public readonly ErrorClass $errorClass,
        string $message,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Sonuç başarısızsa fırlatır, değilse sonucu olduğu gibi döner.
     *
     * Kısmi başarı (`partial`) BURADA FIRLATMAZ — o sonuç `successful`'dır
     * ve kalem seviyesindeki hatalar `recordSuccess` + `markDead` ile
     * ayrıca işlenir (§13.4).
     */
    public static function throwIfFailed(AdapterResult $result): AdapterResult
    {
        if (! $result->failed()) {
            return $result;
        }

        throw new self(
            // Sınıfsız başarısızlık bilinmeyen hatadır; geçici sayılır ve
            // bütçe (MAX_ATTEMPTS) tükenince ölür — kalıcı sayılsaydı tek
            // seferlik bir aksaklık satırı öldürürdü.
            $result->errorClass ?? ErrorClass::SERVER_ERROR,
            $result->errorMessage ?? 'Kanal işlemi başarısız bildirdi (ayrıntı yok).',
            $result->retryAfter,
        );
    }

    /**
     * İşin `catch` bloğundaki sınıf: adapter'ın bildirdiği sonuçsa ONUN
     * sınıfı, gerçek istisnaysa `classifyError()` (§7 · sınıflandırma
     * adapter'da, karar çekirdekte).
     */
    public static function classify(Throwable $e, ChannelAdapter $adapter): ErrorClass
    {
        return $e instanceof self ? $e->errorClass : $adapter->classifyError($e);
    }

    /** Kanalın bildirdiği bekleme süresi — yalnızca dönen sonuçta bilinir. */
    public static function retryAfterOf(Throwable $e): ?int
    {
        return $e instanceof self ? $e->retryAfter : null;
    }
}
