<?php

declare(strict_types=1);

namespace App\Domain\Returns\Enums;

/**
 * İade talebi kalemlerinin KANONİK durumu — S4.
 *
 * Kanalların durumlarını tek sözlüğe indirir; ham durum her zaman
 * `channel_status`'ta kalır. Eşlenemeyen durum `UNKNOWN`'dır ve UNKNOWN
 * hiçbir zaman stok hareketi üretmez.
 *
 * Durum STOK DEĞİLDİR: `ACCEPTED` "kanal iadeyi kabul etti" demektir;
 * ürünün depoya döndüğü ayrı kanıtlanır (`receipt_proven_at`) ve stok
 * yalnız defter hareketiyle döner (`restock_status = restored`).
 */
enum ReturnStatus: string
{
    /** Müşteri talebi açtı; ürün henüz satıcıya ulaşmadı. */
    case REQUESTED = 'requested';

    /** Ürün satıcıya ulaştı, satıcının kararı bekleniyor (Trendyol WaitingInAction). */
    case AWAITING_ACTION = 'awaiting_action';

    /** Kanal inceliyor (analiz, sahtecilik kontrolü). */
    case UNDER_REVIEW = 'under_review';

    case ACCEPTED = 'accepted';

    case REJECTED = 'rejected';

    /** Satıcı reddetti, kanal karara bağlamadı (ihtilaf). */
    case DISPUTED = 'disputed';

    case CANCELLED = 'cancelled';

    case UNKNOWN = 'unknown';

    /** Talep düzeyi durumu: en çok aksiyon gerektiren kalemin durumu. */
    public static function aggregate(array $statuses): self
    {
        $order = [self::AWAITING_ACTION, self::UNKNOWN, self::DISPUTED, self::UNDER_REVIEW, self::REQUESTED, self::ACCEPTED, self::REJECTED, self::CANCELLED];

        foreach ($order as $candidate) {
            if (in_array($candidate, $statuses, true)) {
                return $candidate;
            }
        }

        return self::UNKNOWN;
    }

    public function label(): string
    {
        return __(match ($this) {
            self::REQUESTED => 'İade talebi açıldı',
            self::AWAITING_ACTION => 'Kararınız bekleniyor',
            self::UNDER_REVIEW => 'Kanal inceliyor',
            self::ACCEPTED => 'İade kabul edildi',
            self::REJECTED => 'İade reddedildi',
            self::DISPUTED => 'İhtilaflı',
            self::CANCELLED => 'İptal edildi',
            self::UNKNOWN => 'Durum bilinmiyor',
        });
    }
}
