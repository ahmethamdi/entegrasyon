<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

/**
 * Kanaldaki asenkron toplu işin durumu — `SupportsBatchStatus` dönüşü.
 *
 * ÜÇ DURUM:
 *   pending    kanal hâlâ işliyor; yeniden yoklanır
 *   completed  sonuç belli; satırlar yazılır, iş bir daha yoklanmaz
 *   expired    kanal işi artık tanımıyor (404 / saklama süresi doldu);
 *              hiçbir satıra hüküm YAZILMAZ — bilinmeyen sonuç red değildir
 *
 * SATIR SONUCU KİMLİKLE EŞLENİR (`outcomeFor`): anahtar push yükünde kanala
 * giden kimliktir (listing `external_id`). Kanalın satır sırası gönderim
 * sırasını korumayabilir; konumla eşleştirme bir satırın hatasını BAŞKA bir
 * ürüne yazardı (`AdapterResult::partial` kuralının aynısı).
 *
 * YALNIZ HATALARI LİSTELEYEN KANAL (Hepsiburada `errors[]`):
 *   `unlistedSucceeded = true` listede olmayan satırı başarılı sayar —
 *   AMA kimliği çözülemeyen tek bir hata (`unmatched`) bile varsa bu çıkarım
 *   YAPILMAZ: o hata hangi satıra aitse onu "başarılı" sayıp önceki hatasını
 *   silmek, sorunu panelden kaybettirirdi. O durumda listelenmeyenler
 *   `unknown` kalır.
 */
final readonly class BatchStatus
{
    public const PENDING = 'pending';

    public const COMPLETED = 'completed';

    public const EXPIRED = 'expired';

    public const OUTCOME_SUCCEEDED = 'succeeded';

    public const OUTCOME_FAILED = 'failed';

    public const OUTCOME_AWAITING_APPROVAL = 'awaiting_approval';

    public const OUTCOME_UNKNOWN = 'unknown';

    /**
     * @param  array<string, BatchItemFailure>  $failed  external_id → sebep
     * @param  list<string>  $succeeded  Kanalın AÇIKÇA başarılı dediği kimlikler
     * @param  array<string, string>  $awaitingApproval  external_id → kanal mesajı (Pazarama "Onaya gönderildi")
     * @param  list<string>  $unmatched  Kimliği çözülemeyen hata metinleri
     */
    private function __construct(
        public string $state,
        public array $failed = [],
        public array $succeeded = [],
        public array $awaitingApproval = [],
        public bool $unlistedSucceeded = false,
        public array $unmatched = [],
        public ?BatchItemFailure $batchFailure = null,
    ) {}

    public static function pending(): self
    {
        return new self(self::PENDING);
    }

    public static function expired(): self
    {
        return new self(self::EXPIRED);
    }

    /**
     * @param  array<string, BatchItemFailure>  $failed
     * @param  list<string>  $succeeded
     * @param  array<string, string>  $awaitingApproval
     * @param  list<string>  $unmatched
     */
    public static function completed(
        array $failed = [],
        array $succeeded = [],
        array $awaitingApproval = [],
        bool $unlistedSucceeded = false,
        array $unmatched = [],
    ): self {
        return new self(
            state: self::COMPLETED,
            failed: $failed,
            succeeded: array_values(array_unique($succeeded)),
            awaitingApproval: $awaitingApproval,
            unlistedSucceeded: $unlistedSucceeded,
            unmatched: $unmatched,
        );
    }

    /**
     * İŞİN TAMAMI reddedildi (N11 `REJECT`): satır listesi yoktur ve
     * yükteki HER satır aynı sebeple başarısızdır.
     */
    public static function rejected(BatchItemFailure $failure): self
    {
        return new self(state: self::COMPLETED, batchFailure: $failure);
    }

    public function isPending(): bool
    {
        return $this->state === self::PENDING;
    }

    public function isCompleted(): bool
    {
        return $this->state === self::COMPLETED;
    }

    public function isExpired(): bool
    {
        return $this->state === self::EXPIRED;
    }

    /**
     * Tek satırın hükmü — öncelik: iş reddi > satır hatası > onay bekliyor
     * > açık başarı > çıkarımla başarı > bilinmiyor.
     *
     * @return array{0: string, 1: BatchItemFailure|null}
     */
    public function outcomeFor(string $externalId): array
    {
        if ($this->batchFailure !== null) {
            return [self::OUTCOME_FAILED, $this->batchFailure];
        }

        if (isset($this->failed[$externalId])) {
            return [self::OUTCOME_FAILED, $this->failed[$externalId]];
        }

        if (isset($this->awaitingApproval[$externalId])) {
            return [self::OUTCOME_AWAITING_APPROVAL, new BatchItemFailure($this->awaitingApproval[$externalId])];
        }

        if (in_array($externalId, $this->succeeded, true)) {
            return [self::OUTCOME_SUCCEEDED, null];
        }

        if ($this->unlistedSucceeded && $this->unmatched === []) {
            return [self::OUTCOME_SUCCEEDED, null];
        }

        return [self::OUTCOME_UNKNOWN, null];
    }
}
