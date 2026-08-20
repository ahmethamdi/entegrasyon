<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\Observability\Metric;
use App\Support\Observability\MetricScopeKind;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

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
            MetricScopeKind::CONNECTION => 'Bir kanal bağlantısı',
            MetricScopeKind::SYSTEM => 'Sistem geneli',
        };
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

            default => 'Sistem sağlığı ekranından ayrıntıları görebilirsiniz.',
        };
    }
}
