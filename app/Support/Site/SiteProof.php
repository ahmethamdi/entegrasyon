<?php

declare(strict_types=1);

namespace App\Support\Site;

use App\Domain\Channels\Certification\CertificationEvidence;
use App\Domain\Channels\Certification\CertificationStatus;
use App\Domain\Channels\Certification\ChannelCapability;
use App\Domain\Channels\Certification\ChannelCertification;

/**
 * Tanıtım sitesinin "ne kadar doğrulandı" kaynağı (6 Kas 2026 · site sprint 1).
 *
 * SİTEDE ELLE DURUM YAZILMAZ. Kanal, kargo ve e-fatura için gösterilen
 * her etiket S15 sertifikasyon matrisinden (`ChannelCertification`,
 * `CertificationEvidence`) okunur. Önceki site "Nilvera ile e-fatura"
 * ve her kanalda "Çalışır" diyordu; matris e-faturayı ve on dört kanalı
 * yalnız sahte testli gösteriyordu. Yeni bir gerçek doğrulama
 * `CertificationEvidence`'a yazıldığı an site de değişir.
 */
final class SiteProof
{
    /** Kanal özetini belirleyen çekirdek yetenekler: stok ve sipariş. */
    public const CORE = [ChannelCapability::INVENTORY, ChannelCapability::ORDER_IMPORT];

    public function __construct(private readonly ChannelCertification $certification) {}

    /**
     * Kanalın özeti — çekirdek hücrelerin EN ZAYIFI. Trendyol'da stok ve
     * sipariş gerçek hesapta doğrulandı → "Canlıda doğrulandı"; yalnız
     * stoğu doğrulanmış bir kanal "canlı" diye geçmez.
     */
    public function channelLevel(string $code): CertificationStatus
    {
        $weakest = null;

        foreach (self::CORE as $capability) {
            $status = $this->certification->cell($code, $capability)['status'];
            if ($weakest === null || $status->evidenceRank() < $weakest->evidenceRank()) {
                $weakest = $status;
            }
        }

        return $weakest ?? CertificationStatus::NOT_IMPLEMENTED;
    }

    public function cell(string $code, ChannelCapability $capability): CertificationStatus
    {
        return $this->certification->cell($code, $capability)['status'];
    }

    /**
     * Kanal dışı servisler (kargo firmaları, e-fatura) — kanıt kaydından.
     *
     * @return list<array{name: string, status: CertificationStatus, date: string}>
     */
    public function services(): array
    {
        return array_map(
            fn (array $s): array => ['name' => $s['service'], 'status' => $s['status'], 'date' => $s['date']],
            CertificationEvidence::services(),
        );
    }

    /** Ada göre servis durumu ("Nilvera e-fatura"); kayıt yoksa null (iddia yok). */
    public function service(string $name): ?CertificationStatus
    {
        foreach ($this->services() as $service) {
            if ($service['name'] === $name) {
                return $service['status'];
            }
        }

        return null;
    }

    /**
     * Gerçek satıcı hesabında doğrulanmış kanallar (ana sayfa şeridi).
     *
     * @param  list<array{code: string, name: string, available: bool}>  $channels
     * @return list<string>
     */
    public function liveChannelNames(array $channels): array
    {
        $out = [];
        foreach ($channels as $channel) {
            if ($channel['available'] && $this->channelLevel($channel['code']) === CertificationStatus::PRODUCTION_VERIFIED) {
                $out[] = $channel['name'];
            }
        }

        return $out;
    }

    /**
     * "Durumlar ne demek" listesi — sitede gösterilen etiketlerin hepsi.
     *
     * @return list<array{label: string, tone: string, note: string}>
     */
    public static function legend(): array
    {
        $out = [];
        foreach ([CertificationStatus::PRODUCTION_VERIFIED, CertificationStatus::SANDBOX_VERIFIED, CertificationStatus::MOCK_TESTED, CertificationStatus::NOT_IMPLEMENTED] as $status) {
            $out[] = ['label' => $status->publicLabel(), 'tone' => $status->publicTone(), 'note' => $status->publicNote()];
        }

        return $out;
    }
}
