<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\QuotaMetric;
use App\Domain\Billing\Exceptions\QuotaExceededException;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Catalog\Models\Product;
use App\Domain\Channels\Models\ChannelConnection;
use App\Support\Tenancy\TenantContext;

/**
 * Plan kotasını uygular — yaratmayı engeller.
 *
 * Mimari Karar Dokümanı v2.2 · §3 · Domain/Billing/Actions/EnforceQuota,
 * §4 · plans.limits, §13 · Faz 4.
 *
 * DEĞİŞMEZ KURAL — KOTA YARATMAYI ENGELLER, VAR OLANI SİLMEZ:
 *   Plan düşürüldüğünde limitin üstünde kalan ürünler SİLİNMEZ ve
 *   senkronları DURDURULMAZ; yalnızca YENİSİ eklenemez. Silmek geri
 *   alınamaz ve satıcının kanaldaki listelemelerini de götürürdü.
 *
 * DEĞİŞMEZ KURAL — KOTA STOK VE SİPARİŞ AKIŞINA DOKUNMAZ:
 *   Bu action stok yollarından, sipariş alımından ve senkron
 *   gönderiminden ÇAĞRILMAZ. Sipariş ASLA reddedilmez — pazaryeri onu
 *   kabul etmiştir ve bu otoriter gerçektir; ödeme sorunu yüzünden
 *   sipariş kaybetmek veya stoğu bozmak, çözdüğünden büyük zarar verir.
 *   §14'ün ön koşul kapısının stok akışına dokunmama kuralıyla aynı
 *   tasarım hedefi.
 *
 * DEĞİŞMEZ KURAL — SAYIM KİRACI SCOPE'U ALTINDA YAPILIR. Bağlam
 * `EstablishTenantContext` (panel) veya işin kendisi tarafından kurulur.
 * Scope'suz sayım başka kiracıların satırlarını da sayar ve hiç ürünü
 * olmayan kiracı ilk ürününde engellenirdi.
 */
final class EnforceQuota
{
    /** Aboneliği olmayan kiracının düştüğü plan. */
    public const DEFAULT_PLAN_CODE = 'free';

    /**
     * Kota doluysa istisna fırlatır.
     *
     * @throws QuotaExceededException
     */
    public function check(QuotaMetric $metric): void
    {
        $plan = $this->planForCurrentTenant();

        // Plan hiç tanımlı değilse (seed edilmemiş kurulum) kota
        // UYGULANMAZ. Uygulansaydı seed'i çalıştırmamış bir kurulumda
        // hiç kimse ürün ekleyemez ve sebebi hiçbir yerde görünmezdi.
        if ($plan === null) {
            return;
        }

        $limit = $plan->limitFor($metric);

        // Tanımsız veya null limit SINIRSIZ demektir.
        if ($limit === null) {
            return;
        }

        $current = $this->currentUsage($metric);

        // SINIR TAM DAYANANDA DA AŞILIR: "3 ürün" hakkı üç üründür ve
        // dördüncüyü eklemek kotayı aşar. `>` yazılsaydı her planda bir
        // fazlasına izin verilirdi.
        if ($current >= $limit) {
            throw new QuotaExceededException(
                metric: $metric,
                limit: $limit,
                current: $current,
                planCode: $plan->code,
            );
        }
    }

    /** Kota dolu mu — istisna fırlatmadan sorar (panelde rozet için). */
    public function exceeds(QuotaMetric $metric): bool
    {
        try {
            $this->check($metric);

            return false;
        } catch (QuotaExceededException) {
            return true;
        }
    }

    /**
     * Mevcut kullanım — ANLIK sayım.
     *
     * Dönemsel değil: iki kota da "şu an kaç tane var" sorusudur ve
     * `usage_records` gerektirmez.
     */
    public function currentUsage(QuotaMetric $metric): int
    {
        return match ($metric) {
            QuotaMetric::PRODUCTS => Product::query()->count(),
            QuotaMetric::CHANNELS => ChannelConnection::query()->count(),
        };
    }

    /**
     * Kiracının planı — aktif abonelikten, yoksa varsayılandan.
     *
     * DEĞİŞMEZ KURAL — ABONELİK YOKSA VARSAYILAN PLANA DÜŞÜLÜR, sınırsız
     * SAYILMAZ. Sınırsız sayılsaydı hiç ödeme yapmayan kiracı sınırsız
     * kaynak kullanırdı.
     *
     * DEĞİŞMEZ KURAL — İPTAL EDİLMİŞ ABONELİK KOTA VERMEZ. Verseydi bir
     * kez abone olup iptal eden kiracı ücretli limitleri SONSUZA KADAR
     * kullanmaya devam ederdi. Kapı `grantsQuota()` durumlarıdır.
     */
    public function planForCurrentTenant(): ?Plan
    {
        $subscription = Subscription::query()
            ->whereIn('status', Subscription::ACTIVE_STATUSES)
            ->with('plan')
            ->first();

        if ($subscription?->plan !== null) {
            return $subscription->plan;
        }

        // Varsayılan plan kiracıya ait değildir; `plans` kapsanmaz ama
        // niyeti belgelemek için sistem bağlamında okunur.
        return TenantContext::runAsSystem(
            fn (): ?Plan => Plan::query()->find(self::DEFAULT_PLAN_CODE),
        );
    }
}
