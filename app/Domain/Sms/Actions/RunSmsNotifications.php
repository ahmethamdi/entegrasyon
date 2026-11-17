<?php

declare(strict_types=1);

namespace App\Domain\Sms\Actions;

use App\Domain\Channels\Contracts\SupportsShippingRecipient;
use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Registry\AdapterRegistry;
use App\Domain\Orders\Models\Fulfillment;
use App\Domain\Orders\Models\Order;
use App\Domain\Shipping\Exceptions\RecipientUnavailable;
use App\Domain\Sms\Models\SmsAccount;
use App\Domain\Sms\Models\SmsMessage;
use App\Domain\Sms\Support\SmsPhone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Bir kiracının SMS bildirim turu — dakikada bir, kiracı bağlamında.
 *
 * NEDEN AYRI TARAMA (olay dinleyicisi değil): sipariş alımı, stok
 * hareketi ve sağlık kontrolü 34Pazar'ın en kritik yollarıdır. SMS
 * sağlayıcısının yavaşlığı ya da hatası o işlemlere hiçbir şekilde
 * dokunmamalı. Tarama yalnız OKUR; olayın tekrarını `sms_event_keys`
 * engeller.
 *
 * Satıcıya (bildirim numarası):
 *   • new_order        son turdan beri gelen siparişler — TEK özet SMS
 *   • stock_out        canlı ilanı olup toplam stoğu 0'a inen ürünler (ürün başına günde bir)
 *   • channel_problem  sağlıksız kalan kanal bağlantıları (her kopuşta bir)
 *
 * Alıcıya (yalnız alıcı telefonunu veren kanallar — Shopify, WooCommerce):
 *   • buyer_shipped    34Pazar'dan kanala bildirilen kargo + takip no
 *
 * Pazaryerleri (Trendyol, HB, n11 …) alıcı telefonu vermez ve platform
 * dışı iletişimi yasaklar; onların alıcısına SMS GİTMEZ.
 *
 * Sessiz saatler (22:00–08:00, satıcı kapatabilir): tur hiçbir şey
 * yapmaz; olaylar sessizlik bitince geriye bakışla (12 sa) özetlenir.
 */
final class RunSmsNotifications
{
    public function __construct(
        private readonly SendSms $sms,
        private readonly AdapterRegistry $registry,
    ) {}

    /** @return array<string, int> tür → gönderilen mesaj */
    public function run(SmsAccount $account, ?Carbon $now = null): array
    {
        $now ??= now();
        $sent = [];

        if (! $account->isUsable() || $this->isQuiet($account, $now)) {
            return $sent;
        }

        $since = $now->copy()->subHours((int) config('sms.lookback_hours', 12));

        if ($account->notify_since !== null && $account->notify_since->greaterThan($since)) {
            $since = $account->notify_since->copy();
        }

        $phone = $account->notify_phone;

        if ($phone !== null && SmsPhone::normalize($phone) !== null) {
            if ($account->wants(SmsAccount::EVENT_NEW_ORDER)) {
                $sent[SmsAccount::EVENT_NEW_ORDER] = $this->newOrders($account, $phone, $since);
            }

            if ($account->wants(SmsAccount::EVENT_STOCK_OUT)) {
                $sent[SmsAccount::EVENT_STOCK_OUT] = $this->stockOuts($account, $phone, $since, $now);
            }

            if ($account->wants(SmsAccount::EVENT_CHANNEL_PROBLEM)) {
                $sent[SmsAccount::EVENT_CHANNEL_PROBLEM] = $this->channelProblems($account, $phone, $now);
            }
        }

        if ($account->wants(SmsAccount::EVENT_BUYER_SHIPPED)) {
            $sent[SmsAccount::EVENT_BUYER_SHIPPED] = $this->buyerShipped($account, $since);
        }

        return $sent;
    }

    public function isQuiet(SmsAccount $account, Carbon $now): bool
    {
        if (! $account->quiet_hours) {
            return false;
        }

        $hour = (int) $now->copy()->setTimezone((string) config('sms.timezone', 'Europe/Istanbul'))->format('G');
        $from = (int) config('sms.quiet_from', 22);
        $until = (int) config('sms.quiet_until', 8);

        return $from > $until ? ($hour >= $from || $hour < $until) : ($hour >= $from && $hour < $until);
    }

    // ───────────────────────────────────────────────────────── satıcı

    /**
     * Yeni sipariş: hem 34Pazar'a giriş (created_at) hem kanalda verilme
     * (placed_at) zamanı pencerede olmalı — yeni bağlanan kanalın geçmiş
     * siparişleri içe aktarılırken "40 yeni sipariş" SMS'i gitmesin.
     */
    private function newOrders(SmsAccount $account, string $phone, Carbon $since): int
    {
        $orders = Order::query()
            ->where('created_at', '>=', $since)
            ->where(fn ($q) => $q->whereNull('placed_at')->orWhere('placed_at', '>=', $since->copy()->subHour()))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('sms_event_keys')
                ->whereColumn('sms_event_keys.tenant_id', 'orders.tenant_id')
                ->whereRaw("sms_event_keys.event_key = 'order:' || orders.id::text"))
            ->orderBy('created_at')
            ->limit(500)
            ->get(['id', 'channel_connection_id', 'external_id', 'external_number', 'currency', 'grand_total']);

        if ($orders->isEmpty()) {
            return 0;
        }

        $claimed = $this->sms->claim($account, $orders->map(fn (Order $o): string => 'order:'.$o->id)->all());
        $orders = $orders->filter(fn (Order $o): bool => in_array('order:'.$o->id, $claimed, true))->values();

        if ($orders->isEmpty()) {
            return 0;
        }

        $channels = $this->channelNames($orders->pluck('channel_connection_id')->unique()->all());

        if ($orders->count() === 1) {
            $o = $orders->first();
            $text = sprintf('34Pazar: Yeni sipariş - %s #%s, %s.',
                $channels[$o->channel_connection_id] ?? 'Kanal',
                $o->external_number ?: $o->external_id,
                $this->money((string) $o->grand_total, (string) $o->currency));
        } else {
            $byChannel = $orders->groupBy(fn (Order $o): string => $channels[$o->channel_connection_id] ?? 'Kanal')
                ->map(fn (Collection $g, string $name): string => $name.' '.$g->count())
                ->values()->implode(', ');
            $totals = $orders->groupBy('currency')
                ->map(fn (Collection $g, string $cur): string => $this->money((string) $g->sum(fn (Order $o): float => (float) $o->grand_total), $cur))
                ->values()->implode(' + ');
            $text = sprintf('34Pazar: %d yeni sipariş (%s), toplam %s.', $orders->count(), $byChannel, $totals);
        }

        $this->sms->send($account, SmsAccount::EVENT_NEW_ORDER, $phone, $text, $claimed);

        return 1;
    }

    /**
     * Stok tükendi: canlı ilanı olan, bütün depolardaki toplam satılabilir
     * stoğu 0 ya da altına inen ve stoğu pencere içinde DEĞİŞEN ürün.
     * Ürün başına günde bir kez (anahtar gün taşır).
     */
    private function stockOuts(SmsAccount $account, string $phone, Carbon $since, Carbon $now): int
    {
        $rows = DB::table('inventory_levels as il')
            ->join('variants as v', 'v.id', '=', 'il.variant_id')
            ->where('il.tenant_id', $account->tenant_id)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('listings as l')
                ->whereColumn('l.variant_id', 'il.variant_id')
                ->where('l.lifecycle_status', 'live'))
            ->groupBy('il.variant_id', 'v.sku')
            ->havingRaw('SUM(il.available) <= 0')
            ->havingRaw('MAX(il.updated_at) >= ?', [$since])
            ->orderBy('v.sku')
            ->limit(200)
            ->get(['il.variant_id', 'v.sku']);

        if ($rows->isEmpty()) {
            return 0;
        }

        $day = $now->copy()->setTimezone((string) config('sms.timezone', 'Europe/Istanbul'))->format('Y-m-d');
        $keys = $rows->map(fn ($r): string => 'stockout:'.$r->variant_id.':'.$day)->all();
        $claimed = $this->sms->claim($account, $keys);

        if ($claimed === []) {
            return 0;
        }

        $skus = $rows->filter(fn ($r): bool => in_array('stockout:'.$r->variant_id.':'.$day, $claimed, true))
            ->pluck('sku')->map(fn ($s): string => (string) $s)->values();
        $shown = $skus->take(4)->implode(', ');
        $more = $skus->count() > 4 ? ' +'.($skus->count() - 4) : '';
        $text = $skus->count() === 1
            ? sprintf('34Pazar: %s stokta tükendi; kanallarda stok 0 oldu.', $shown)
            : sprintf('34Pazar: %d ürün stokta tükendi: %s%s.', $skus->count(), $shown, $more);

        $this->sms->send($account, SmsAccount::EVENT_STOCK_OUT, $phone, $text, $claimed);

        return 1;
    }

    /**
     * Kanal sorunu: bir kez sağlıklı olmuş, şimdi sağlıksız ve en az 15 dk
     * öyle kalan bağlantı. Anahtar son sağlıklı anı taşır: kanal düzelip
     * yeniden koparsa yeni SMS gider; aynı kopuş için bir kez.
     */
    private function channelProblems(SmsAccount $account, string $phone, Carbon $now): int
    {
        $connections = ChannelConnection::query()
            ->where('health_status', 'unhealthy')
            ->where('status', '!=', 'inactive')
            ->whereNotNull('connected_at')
            ->whereNotNull('last_healthy_at')
            ->where('last_healthy_at', '<=', $now->copy()->subMinutes(15))
            ->get(['id', 'label', 'channel_type_code', 'last_healthy_at']);

        if ($connections->isEmpty()) {
            return 0;
        }

        $key = fn (ChannelConnection $c): string => 'channel:'.$c->id.':'.$c->last_healthy_at?->getTimestamp();
        $claimed = $this->sms->claim($account, $connections->map($key)->all());
        $connections = $connections->filter(fn (ChannelConnection $c): bool => in_array($key($c), $claimed, true))->values();

        if ($connections->isEmpty()) {
            return 0;
        }

        $names = $this->channelNames($connections->pluck('id')->all());
        $list = $connections->map(fn (ChannelConnection $c): string => $names[$c->id] ?? $c->label)->implode(', ');
        $text = sprintf('34Pazar: %s bağlantısında sorun var; stok ve sipariş akışı durdu. Panelden kontrol edin.', $list);

        $this->sms->send($account, SmsAccount::EVENT_CHANNEL_PROBLEM, $phone, $text, $claimed);

        return 1;
    }

    // ───────────────────────────────────────────────────────── alıcı

    /**
     * 34Pazar'dan kanala BAŞARIYLA bildirilmiş (push_status = sent), takip
     * numaralı kargo. Kanalın kendi panelinden girilen kargoya SMS
     * gönderilmez: o kanal alıcıya kendi bildirimini zaten gönderir.
     *
     * Telefon kanaldan ANLIK okunur, hiçbir yere yazılmaz.
     */
    private function buyerShipped(SmsAccount $account, Carbon $since): int
    {
        $fulfillments = Fulfillment::query()
            ->where('push_status', Fulfillment::PUSH_SENT)
            ->whereNotNull('tracking_number')
            ->where('pushed_at', '>=', $since)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('sms_event_keys')
                ->whereColumn('sms_event_keys.tenant_id', 'fulfillments.tenant_id')
                ->whereRaw("sms_event_keys.event_key = 'ship:' || fulfillments.id::text"))
            ->orderBy('pushed_at')
            ->limit((int) config('sms.buyer_batch', 50))
            ->get(['id', 'order_id', 'carrier', 'tracking_number']);

        $count = 0;

        foreach ($fulfillments as $fulfillment) {
            /** @var Order|null $order */
            $order = Order::query()->with('connection')->find($fulfillment->order_id);
            $connection = $order?->connection;

            if ($order === null || $connection === null) {
                continue;
            }

            try {
                $adapter = $this->registry->for($connection);
            } catch (Throwable) {
                continue;
            }

            if (! $adapter instanceof SupportsShippingRecipient) {
                continue;   // pazaryeri: alıcıya SMS yok
            }

            $claimed = $this->sms->claim($account, ['ship:'.$fulfillment->id]);

            if ($claimed === []) {
                continue;
            }

            $text = $this->shippedText($order, $fulfillment);

            try {
                $phone = $adapter->fetchShippingRecipient($order)->phone;
            } catch (RecipientUnavailable) {
                $this->sms->send($account, SmsAccount::EVENT_BUYER_SHIPPED, '', $text, $claimed);

                continue;
            } catch (Throwable) {
                // Kanal şu an cevap vermedi: anahtar geri alınır, sonraki turda yeniden denenir.
                DB::table('sms_event_keys')->where('tenant_id', $account->tenant_id)->whereIn('event_key', $claimed)->delete();

                continue;
            }

            $message = $this->sms->send($account, SmsAccount::EVENT_BUYER_SHIPPED, $phone, $text, $claimed);
            $count += $message->status === SmsMessage::STATUS_SENT ? 1 : 0;
        }

        return $count;
    }

    private function shippedText(Order $order, Fulfillment $fulfillment): string
    {
        $carrier = trim((string) $fulfillment->carrier);

        return sprintf('Siparişiniz #%s kargoya verildi. %stakip no: %s',
            $order->external_number ?: $order->external_id,
            $carrier !== '' ? $carrier.' ' : 'Kargo ',
            $fulfillment->tracking_number);
    }

    // ───────────────────────────────────────────────────────── yardımcı

    /**
     * Bağlantı → "Trendyol" ya da aynı kanaldan birden çok mağaza varsa "Trendyol (Etiket)".
     *
     * @param  list<string|null>  $connectionIds
     * @return array<string, string>
     */
    private function channelNames(array $connectionIds): array
    {
        $rows = DB::table('channel_connections as c')
            ->join('channel_types as t', 't.code', '=', 'c.channel_type_code')
            ->whereIn('c.id', array_values(array_filter($connectionIds)))
            ->get(['c.id', 'c.label', 'c.channel_type_code', 't.name']);

        $perType = ChannelConnection::query()
            ->selectRaw('channel_type_code, COUNT(*) as n')
            ->groupBy('channel_type_code')
            ->pluck('n', 'channel_type_code');

        $names = [];

        foreach ($rows as $row) {
            $names[$row->id] = (int) ($perType[$row->channel_type_code] ?? 1) > 1 && trim((string) $row->label) !== ''
                ? $row->name.' ('.$row->label.')'
                : (string) $row->name;
        }

        return $names;
    }

    private function money(string $amount, string $currency): string
    {
        $formatted = number_format((float) $amount, 2, ',', '.');

        return match (strtoupper($currency)) {
            'TRY', '' => $formatted.' TL',
            'EUR' => $formatted.' €',
            default => $formatted.' '.strtoupper($currency),
        };
    }
}
