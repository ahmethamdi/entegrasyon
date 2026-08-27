<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\Observability\Metric;
use App\Support\Observability\MetricScope;
use App\Support\Observability\MetricScopeKind;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Eşik aşımı uyarısı — §11 · "eşik aşımında e-posta".
 *
 * ─────────────────────────────────────────────────────────────────────
 * KONU SATIRI METRİĞİ VE DEĞERİ TAŞIR
 * ─────────────────────────────────────────────────────────────────────
 * "Entegrasyon uyarısı" gibi genel bir konu, gelen kutusunda üst üste
 * duran üç uyarıyı ayırt edilemez kılar ve satıcı hangisinin acil
 * olduğunu ancak hepsini açarak öğrenir. Konu satırı tek başına
 * okunabilir olmalı: eşleştirme ekranındaki "eksik zorunlu öznitelik
 * ADIYLA gösterilir" kuralının aynısı.
 *
 * DEĞER VE EŞİK BİRLİKTE GÖSTERİLİR: "5 adet" tek başına bir şey
 * söylemez; "5 adet (eşik 3)" satıcıya ne kadar aştığını söyler.
 *
 * ─────────────────────────────────────────────────────────────────────
 * KUYRUĞA ATILMAZ (`ShouldQueue` UYGULANMAZ)
 * ─────────────────────────────────────────────────────────────────────
 * Uyarı taraması ZATEN zamanlanmış bir komutta çalışır; oradan kuyruğa
 * atmak, gönderimi taramanın çıpasından (aynı gün tekilliği) AYIRIR ve
 * iş başarısız olursa çıpa yazılmış ama e-posta hiç gitmemiş olurdu —
 * üstelik kaydı gören hiç kimse bunu anlayamazdı. Senkron gönderim
 * hatayı ANINDA görünür kılar ve `AlertMailer` onu günlüğe yazar.
 */
final class MetricAlertMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Metric $metric,
        public readonly string $scope,
        public readonly float $value,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                '[Entegrasyon] %s eşiği aşıldı: %s',
                $this->metric->label(),
                $this->formattedValue(),
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.metric-alert',
            with: [
                'label' => $this->metric->label(),
                'value' => $this->formattedValue(),
                'threshold' => $this->metric->unit()->format($this->metric->threshold()),
                'scopeText' => $this->scopeText(),
                'advice' => $this->advice(),
            ],
        );
    }

    public function formattedValue(): string
    {
        return $this->metric->unit()->format($this->value);
    }

    /**
     * Kapsamın satıcıya anlamlı hâli.
     *
     * Ham kapsam metni (`tenant:01a0...`) kullanıcıya UUID gösterirdi ve
     * o hiçbir şey ifade etmez.
     */
    private function scopeText(): string
    {
        return match ($this->metric->scopeKind()) {
            MetricScopeKind::TENANT => 'Hesabınız',
            MetricScopeKind::CONNECTION => $this->connectionText(),
            MetricScopeKind::SYSTEM => 'Sistem geneli',
        };
    }

    /**
     * Bağlantının ADI — "Bir kanal bağlantısı" DEĞİL.
     *
     * ⚠️ BU AYRIM §25 İLE ZORUNLU HÂLE GELDİ. Bağlantı kapsamlı uyarılar
     * eskiden YALNIZCA yöneticiye gidiyordu ve o, kimliği panelden
     * bulabilirdi. Token uyarıları SATICIYA gider (§25 istisnası) ve üç
     * mağazası olan bir satıcı "bir kanal bağlantısı" cümlesinden
     * HANGİSİNİ yeniden yetkilendireceğini çıkaramaz — uyarı okunur ama
     * eylem üretmez.
     *
     * ⚠️ UUID GÖSTERİLMEZ, ETİKET GÖSTERİLİR: satıcı bağlantısını kendi
     * verdiği adla tanır. Ad okunamazsa jenerik metne düşülür — uyarının
     * kendisi bir isim yüzünden kaybolmamalıdır.
     */
    private function connectionText(): string
    {
        $connectionId = MetricScope::connectionIdOf($this->scope);

        if ($connectionId === null) {
            return 'Bir kanal bağlantısı';
        }

        // `runAsSystem()`: tarama bağlam OLMADAN koşar ve kapsamlı bir
        // sorgu burada izolasyon istisnası fırlatırdı.
        $label = TenantContext::runAsSystem(
            fn () => DB::table('channel_connections')->where('id', $connectionId)->value('label'),
        );

        return is_string($label) && $label !== ''
            ? $label.' bağlantısı'
            : 'Bir kanal bağlantısı';
    }

    /**
     * NE YAPILACAĞI SÖYLENİR — sayı tek başına eylem üretmez.
     *
     * Ölü mektup ekranındaki "hata sınıfı ve tavsiye gösterilir"
     * kuralının aynısı: `AUTHENTICATION` ile `VALIDATION` kullanıcıya
     * FARKLI iş yaptırır ve tek bir "sorun var" mesajı satıcıyı panelde
     * neye bakacağını bilmeden bırakır.
     */
    private function advice(): string
    {
        return match ($this->metric) {
            Metric::OVERSOLD_UNITS,
            Metric::OVERSOLD_VARIANTS => 'Stok ekranında negatif bakiyeli ürünleri inceleyin; '
                .'satış gerçekleşmiş ve stok eksiye düşmüştür.',

            Metric::DEAD_OPERATIONS => 'Başarısız işlemler ekranından hataları inceleyip '
                .'tek tıkla yeniden deneyebilirsiniz.',

            Metric::DRIFT_RATE => 'Mutabakat ekranında sürüklenen listelemeleri inceleyin; '
                .'elle inceleme bekleyenler en üstte listelenir.',

            Metric::API_LATENCY_P95,
            Metric::RATE_LIMIT_HITS => 'Kanallar ekranından bağlantı sağlığını kontrol edin.',

            // ⚠️ §25 · TOKEN TAVSİYESİ EYLEM SÖYLER, EKRAN DEĞİL.
            //
            // Varsayılan metne bırakılsaydı satıcı "sistem sağlığı
            // ekranından ayrıntıları görebilirsiniz" okur, oraya gider ve
            // yapabileceği bir şey BULAMAZDI — o ekran salt okunurdur.
            // Yapılması gereken tek şey mağazayı YENİDEN BAĞLAMAKTIR ve
            // bunu yalnızca satıcı yapabilir (uyarının ona gitmesinin
            // sebebi de budur).
            Metric::TOKEN_EXPIRING_SOON => 'Kanallar ekranından bu mağazayı yeniden '
                .'bağlayın; yetki süresi dolduğunda senkron SESSİZCE durur.',

            Metric::TOKEN_REFRESH_FAILURES => 'Yetki yenileme tekrar tekrar başarısız oluyor. '
                .'Kanallar ekranından mağazayı yeniden bağlayın; kanal '
                .'tarafında izin iptal edilmiş olabilir.',

            // Kota YÖNETİCİYE gider ve tavsiye de ona göredir: satıcının
            // elinde bir düğme YOKTUR (§21 · P2).
            Metric::CHANNEL_DAILY_QUOTA_USED => 'Kanalın günlük istek kotası dolmak üzere. '
                .'Stok itme sıklığı düşürülmeli veya varyantlar tek çağrıda '
                .'gruplanmalı (§21).',

            default => 'Sistem sağlığı ekranından ayrıntıları görebilirsiniz.',
        };
    }
}
