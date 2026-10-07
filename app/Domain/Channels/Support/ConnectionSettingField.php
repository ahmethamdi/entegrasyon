<?php

declare(strict_types=1);

namespace App\Domain\Channels\Support;

/**
 * Tek bir bağlantı ayarı — panelde bir seçim kutusu.
 *
 * ⚠️ YALNIZCA SEÇİM VARDIR, SERBEST METİN YOKTUR. Değerler kanalın kabul
 * ettiği kapalı bir kümedir (Etsy `who_made` üç değer alır; kargo profili
 * mağazada var olan bir kimliktir). Serbest metin olsaydı yazım hatası
 * ancak ilk ilan açılırken, kanalın 400'üyle ortaya çıkardı.
 *
 * Seçenek `usable = false` ise listede GÖRÜNÜR ama SEÇİLEMEZ ve `note`
 * nedenini söyler. Gizlenseydi satıcı Etsy'de gördüğü profili burada
 * bulamaz ve nedenini bilemezdi.
 */
final readonly class ConnectionSettingField
{
    /**
     * @param  list<array{value: string, label: string, usable: bool, note?: string|null}>  $options
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $options,
        public ?string $value = null,
        public bool $required = true,
        public ?string $hint = null,
        // Seçenekler kanaldan okunamadıysa nedeni. Boş liste "hiç yok"
        // ile "okunamadı"yı karıştırırdı; satıcı mağazasında profil
        // olmadığını sanardı.
        public ?string $optionsError = null,
    ) {}

    /** Değer seçilebilir seçeneklerden biri mi? */
    public function accepts(string $value): bool
    {
        foreach ($this->options as $option) {
            if ($option['value'] === $value) {
                return $option['usable'];
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'hint' => $this->hint,
            'required' => $this->required,
            'value' => $this->value,
            'options' => array_map(static fn (array $option): array => [
                'value' => $option['value'],
                'label' => $option['label'],
                'usable' => $option['usable'],
                'note' => $option['note'] ?? null,
            ], $this->options),
            'optionsError' => $this->optionsError,
        ];
    }
}
