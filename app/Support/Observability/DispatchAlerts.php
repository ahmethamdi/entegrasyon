<?php

declare(strict_types=1);

namespace App\Support\Observability;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Eşiği aşan metrikleri e-posta bildirimine çevirir.
 *
 * Mimari Karar Dokümanı v2.2 · §11 ("Panelde basit grafikler, eşik
 * aşımında e-posta"), §12 ("günlük özet: kiracı başına 10'dan fazla ölü
 * iş → e-posta; sistem geneli: eşik aşarsa yönetici uyarısı").
 *
 * ─────────────────────────────────────────────────────────────────────
 * DEĞİŞMEZ KURAL — TARAMA ÖLÇMEZ, YALNIZCA OKUR
 * ─────────────────────────────────────────────────────────────────────
 * Değerler `metric_snapshots`'tan okunur; on üç ağır toplama sorgusu
 * BURADA YENİDEN KOŞULMAZ. Koşsaydı iki gerçek kaynağı doğardı: panel
 * bir değeri, e-posta başka bir değeri gösterirdi (turlar farklı anlarda
 * çalışır) ve satıcı hangisine inanacağını bilemezdi. Ayrıca
 * `percentile_cont` tam tarama ister ve aynı maliyet iki kez ödenirdi.
 *
 * ─────────────────────────────────────────────────────────────────────
 * DEĞİŞMEZ KURAL — EŞİK `Metric::threshold()` İÇİNDE TEK KAYNAKTIR
 * ─────────────────────────────────────────────────────────────────────
 * Karşılaştırma `Metric::breaches()` ile yapılır; burada `>` veya `>=`
 * yeniden YAZILMAZ. Yazılsaydı panel rozeti ile e-posta ayrışır ve
 * satıcı "panelde yeşil ama e-posta geldi" durumuyla karşılaşırdı.
 *
 * ─────────────────────────────────────────────────────────────────────
 * DEĞİŞMEZ KURAL — SON ÖLÇÜM `id` İLE SEÇİLİR, `captured_at` İLE DEĞİL
 * ─────────────────────────────────────────────────────────────────────
 * `captured_at` SANİYE hassasiyetlidir; arka arkaya koşan iki tur aynı
 * damgayı taşıyabilir ve sıra belirsiz kalır. `MetricsController` ile
 * AYNI kural — ayrışsalardı panel bir değeri "son" sayarken uyarı
 * başkasını sayardı.
 *
 * ─────────────────────────────────────────────────────────────────────
 * DEĞİŞMEZ KURAL — AYNI UYARI AYNI GÜN İKİ KEZ GİTMEZ
 * ─────────────────────────────────────────────────────────────────────
 * Çıpa `alert_deliveries (alert_key, sent_on)` tekilliğidir ve yarışı
 * `insertOrIgnore` çözer: kayıt GÖNDERİMDEN ÖNCE yazılır. Sonra
 * yazılsaydı iki paralel tur aynı uyarıyı iki kez gönderir, ikisi de
 * kaydı sonra yazar ve tekillik ihlali ancak İŞ BİTTİKTEN SONRA fark
 * edilirdi — e-posta çoktan gitmiş olurdu.
 *
 * "Yazdım ama gönderemedim" durumu bilinçli olarak KABUL EDİLİR: bir
 * uyarıyı kaçırmak, aynı uyarıyı iki kez göndermekten iyidir (§12'nin
 * amacı dikkat çekmek; tekrar eden bildirim dikkati YOK EDER).
 *
 * DEĞİŞMEZ KURAL — TARAMA `runAsSystem()` İLE TÜM KİRACILARI GÖRÜR.
 * Bağlam altında koşsaydı yalnızca tek kiracının uyarıları gönderilirdi.
 */
final class DispatchAlerts
{
    public function __construct(private readonly AlertMailer $mailer) {}

    /** @return array{sent: int, suppressed: int, skipped: int} */
    public function run(): array
    {
        return TenantContext::runAsSystem(function (): array {
            $sent = 0;
            $suppressed = 0;
            $skipped = 0;

            foreach ($this->breachingMetrics() as $breach) {
                $recipients = $this->recipientsFor($breach);

                // ALICISI OLMAYAN UYARI GÖNDERİLMEZ ama SESSİZ DE
                // GEÇİLMEZ: yönetici adresi tanımsız olabilir ve bu
                // bilinçli bir yapılandırma durumudur — ama kimsenin
                // haberi olmadan kaybolmamalı.
                if ($recipients === []) {
                    Log::info('alerts.no_recipient', [
                        'alert_key' => $breach['key'],
                        'metric' => $breach['metric']->value,
                    ]);

                    $skipped++;

                    continue;
                }

                // KAYIT ÖNCE — gerekçe sınıf başlığında.
                if (! $this->claim($breach, count($recipients))) {
                    $suppressed++;

                    continue;
                }

                $this->mailer->send($breach, $recipients);
                $sent++;
            }

            return ['sent' => $sent, 'suppressed' => $suppressed, 'skipped' => $skipped];
        });
    }

    // ---------------------------------------------------------------- okuma

    /**
     * Eşiği aşan SON ölçümler.
     *
     * Her (metrik, kapsam) çifti için EN SON satır alınır ve `breaches()`
     * uygulanır. Geçmiş satırlar sorulmaz: uyarı ŞU ANKİ durumu bildirir
     * ve dün aşılıp bugün düzelmiş bir eşik için e-posta göndermek
     * satıcıyı olmayan bir soruna gönderirdi.
     *
     * @return list<array{key: string, metric: Metric, scope: string, value: float, tenantId: ?string}>
     */
    private function breachingMetrics(): array
    {
        // DISTINCT ON ile (metrik, kapsam) başına son satır. `MAX(id)`
        // KULLANILAMAZ — PostgreSQL'de uuid için `max()` toplam
        // fonksiyonu YOKTUR (mutabakat ekranında aynı tuzağa düşülmüştü).
        $rows = DB::select(<<<'SQL'
            SELECT DISTINCT ON (metric, scope) metric, scope, value
              FROM metric_snapshots
             ORDER BY metric, scope, id DESC
        SQL);

        $breaches = [];

        foreach ($rows as $row) {
            $metric = Metric::tryFrom((string) $row->metric);

            // TANINMAYAN METRİK ATLANIR, uydurma eşik UYGULANMAZ:
            // enum'dan silinmiş eski bir metrik satırı için eşik
            // uydurmak yanlış uyarı üretirdi.
            if ($metric === null) {
                continue;
            }

            $value = (float) $row->value;

            if (! $metric->breaches($value)) {
                continue;
            }

            $scope = (string) $row->scope;
            $tenantId = MetricScope::tenantIdOf($scope);

            $breaches[] = [
                'key' => $this->keyFor($metric, $scope, $tenantId),
                'metric' => $metric,
                'scope' => $scope,
                'value' => $value,
                'tenantId' => $tenantId,
            ];
        }

        return $breaches;
    }

    private function keyFor(Metric $metric, string $scope, ?string $tenantId): string
    {
        if ($tenantId !== null) {
            return AlertKey::metricForTenant($metric, $tenantId);
        }

        $connectionId = MetricScope::connectionIdOf($scope);

        if ($connectionId !== null) {
            return AlertKey::metricForConnection($metric, $connectionId);
        }

        return AlertKey::metricForSystem($metric);
    }

    // ---------------------------------------------------------------- alıcı

    /**
     * Uyarıyı kim alır.
     *
     * KİRACI kapsamlı uyarı o kiracının SAHİPLERİNE gider; sistem ve
     * BAĞLANTI kapsamlı uyarı YÖNETİCİYE. Bağlantı uyarısı da yöneticiye
     * gider çünkü api gecikmesi ve 429 satıcının düzeltebileceği şeyler
     * değildir — altyapı sorunudur.
     *
     * @param  array{metric: Metric, scope: string, tenantId: ?string}  $breach
     * @return list<string>
     */
    private function recipientsFor(array $breach): array
    {
        if ($breach['tenantId'] !== null) {
            return $this->tenantOwnerEmails($breach['tenantId']);
        }

        $admin = config('entegrasyon.alerts.admin_email');

        return is_string($admin) && trim($admin) !== '' ? [trim($admin)] : [];
    }

    /**
     * Kiracının sahip e-postaları.
     *
     * `tenant_users` ÜZERİNDEN okunur — kiracıya bağlı gerçek üyelik
     * budur. `DB::table()` global scope'a TABİ DEĞİLDİR; kiracı filtresi
     * AÇIKÇA yazılır ve testi vardır (bu boşluk projede dört turda çıktı).
     *
     * @return list<string>
     */
    private function tenantOwnerEmails(string $tenantId): array
    {
        $emails = DB::table('tenant_users')
            ->join('users', 'users.id', '=', 'tenant_users.user_id')
            ->where('tenant_users.tenant_id', $tenantId)
            ->where('tenant_users.role', 'owner')
            // DAVETİ KABUL ETMEMİŞ ÜYEYE GÖNDERİLMEZ: adres doğrulanmış
            // sayılmaz ve uyarı yabancı bir gelen kutusuna düşerdi.
            ->whereNotNull('tenant_users.accepted_at')
            ->pluck('users.email')
            ->all();

        return array_values(array_filter(array_map('strval', $emails)));
    }

    // ---------------------------------------------------------------- çıpa

    /**
     * Uyarıyı bugüne yaz — yazabildiysek gönderme hakkı BİZDE.
     *
     * `insertOrIgnore` yarışı çözer: ikinci tur satırı yazamaz, `false`
     * döner ve göndermez. Tekillik `(alert_key, sent_on)`.
     *
     * @param  array{key: string, metric: Metric, value: float, tenantId: ?string}  $breach
     */
    private function claim(array $breach, int $recipientCount): bool
    {
        try {
            $inserted = DB::table('alert_deliveries')->insertOrIgnore([
                'id' => AlertDelivery::generateUuidV7(),
                'tenant_id' => $breach['tenantId'],
                'alert_key' => $breach['key'],
                'channel' => 'mail',
                'recipient_count' => $recipientCount,
                'observed_value' => $breach['value'],
                'threshold_value' => $breach['metric']->threshold(),
                'sent_on' => now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            // ÇIPA YAZILAMADIYSA GÖNDERİLMEZ: tekrar korumasız gönderim
            // her turda yeniden e-posta demektir ve o, uyarıyı kaçırmaktan
            // KÖTÜDÜR.
            Log::warning('alerts.claim_failed', [
                'alert_key' => $breach['key'],
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return $inserted > 0;
    }
}
