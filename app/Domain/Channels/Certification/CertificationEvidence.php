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
            ['service' => 'HepsiJet', 'status' => CertificationStatus::MOCK_TESTED, 'date' => '2026-11-13', 'evidence' => 'YALNIZ SAHTE TEST: HepsiJet partner belgesindeki (v1.9.2/v2.1) örnek yanıtlarla Http::fake — token / aç + ZPL / BARCODE_EXIST / takip durumları / iptal. Test ya da canlı sunucuda HİÇ denenmedi; yeni bağlantıya varsayılan kapalı (CARRIERS_UNVERIFIED_ENABLED).'],
            ['service' => 'MNG Kargo', 'status' => CertificationStatus::MOCK_TESTED, 'date' => '2026-11-13', 'evidence' => 'YALNIZ SAHTE TEST: MNG API Zone resmî Swagger örnek yanıtlarıyla Http::fake — token / CBS il-ilçe kodu / createOrder + createbarcode / 3002 tekrar (açık kaynak notu) / durum kodları 1–8 / iptal. Sandbox ya da canlıda HİÇ denenmedi; platform anahtarı yok, yeni bağlantıya kapalı.'],
            ['service' => 'Logo ERP', 'status' => CertificationStatus::MOCK_TESTED, 'date' => '2026-11-13', 'evidence' => 'YALNIZ SAHTE TEST: Logo Objects REST belgesindeki (docs.logo.com.tr arşivi) token ve salesOrders örnekleriyle Http::fake — token / satış siparişi / 401 tazeleme / SSRF süzgeci. Gerçek Logo kurulumunda HİÇ denenmedi; çözüm ortağı ClientID yok, yeni bağlantıya kapalı.'],
            ['service' => 'Mikro ERP', 'status' => CertificationStatus::MOCK_TESTED, 'date' => '2026-11-13', 'evidence' => 'YALNIZ SAHTE TEST: apidocs.mikro.com.tr örnek yanıt modelleriyle Http::fake — MD5(tarih şifre) / CariListesiV3 doğrulama / SiparisKaydetV2 / Geçersiz ApiKey, Şifre Hatalı, satır hatası. Gerçek Mikro kurulumunda HİÇ denenmedi; yeni bağlantıya kapalı.'],
            ['service' => 'Nilvera e-fatura', 'status' => CertificationStatus::MOCK_TESTED, 'date' => '2026-10-24', 'evidence' => 'Belgedeki örnek yanıtlarla sahte testler; gerçek hesap/test ortamında denenmedi.'],
        ];
    }
}
