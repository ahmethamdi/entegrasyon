<?php

declare(strict_types=1);

namespace App\Domain\Channels\Contracts;

use App\Domain\Sync\Enums\ErrorClass;

/**
 * Adapter çağrısının sonucu.
 *
 * Mimari Karar Dokümanı v2.2 · §7 · Adapter yan etkisizdir.
 *
 * DEĞİŞMEZ KURAL: adapter veritabanına YAZMAZ, kuyruğa iş ATMAZ, durum
 * GÜNCELLEMEZ. Girdi alır, kanalla konuşur, bu nesneyi döner. Durumu
 * SyncResultRecorder yazar.
 *
 * Bu ayrım olmadan her adapter kendi durum yazma mantığını taşır ve
 * "hangi adapter sync state'i nasıl güncelliyor" sorusu cevapsız kalırdı.
 */
final readonly class AdapterResult
{
    /**
     * @param  array<string, mixed>  $data  Kanaldan dönen faydalı veri (external_id vb.)
     * @param  int|null  $retryAfter  Kanalın Retry-After başlığı, saniye
     * @param  array<string, string>  $failedOperations  Operasyon kimliği → hata metni
     */
    private function __construct(
        public bool $successful,
        public array $data = [],
        public ?ErrorClass $errorClass = null,
        public ?string $errorMessage = null,
        public ?int $retryAfter = null,
        public array $failedOperations = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function success(array $data = []): self
    {
        return new self(successful: true, data: $data);
    }

    /**
     * KISMİ BAŞARI — bazı kalemler geçti, bazıları geçmedi.
     *
     * V3.0 · §13.4 (eBay `bulk_update_price_quantity`) · v2.2 §8.
     *
     * ═════════════════════════════════════════════════════════════════
     * NEDEN AYRI BİR SONUÇ BİÇİMİ — İKİSİ DE YANLIŞ CEVAP VERİRDİ
     * ═════════════════════════════════════════════════════════════════
     * Toplu uç noktaların bir kısmı 200 döner ama gövdede KALEM BAŞINA
     * durum taşır (eBay `responses[]`, her biri kendi `statusCode`'uyla).
     * O gövde tek bir "başarılı/başarısız" cevabına indirgenemez:
     *
     *   `success()` dönseydi → başarısız kalemler "senkron" damgası yer,
     *     `synced_version` ilerler ve stok kanalda YANLIŞ kalır. Mutabakat
     *     onu bir gün yakalar ama arada satış olur.
     *   `failure()` dönseydi → GEÇEN kalemler de yeniden denenir. Stok
     *     mutlak değer olduğu için tekrar zararsızdır, ama KALICI hatalı
     *     tek bir kalem (silinmiş offer) partinin tamamını sonsuza kadar
     *     `error_permanent`'a sürükler ve sağlam ürünlerin stoğu da
     *     yanlış kalır.
     *
     * ⚠️ EŞLEŞTİRME OPERASYON KİMLİĞİYLE YAPILIR, SIRAYLA DEĞİL. Kanalın
     * `responses[]` dizisi gönderdiğimiz sırayı KORUMAYABİLİR; konumla
     * eşleştirilseydi bir kalemin hatası BAŞKA bir operasyona yazılır ve
     * iki satır birden yanlış olurdu (`HepsiburadaEndpoints`'in "yer
     * tutucu ADIYLA doldurulur" kuralının sonuç tarafındaki karşılığı).
     *
     * ⚠️ SONUÇ YİNE DE `successful`'DIR. Çağıran (`PushInventory`) devre
     * kesiciye BAŞARI yazar: kanal cevap verdi ve çağrıların çoğu geçti —
     * altyapı sağlıklıdır. Başarısızlık KALEM seviyesindedir ve devreyi
     * açmak, çalışan bir kanalı kapatmak olurdu.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $failedOperations  Operasyon kimliği → hata metni
     */
    public static function partial(
        array $failedOperations,
        array $data = [],
        ?ErrorClass $errorClass = null,
    ): self {
        return new self(
            successful: true,
            data: $data,
            errorClass: $errorClass,
            failedOperations: $failedOperations,
        );
    }

    /** Kalem seviyesinde başarısızlık taşıyor mu? */
    public function hasFailedOperations(): bool
    {
        return $this->failedOperations !== [];
    }

    public static function failure(
        ErrorClass $errorClass,
        ?string $message = null,
        ?int $retryAfter = null,
    ): self {
        return new self(
            successful: false,
            errorClass: $errorClass,
            errorMessage: $message,
            retryAfter: $retryAfter,
        );
    }

    public function failed(): bool
    {
        return ! $this->successful;
    }

    /** Bu sonuç kullanıcı müdahalesi gerektiriyor mu? */
    public function isPermanentFailure(): bool
    {
        return $this->failed() && $this->errorClass?->isPermanent() === true;
    }
}
