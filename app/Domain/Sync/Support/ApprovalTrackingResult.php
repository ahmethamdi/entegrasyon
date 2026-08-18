<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

/**
 * Onay takibi turunun sonucu.
 *
 * Mimari Karar Dokümanı v2.2 · §13 · Faz 2, §7 · SupportsApprovalWorkflow.
 *
 * `supported` AYRI BİR BİLGİDİR: "0 satır kontrol edildi" ile "bu kanalda
 * onay süreci yok" farklı şeylerdir. Tek sayıya indirgenseydi komut
 * çıktısı Woo bağlantısı için de "0 onaylandı" der ve kullanıcı turun
 * çalıştığını sanırdı.
 */
final readonly class ApprovalTrackingResult
{
    public function __construct(
        public bool $supported,
        public int $checked = 0,
        public int $approved = 0,
        public int $rejected = 0,
    ) {}

    public static function unsupported(): self
    {
        return new self(supported: false);
    }
}
