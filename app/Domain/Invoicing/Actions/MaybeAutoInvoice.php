<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Billing\Exceptions\QuotaExceededException;
use App\Domain\Channels\Contracts\SupportsInvoiceData;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAccount;
use App\Domain\Orders\Enums\OrderState;
use App\Domain\Orders\Models\Order;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sipariş kargoya verildi / teslim edildi → ayar açıksa fatura isteği.
 *
 * TEK KANCA: sipariş durumunu ilerleten her yol (kanaldan gelen durum
 * güncellemesi ve kargo olayı — `OrderEventRouter`; panelden girilen kargo —
 * `RecordPanelShipment`) ulaştığı AŞAMAYI buraya bildirir. Karar kuralları
 * tek yerde durur; her yola ayrı ayrı yazılsaydı biri "iptal edilmişe
 * kesme" kuralını unuturdu.
 *
 * KOŞULLAR — hepsi sağlanmazsa HİÇBİR ŞEY yapılmaz (sessizce):
 *   · hesap bağlı ve kullanılabilir
 *   · `auto_issue` açık ve sipariş ayarın istediği aşamaya ulaştı
 *     (teslim, kargo aşamasını da karşılar)
 *   · sipariş ayar açıldıktan SONRA verildi (`auto_issue_since`)
 *   · kanal faturalanabilir (`SupportsInvoiceData`)
 *   · sipariş iptal edilmemiş ve faturası yok
 *
 * ⚠️ GERİYE DÖNÜK FATURA YOK: ayar açıldığı an `auto_issue_since` yazılır
 * ve yalnız ondan sonra VERİLEN siparişler faturalanır. Bu olmasaydı ayarı
 * açan satıcının o an yoldaki/teslim edilmiş yüzlerce eski siparişi — bir
 * kısmı başka yerden zaten faturalanmış — sonraki durum yoklamasıyla
 * topluca kesilirdi; resmî e-belgenin iptali ayrı ve zahmetli bir süreçtir.
 *
 * TEKRAR ZARARSIZDIR: yoklama aynı durumu defalarca görür. Var olan fatura
 * erken çıkışla, yarış durumu `invoices_order_unique` kısıtıyla
 * (`RequestInvoice`) karşılanır.
 *
 * HATA SİPARİŞ YOLUNU DURDURMAZ: fatura isteği açılamazsa log'a düşer ve
 * çağıran devam eder. Sipariş/stok senkronu faturaya bağımlı olsaydı
 * Paraşüt tarafındaki bir arıza siparişleri de takılı bırakırdı.
 */
final class MaybeAutoInvoice
{
    public const STAGE_SHIPPED = InvoiceAccount::AUTO_SHIPPED;

    public const STAGE_DELIVERED = InvoiceAccount::AUTO_DELIVERED;

    /**
     * Kanal durum sözcüğü → aşama. Yalnız AÇIKÇA tanınanlar; bilinmeyen
     * durum hiçbir aşama sayılmaz (izin listesi — `Order::awaitingShipment`
     * kuralı). Trendyol `Shipped`/`Delivered`, Shopify/Woo kargo satırının
     * `shipped`/`delivered`'ı aynı sözcükleri kullanır.
     */
    private const STATUS_STAGES = [
        'shipped' => self::STAGE_SHIPPED,
        'delivered' => self::STAGE_DELIVERED,
    ];

    /** Aşama sırası — teslim, kargoyu da karşılar. */
    private const RANK = [self::STAGE_SHIPPED => 1, self::STAGE_DELIVERED => 2];

    /**
     * İptal sayılan sipariş durumları. `UnPacked` (Trendyol bölünen paket)
     * da iptaldir: kalemler yeni paketlerde yeniden gelir ve fatura onlara
     * kesilir.
     */
    public const CANCELLED_STATUSES = ['cancelled', 'canceled', 'unpacked'];

    public function __construct(private readonly RequestInvoice $requestInvoice = new RequestInvoice) {}

    /** Kanal durum sözcüğünün aşaması; tanınmıyorsa null. */
    public static function stageOf(?string $status): ?string
    {
        return $status === null ? null : (self::STATUS_STAGES[strtolower(trim($status))] ?? null);
    }

    /** Kanonik durumun aşaması (ADR-001); kargo/teslim dışı → null. */
    public static function stageOfState(?string $state): ?string
    {
        return match ($state) {
            OrderState::SHIPPED->value => self::STAGE_SHIPPED,
            OrderState::DELIVERED->value => self::STAGE_DELIVERED,
            default => null,
        };
    }

    /**
     * @param  string|null  $stage  `STAGE_*`; null = aşama yok, hiçbir şey yapılmaz
     * @return Invoice|null açılan (ya da var olan) fatura; koşul tutmadıysa null
     */
    public function run(Order $order, ?string $stage): ?Invoice
    {
        if ($stage === null || ! isset(self::RANK[$stage])) {
            return null;
        }

        try {
            return $this->decide($order, $stage);
        } catch (QuotaExceededException $e) {
            // Kota dolu: satır AÇILMAZ, sipariş akışı sürer (kota sipariş
            // akışını durdurmaz). Hata değil plan sınırı — ayrı anahtarla
            // yazılır ki alarm gürültüsüne karışmasın; satıcı siparişi
            // faturasız görür ve planı yükseltince elle keser.
            Log::info('invoicing.auto_issue_quota_exceeded', ['order' => $order->id, 'limit' => $e->limit]);

            return null;
        } catch (Throwable $e) {
            Log::error('invoicing.auto_issue_failed', ['order' => $order->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function decide(Order $order, string $stage): ?Invoice
    {
        $account = InvoiceAccount::query()->first();

        if ($account === null || ! $account->isUsable()) {
            return null;
        }

        $wanted = $account->autoIssue();

        if ($wanted === InvoiceAccount::AUTO_OFF || self::RANK[$stage] < self::RANK[$wanted]) {
            return null;
        }

        // Tarihi bilinmeyen sipariş KESİLMEZ: emin olunamayan durumda
        // fatura kesmemek, geriye dönük resmî belge kesmekten iyidir.
        $since = $account->autoIssueSince();
        $placedAt = $order->placed_at;

        if ($since === null || $placedAt === null || $placedAt->lt($since)) {
            return null;
        }

        // Kanonik durum önce (ADR-001); eşlenmemiş eski satırda ham liste.
        if (in_array($order->state, [OrderState::CANCELLED->value, OrderState::SUPERSEDED->value], true)
            || ($order->state === null && in_array(strtolower((string) $order->status), self::CANCELLED_STATUSES, true))) {
            return null;
        }

        // Tüm kalemleri iptal edilmiş sipariş de iptaldir — başlık durumu
        // kanala göre gecikebilir.
        $live = $order->lines()->whereColumn('quantity_cancelled', '<', 'quantity')->exists();

        if (! $live) {
            return null;
        }

        $order->loadMissing('connection.channelType');
        $class = $order->connection?->channelType?->adapter_class;

        if (! is_string($class) || $class === '' || ! is_subclass_of($class, SupportsInvoiceData::class)) {
            return null;
        }

        if ($order->invoice()->exists()) {
            return null;
        }

        return $this->requestInvoice->run($order, $account);
    }
}
