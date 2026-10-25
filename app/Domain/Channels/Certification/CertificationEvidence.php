<?php

declare(strict_types=1);

namespace App\Domain\Channels\Certification;

/**
 * S15 · GERÇEK doğrulamaların kanıtlı kaydı (SANDBOX / PRODUCTION / FAILED)
 * ve hücre notları. Koddan çıkarılamayan tek bilgi budur; elle yazılır ve
 * her satır bir kanıta dayanır (kim, ne zaman, ne görüldü, commit).
 *
 * KURAL: emin olunmayan satır YAZILMAZ. "Muhtemelen çalışıyor" bir kayıt
 * değildir; o hücre koddan/testten gelen durumda kalır. Aynı hücrede birden
 * çok kayıt varsa EN YENİ tarih geçerlidir (FAILED sonra düzelirse yeni
 * VERIFIED kaydı eklenir, eskisi silinmez — tarihçe).
 *
 * Kaynak: memory `entegrasyon-projesi.md` + git geçmişi (25 Eki 2026 ölçüldü).
 */
final class CertificationEvidence
{
    /**
     * @return list<array{channel: string, capabilities: list<ChannelCapability>, status: CertificationStatus, date: string, by: string, evidence: string}>
     */
    public static function records(): array
    {
        return [
            [
                'channel' => 'trendyol',
                'capabilities' => [
                    ChannelCapability::AUTH,
                    ChannelCapability::PUBLISHING,
                    ChannelCapability::INVENTORY,
                    ChannelCapability::ORDER_IMPORT,
                    ChannelCapability::CANCELLATION,
                ],
                'status' => CertificationStatus::PRODUCTION_VERIFIED,
                'date' => '2026-10-06',
                'by' => 'Ahmet Hamdi Kilic (onay) + Claude',
                'evidence' => 'Gerçek Trendyol satıcı hesabında uçtan uca: ürün gönderme → onay, stok 1 kanala gitti (onSale doğrulandı), gerçek sipariş düştü, iptal düştü. Bulunan hatalar canlıda düzeltildi: 1ecbaae, 7f96277, 0077750.',
            ],
            [
                'channel' => 'etsy',
                'capabilities' => [ChannelCapability::AUTH],
                'status' => CertificationStatus::FAILED,
                'date' => '2026-10-24',
                'by' => 'Claude (açılış denetimi)',
                'evidence' => '34Devs test bağlantısında canlı ping: Etsy "API key not found or not active" (403). Uygulama Etsy geliştirici portalında aktif değil; kanal CHANNELS_WITHHELD=etsy ile sunulmuyor.',
            ],
        ];
    }

    /**
     * Hücre notu — durum DEĞİŞTİRMEZ, yalnız yönetim ekranında açıklar.
     *
     * @return list<array{channel: string, capability: ChannelCapability, note: string}>
     */
    public static function notes(): array
    {
        return [
            [
                'channel' => 'idefix',
                'capability' => ChannelCapability::SHIPMENT,
                'note' => 'Bilinçli yazılmadı (docs/IDEFIX-API-NOTLARI.md): takip numarası göndermek platform anlaşmalı siparişi satıcı anlaşmasına geçirip kargo bedelini satıcıya kaydırabilir.',
            ],
            [
                'channel' => 'pazarama',
                'capability' => ChannelCapability::PUBLISHING,
                'note' => 'Yalnız yeni ürün açma: belgede ürün içeriği güncelleme ucu yok (docs/YENI-KANALLAR-API-NOTLARI.md); güncelleme gerekçeli reddedilir.',
            ],
        ];
    }

    /**
     * Kanal DIŞI ama aynı ekranda izlenen dış servisler (kargo, e-fatura).
     *
     * @return list<array{service: string, status: CertificationStatus, date: string, evidence: string}>
     */
    public static function services(): array
    {
        return [
            ['service' => 'Yurtiçi Kargo', 'status' => CertificationStatus::SANDBOX_VERIFIED, 'date' => '2026-10-23', 'evidence' => 'Gerçek kodla herkese açık test sunucusunda: doğrula / aç / 60020 tekrar / NOP / iptal ×2 / 82519. Doğrulanmadı: IND/ISR/DLV kodları, docId yeri.'],
            ['service' => 'Aras Kargo', 'status' => CertificationStatus::SANDBOX_VERIFIED, 'date' => '2026-10-23', 'evidence' => 'Gerçek kodla herkese açık test sunucusunda: doğrula / aç + etiket / tekrar aynı no / iptal ×2. Doğrulanmadı: canlıda PDF etiket, desi.'],
            ['service' => 'Nilvera e-fatura', 'status' => CertificationStatus::MOCK_TESTED, 'date' => '2026-10-24', 'evidence' => 'Belgedeki örnek yanıtlarla sahte testler; gerçek hesap/test ortamında denenmedi.'],
        ];
    }
}
