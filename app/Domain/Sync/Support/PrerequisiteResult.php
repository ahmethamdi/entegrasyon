<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

use App\Domain\Sync\Enums\PublishReason;

/**
 * Ön koşul kapısının sonucu.
 *
 * Mimari Karar Dokümanı v2.2 · §14 · "eksikse listing blocked, stok akışı
 * etkilenmez".
 *
 * EKSİKLER ADIYLA TAŞINIR, SAYIYLA DEĞİL:
 *   "3 zorunlu öznitelik eksik" cümlesi kullanıcıya hangi ekrana gideceğini
 *   söylemez. Hangi kategorinin eşleşmediği ve hangi özniteliklerin eksik
 *   olduğu ADLARIYLA taşınır ve panele öyle gider.
 *
 * `applies()` İLE `satisfied()` AYRI SORULARDIR:
 *   Taksonomisi olmayan kanalda kapı ÇALIŞMAZ (`applies() === false`) ve
 *   sonuç sağlanmış sayılır. İkisi tek bayrağa indirgenseydi "kontrol
 *   edildi ve geçti" ile "hiç kontrol edilmedi" ayırt edilemez, panelde
 *   Woo listing'leri de "ön koşul tamam" rozetiyle görünürdü.
 */
final readonly class PrerequisiteResult
{
    /**
     * @param  list<string>  $missingAttributes  Eksik ZORUNLU özniteliklerin adları
     */
    private function __construct(
        private bool $applies,
        private ?string $missingCategoryReason,
        private array $missingAttributes,
        public ?string $channelCategoryId = null,
        private array $issues = [],
        private ?PublishReason $categoryCode = null,
    ) {}

    /** Kanalın taksonomisi yok — ön koşul aranmaz. */
    public static function notApplicable(): self
    {
        return new self(applies: false, missingCategoryReason: null, missingAttributes: []);
    }

    /**
     * Ön koşul sağlandı.
     *
     * Adı `satisfied()` DEĞİL: PHP'de statik fabrika ile örnek metodu aynı
     * adı paylaşamaz ve dokümanın örneğindeki `$gate->satisfied()` çağrısı
     * korunmalı.
     */
    public static function ok(string $channelCategoryId): self
    {
        return new self(
            applies: true,
            missingCategoryReason: null,
            missingAttributes: [],
            channelCategoryId: $channelCategoryId,
        );
    }

    /** @param list<string> $missingAttributes */
    public static function blocked(
        ?string $missingCategoryReason = null,
        array $missingAttributes = [],
        ?string $channelCategoryId = null,
        ?PublishReason $categoryCode = null,
    ): self {
        return new self(
            applies: true,
            missingCategoryReason: $missingCategoryReason,
            missingAttributes: $missingAttributes,
            channelCategoryId: $channelCategoryId,
            categoryCode: $categoryCode,
        );
    }

    /**
     * Kanalın kendi doğrulamasının (`ValidatesPublication`) bulduğu eksikler.
     *
     * @param  list<PublishIssue>  $issues
     */
    public static function invalid(array $issues, string $channelCategoryId): self
    {
        return new self(
            applies: true,
            missingCategoryReason: null,
            missingAttributes: [],
            channelCategoryId: $channelCategoryId,
            issues: array_values($issues),
        );
    }

    /** @return list<PublishIssue> */
    public function issues(): array
    {
        return $this->issues;
    }

    /** Engelin KARARLI kodu (ilk eksik); engel yoksa null. */
    public function reasonCode(): ?PublishReason
    {
        if ($this->satisfied()) {
            return null;
        }

        if ($this->missingCategoryReason !== null) {
            return $this->categoryCode ?? PublishReason::CATEGORY_MAPPING_REQUIRED;
        }

        if ($this->missingAttributes !== []) {
            return PublishReason::ATTRIBUTE_MAPPING_REQUIRED;
        }

        return $this->issues[0]->reason ?? null;
    }

    /**
     * Kapı bu kanalda ÇALIŞIYOR mu.
     *
     * Yalnızca `SupportsTaxonomy` uygulayan kanallarda `true`.
     */
    public function applies(): bool
    {
        return $this->applies;
    }

    public function satisfied(): bool
    {
        return ! $this->applies
            || ($this->missingCategoryReason === null && $this->missingAttributes === [] && $this->issues === []);
    }

    public function missingCategoryReason(): ?string
    {
        return $this->missingCategoryReason;
    }

    /** @return list<string> */
    public function missingAttributes(): array
    {
        return $this->missingAttributes;
    }

    /**
     * Panelde ve `listing_sync_states.last_error` içinde gösterilecek metin.
     *
     * İki eksik türü AYRI cümlelerdir: kategori eşleşmesi yokken öznitelik
     * eksikliğinden söz etmek anlamsızdır (hangi kategorinin öznitelikleri?).
     */
    public function reason(): ?string
    {
        if ($this->satisfied()) {
            return null;
        }

        $parts = [];

        if ($this->missingCategoryReason !== null) {
            $parts[] = $this->missingCategoryReason;
        }

        if ($this->missingAttributes !== []) {
            $parts[] = __('Eksik zorunlu öznitelik: :attributes.', [
                'attributes' => implode(', ', $this->missingAttributes),
            ]);
        }

        foreach ($this->issues as $issue) {
            $parts[] = $issue->describe();
        }

        return implode(' ', $parts);
    }
}
