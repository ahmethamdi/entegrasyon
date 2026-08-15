<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Sync\Enums\ErrorClass;
use Throwable;

/**
 * Tüm adapter'ların tabanı — KASTEN İNCE.
 *
 * Mimari Karar Dokümanı v2.2 · §7 · Adapter Architecture, §1 · Karar 19.
 *
 * YETENEK ARAYÜZLERİ, TEK ŞİŞMAN ADAPTER DEĞİL:
 *   Her kanal her şeyi yapmaz. WooCommerce'te taksonomi ve onay süreci yoktur;
 *   Trendyol'da vardır. Tek arayüzde toplansaydı yarısı `throw new
 *   NotSupportedException` ile dolardı ve "bu kanal bunu yapabilir mi"
 *   sorusunun cevabı çalışma zamanına ertelenirdi. `instanceof Supports*`
 *   ile cevap tip sisteminde durur.
 *
 * DEĞİŞMEZ KURAL — ADAPTER YAN ETKİSİZDİR:
 *   Veritabanına yazmaz, kuyruğa iş atmaz, durum güncellemez. Girdi alır,
 *   kanalla konuşur, sonuç nesnesi döner.
 *
 * DEĞİŞMEZ KURAL — ASLA PAYLAŞILMAZ:
 *   AdapterRegistry::for() her çağrıda yeni örnek üretir; container'da
 *   singleton bağlama YASAKTIR. Adapter bağlantı taşır ve paylaşılan örnek,
 *   aynı worker sürecinde kiracı A'nın bağlantısını kiracı B'nin işinde
 *   kullanır.
 *
 * SupportsWebhooks arayüzü YOKTUR: webhook bir yetenek değil taşıma biçimidir.
 * Her adapter imza doğrular ve olay ayrıştırır; kanal webhook desteklemiyorsa
 * yoklama devreye girer. Bu bilgi channel_types.supports_webhooks bayrağında.
 */
interface ChannelAdapter
{
    public function connection(): ChannelConnection;

    public function healthCheck(): HealthResult;

    /**
     * Kanal hatasını çekirdeğin anladığı sınıfa çevirir.
     *
     * Kanalın hata gövdesini yalnızca adapter anlar; ne yapılacağına
     * (yeniden dene / kalıcı hata / mutabakata devret) çekirdek karar verir.
     */
    public function classifyError(Throwable $e): ErrorClass;

    /**
     * Hız sınırı profili.
     *
     * Varsayılan olarak channel_types.rate_limit_profile okunur; adapter
     * hesaba özgü bilgiyle (yanıt başlıkları, satıcı seviyesi) çalışma
     * zamanında geçersiz kılabilir.
     */
    public function rateLimitProfile(): RateLimitProfile;

    /**
     * Webhook imzasını HAM gövde üzerinden doğrular.
     *
     * JSON AYRIŞTIRILMADAN ÖNCE çağrılır: ayrıştırma ve yeniden serileştirme
     * baytları değiştirir ve imza tutmaz.
     *
     * @param  array<string, array<int, string|null>>  $headers
     */
    public function verifyWebhookSignature(string $raw, array $headers): bool;

    /**
     * Kanalın olay kimliği — inbox tekilleştirmesinin çıpası.
     *
     * @param  array<string, array<int, string|null>>  $headers
     */
    public function extractEventId(array $headers): ?string;

    /** @param array<string, array<int, string|null>> $headers */
    public function extractEventType(array $headers): string;
}
