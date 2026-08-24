<?php

declare(strict_types=1);

namespace App\Domain\Sync\Support;

use App\Domain\Channels\Models\ChannelConnection;
use App\Domain\Channels\Support\CredentialVault;
use App\Support\Logging\PayloadRedactor;
use App\Support\Tenancy\TenantContext;
use Throwable;

/**
 * KALICI HATA METNİNİ MASKELER — sırrın son çıkış kapısı.
 *
 * Mimari Karar Dokümanı v2.2 · §11 · "Kişisel veri ve sır maskeleme".
 *
 * ─────────────────────────────────────────────────────────────────────
 * NEDEN AYRI BİR SINIF — ÜÇ YOL, TEK KURAL
 * ─────────────────────────────────────────────────────────────────────
 * Kanal hatası ÜÇ ayrı kolona kalıcı yazılır ve üçü de panele gider:
 *   `channel_connections.last_error`  (sağlık kontrolü)
 *   `sync_attempts.error_message`     (gönderim denemesi)
 *   `listing_sync_states.last_error`  (satırın son durumu)
 *
 * Maskeleme her çağrı yerinde elle yazılsaydı biri unutulur ve o yol
 * SESSİZCE sızdırırdı — üstelik sızıntı ancak kanal 401 gövdesinde
 * anahtarı yansıttığında görünür, yani normal çalışmada hiç fark
 * edilmezdi. `MetricScope` ve `Metric::threshold()` ile aynı gerekçe:
 * biçimi ve kuralı TEK sınıf bilir.
 *
 * ─────────────────────────────────────────────────────────────────────
 * NEDEN `PayloadRedactor` DOĞRUDAN ÇAĞRILMIYOR
 * ─────────────────────────────────────────────────────────────────────
 * Katman 2 "bilinen sır değerleri" ister ve o değerler bağlantının
 * kasasındadır. `PayloadRedactor` bilinçli olarak bağımsızdır — kasayı
 * tanımaz ve tanımamalıdır (§11: "HTTP istemcisinin parçası değil").
 * Bu sınıf ikisini birleştiren ince katmandır.
 *
 * ─────────────────────────────────────────────────────────────────────
 * KİMLİK BİLGİSİ `runAsSystem()` İLE OKUNUR — PROJEDE YAŞANMIŞ HATA
 * ─────────────────────────────────────────────────────────────────────
 * `channel_credentials` kiracıya göre kapsanır, ama bu sınıf kuyruk
 * işinden ve `runAsSystem()` taramasından da çağrılır; oralarda bağlam
 * YOKTUR. Kapsanmış sorgu istisna fırlatır, sır listesi BOŞ döner ve
 * katman 2 sessizce devre dışı kalır — maskeleme yapıldığını sanırken
 * hiçbir şey maskelenmez. Aynı hata `97a7eb7`'de üretimi etkilemişti.
 *
 * OKUMA BAŞARISIZSA METİN YİNE DE KATMAN 1'DEN GEÇER: kasa okunamıyor
 * diye ham metin yazmak, korumanın erişilemezliğini korunan şeyden
 * büyük bir zarara çevirirdi (koruma katmanının Redis kuralıyla aynı
 * aileden — ama buradaki güvenli taraf GEÇİRMEK değil MASKELEMEKTİR).
 */
final class ChannelErrorText
{
    /**
     * Uzun hata metinleri kolonu ve ekranı boğar; kesme maskelemeden
     * SONRA yapılır — önce kesilseydi sırrın yarısı metinde kalır ve
     * katman 2 onu artık eşleştiremezdi.
     */
    private const MAX_LENGTH = 2000;

    public function __construct(
        private readonly CredentialVault $vault,
        private readonly PayloadRedactor $redactor,
    ) {}

    /**
     * Kalıcı yazılacak hata metnini maskeler.
     *
     * @param  ChannelConnection|null  $connection  Bağlantı bilinmiyorsa
     *                                              yalnızca katman 1 çalışır.
     */
    public function redact(?ChannelConnection $connection, ?string $message): ?string
    {
        if ($message === null || $message === '') {
            return $message;
        }

        $redacted = $this->redactor->redact(
            $message,
            $connection === null ? [] : $this->secretsFor($connection),
        );

        $text = is_string($redacted) ? $redacted : $message;

        return mb_substr($text, 0, self::MAX_LENGTH);
    }

    /**
     * Bağlantının çözülmüş sır DEĞERLERİ — katman 2'nin girdisi.
     *
     * @return list<string>
     */
    private function secretsFor(ChannelConnection $connection): array
    {
        try {
            $secrets = TenantContext::runAsSystem(
                fn (): array => $this->vault->read($connection),
            );
        } catch (Throwable) {
            // Kasa okunamadı: katman 1 yine çalışır (sınıf başlığı).
            return [];
        }

        $values = [];

        foreach ($secrets as $value) {
            if (is_string($value) && $value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }
}
