<?php

declare(strict_types=1);

namespace App\Domain\Channels\Adapters\Shopify;

use App\Domain\Channels\Contracts\CarriesRetryAfter;
use RuntimeException;

/**
 * GraphQL yanıtı 200 döndü ama İŞ BAŞARISIZ.
 *
 * V3.0 · §06.3 · P0-1 · T-V3-11.
 *
 * ─────────────────────────────────────────────────────────────────────
 * BU İSTİSNANIN VARLIK SEBEBİ — PROJENİN EN PAHALI HATA BİÇİMİ
 * ─────────────────────────────────────────────────────────────────────
 * REST'te hata HTTP kodudur ve `$response->throw()` onu yakalar.
 * GraphQL'de **HER ŞEY 200'DÜR**; hata gövdede `errors` (taşıma/şema) veya
 * `userErrors` (iş kuralı) altında yaşar ve `throw()` onları GÖRMEZ.
 *
 * Kontrol edilmezse: `SyncResultRecorder` BAŞARI yazar, `synced_version`
 * ilerler ve **kanalda hiçbir şey değişmemişken satır "senkron" görünür.**
 * Mutabakat turu farkı bulur, onarım açar, o da aynı sessiz başarıyla
 * döner — sonsuz onarım döngüsü. Woo'nun `manage_stock` tuzağının aynısı.
 *
 * ─────────────────────────────────────────────────────────────────────
 * İSTİSNA FIRLATILIR, `AdapterResult::failure()` DÖNÜLMEZ
 * ─────────────────────────────────────────────────────────────────────
 * v2.2 kuralı: adapter başarısızlıkta İSTİSNA fırlatır; sınıflandırma ve
 * yeniden deneme kararı çağıran taraftaki tek `try/catch`'te toplanır.
 *
 * SINIFLANDIRMA `ShopifyAdapter::classifyError()` İÇİNDE: `userErrors`
 * bir İŞ KURALI ihlalidir ve `VALIDATION` yani KALICIDIR — yeniden denemek
 * aynı sonucu verir ve kotayı boşa harcar.
 */
final class ShopifyGraphqlException extends RuntimeException implements CarriesRetryAfter
{
    /**
     * Shopify'ın 200 gövdesinde GEÇİCİ olduğunu bildirdiği taşıma hataları.
     *
     * ⚠️ GRAPHQL'DE HIZ SINIRI DA 200 DÖNER: `errors[].extensions.code =
     * THROTTLED`. Bu kodlar tanınmasaydı her taşıma hatası gibi KALICI
     * (`VALIDATION`) sayılır ve toplu bir stok/fiyat turunda yüzlerce
     * listing yalnızca kova boşaldı diye "düzeltilemez" damgasıyla ölürdü.
     */
    public const THROTTLED = 'THROTTLED';

    public const INTERNAL_SERVER_ERROR = 'INTERNAL_SERVER_ERROR';

    /**
     * @param  string  $operation  Hangi mutation/query — hata mesajında görünür
     * @param  list<array<string, mixed>>  $errors  Kanalın döndürdüğü ham hata listesi
     * @param  bool  $isUserError  true → iş kuralı (`userErrors`), false → taşıma (`errors`)
     * @param  int|null  $retryAfter  Kova dolana kadar beklenecek saniye (yalnızca THROTTLED)
     */
    public function __construct(
        public readonly string $operation,
        public readonly array $errors,
        public readonly bool $isUserError = false,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct(sprintf(
            'Shopify GraphQL %s başarısız (%s): %s',
            $operation,
            $isUserError ? 'userErrors' : 'errors',
            self::summarize($errors),
        ));
    }

    public function retryAfterSeconds(): ?int
    {
        return $this->retryAfter;
    }

    /** Taşıma hatası bu koda sahip mi? `userErrors` hiçbir zaman eşleşmez. */
    public function hasCode(string $code): bool
    {
        if ($this->isUserError) {
            return false;
        }

        foreach ($this->errors as $error) {
            if (($error['extensions']['code'] ?? null) === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hata listesini tek satırlık okunur metne indirger.
     *
     * MESAJ KALICI YAZILIR (`sync_attempts.error_message`) ve panele gider;
     * `ChannelErrorText` onu ayrıca maskeler. Burada yalnızca OKUNUR hâle
     * getirilir — ham dizi basılsaydı satıcı `Array` görürdü.
     *
     * @param  list<array<string, mixed>>  $errors
     */
    private static function summarize(array $errors): string
    {
        $messages = [];

        foreach ($errors as $error) {
            $message = $error['message'] ?? null;

            if (! is_string($message) || $message === '') {
                continue;
            }

            // Alan yolu varsa eklenir: satıcıya HANGİ alanın sorunlu
            // olduğunu söyler — "geçersiz değer" tek başına ne yapacağını
            // söylemez (§12 · ölü mektup ekranı kuralının aynısı).
            $field = $error['field'] ?? null;

            $messages[] = is_array($field) && $field !== []
                ? implode('.', array_map('strval', $field)).': '.$message
                : $message;
        }

        return $messages === [] ? '(mesaj yok)' : implode(' · ', $messages);
    }
}
