<?php

declare(strict_types=1);

namespace App\Domain\Reconciliation\Support;

use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Orders\Support\PollChannelOrders;
use App\Domain\Orders\Support\ReconcileChannelOrders;
use App\Domain\Reconciliation\Enums\ItemStatus;
use App\Domain\Sync\Enums\SyncDomain;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Kanal stoğu BEKLENENDEN DÜŞÜK — bu bir sürüklenme mi, henüz alınmamış bir
 * satış mı? (mimari denetim P0-2)
 *
 * TEMEL KURAL: BİLİNMEYEN DÜŞÜK KANAL STOĞU ASLA FAZLA SATIŞ RİSKİ
 * YARATMAMALI.
 *
 * Önceden fark ilk turda yönüne bakılmadan "onarılıyordu": kanalda az önce
 * satılmış ama siparişi bize henüz ulaşmamış bir ürünün stoğu eski, yüksek
 * değerle EZİLİR ve aynı ürün ikinci kez satılabilirdi.
 *
 * Onarım (kanalı YUKARI çekmek) ancak şu ÜÇÜ birden doğruysa açılır:
 *
 *   1. ÖNCEKİ tur da aynı düşük değeri görüp beklemiş (`SUSPECTED_SALE`,
 *      aynı uzak miktar). Değer arada değiştiyse yeni bir satış olabilir —
 *      sayaç baştan başlar.
 *   2. Bağlantının bekleyen/işlenen inbox mesajı YOK — gelmiş ama henüz
 *      işlenmemiş bir sipariş farkı açıklayabilir.
 *   3. Sipariş alımı, ilk gözlemden SONRA tam bir tur tamamlamış:
 *      yoklama kanalında `orders_polled_through`, webhook kanalında
 *      sipariş mutabakatının `orders_reconciled_through` imleci ilk
 *      gözlemden yeni, ve yarım kalmış yoklama turu yok.
 *
 * Sipariş alımını kanıtlayacak bir imleç YOKSA (sipariş mutabakatı
 * yazılmamış webhook kanalı) onarım HİÇ açılmaz: kalem `SUSPECTED_SALE`
 * kalır ve satıcıya görünür. Az stok göstermek satış kaçırır; fazla
 * göstermek iki müşteriye aynı malı satar — ikincisi her zaman daha kötü.
 *
 * Kanal stoğu beklenenden YÜKSEKSE (fazla satış riski) bu kapı sorulmaz,
 * onarım bugünkü gibi hemen açılır.
 */
final class UnrecordedSaleGuard
{
    public function mayRaiseRemote(ChannelConnection $connection, string $listingId, int $observedRemote): bool
    {
        $previous = DB::table('reconciliation_items')
            ->where('tenant_id', TenantContext::idOrFail())
            ->where('listing_id', $listingId)
            ->where('domain', SyncDomain::INVENTORY->value)
            ->orderByDesc('id')
            ->first(['status', 'remote_value', 'checked_at']);

        if ($previous === null || $previous->status !== ItemStatus::SUSPECTED_SALE->value) {
            return false;
        }

        $remote = json_decode((string) $previous->remote_value, true);

        if (! is_array($remote) || ($remote['quantity'] ?? null) !== $observedRemote) {
            return false;
        }

        if ($this->hasUnprocessedInbox($connection)) {
            return false;
        }

        $watermark = $this->orderIntakeWatermark($connection);

        if ($watermark === null) {
            return false;
        }

        return $watermark->greaterThanOrEqualTo(CarbonImmutable::parse((string) $previous->checked_at));
    }

    private function hasUnprocessedInbox(ChannelConnection $connection): bool
    {
        return DB::table('inbox_messages')
            ->where('tenant_id', $connection->tenant_id)
            ->where('channel_connection_id', $connection->id)
            ->whereIn('status', ['pending', 'processing'])
            ->exists();
    }

    /**
     * Sipariş alımının "buraya kadar her şeyi gördüm" dediği an — yoksa null.
     */
    private function orderIntakeWatermark(ChannelConnection $connection): ?CarbonImmutable
    {
        $settings = $connection->fresh()?->settings ?? [];

        // Sayfa sınırında yarım kalmış yoklama turu: imleç ilerlemedi ama
        // pencerenin kalanı henüz okunmadı.
        if (isset($settings[PollChannelOrders::RESUME_KEY]) || isset($settings[ReconcileChannelOrders::RESUME_KEY])) {
            return null;
        }

        $marks = array_filter([
            $settings[PollChannelOrders::CURSOR_KEY] ?? null,
            $settings[ReconcileChannelOrders::CURSOR_KEY] ?? null,
        ], 'is_string');

        $latest = null;

        foreach ($marks as $mark) {
            try {
                $at = CarbonImmutable::parse($mark);
            } catch (Throwable) {
                continue;
            }

            $latest = $latest === null || $at->greaterThan($latest) ? $at : $latest;
        }

        return $latest;
    }
}
