# Pilot satıcı doğrulaması (Sprint 2)

Amaç: Hepsiburada, n11 ve kargoyu (Yurtiçi/Aras) **gerçek satıcı hesabında**
uçtan uca doğrulamak; Nilvera'da test ortamında gerçek bir fatura kesmek.
Bitince sonuç `CertificationEvidence::records()`'a kanıtla yazılır ve sitedeki
etiket kendiliğinden "Canlıda doğrulandı" olur (bkz. `SERTIFIKASYON.md`).

Takvim: **7–19 Kasım pilot**, 20 Kasım son düzeltme deploy'u,
**21–30 Kasım dondurma** (Black Friday 23–27 Kasım; canlıya yalnız acil yama).

## 0. Önce (dış bağımlılık — en uzun süren)

- [ ] **Hepsiburada entegratör kaydı.** HB'de bir pilot satıcının panelinden:
      Yardım Merkezi → Talepler → yeni talep → "API ENTEGRASYON – API
      Entegratör Yetkilendirme İşlemleri". Önce **test (SIT)** bilgileri
      gelir, test geçince aynı yoldan canlı istenir; yetki ~2 saatte açılır.
      Gelen entegratör kullanıcı adı bağlantı formundaki
      "Entegratör kullanıcı adı" alanına yazılır (`User-Agent`).
- [ ] **Nilvera test hesabı** (apitest.nilvera.com) → API anahtarı. Panelde
      e-fatura hesabı bağlanırken ortam **Test** seçilir; test faturası GİB'e
      gitmez.
- [ ] Pilot satıcılarla yazılı mutabakat (e-posta yeter): ücretsiz dönem,
      ilk hafta yalnız okuma, stok yazma ancak satıcının onayıyla, verilerin
      KVKK kapsamında yalnız hizmet için işlendiği.

## 1. Pilot satıcı seçimi (2–3 satıcı)

- Hepsiburada **ya da** n11'de aktif, en az ~20 ürün, haftada birkaç sipariş.
- Ürünlerinde SKU (satıcı stok kodu) dolu olan tercih edilir.
- Yurtiçi ya da Aras ile **kendi kargo sözleşmesi** olan en az bir satıcı.
- Trendyol'da da satan biri olursa iyi olur: aynı ürün iki kanalda,
  stok birlikte düşüyor mu görülür.

## 2. Satıcıdan istenenler

| Kanal | Bilgi | Nereden |
|---|---|---|
| Hepsiburada | Merchant ID, Servis Anahtarı | Satıcı paneli → Bilgilerim → Entegrasyon → Entegratör Bilgileri |
| n11 | API anahtarı + şifre, mağaza adı | Satıcı Ofisi → Hesabım → API Hesapları (şifre e-postayla gelir) |
| Yurtiçi / Aras | Müşteri kodu, kullanıcı adı, şifre | Kargo firmasının satıcıya verdiği entegrasyon bilgisi |

Bilgiler panele satıcının kendisi girer (ya da ekran paylaşımında birlikte).
E-posta/WhatsApp ile anahtar istenmez.

## 3. Adımlar — her satıcı için

1. **Bağla, yazma KAPALI.** Kanal bağlanınca stok/fiyat/ürün yazma izinleri
   kapalı doğar (ADR-010). İlk hafta öyle kalır.
2. **Okuyarak doğrula:**
   ```
   docker compose exec -T app php artisan channels:pilot-check <bağlantı-id> --days=14
   ```
   Kanala yalnız okuma isteği gider; sipariş bizde kaydedilmez. Rapor
   `storage/app/pilot-check/` altında (alıcı adı/adresi yok).
   - ✓ Bağlantı → kimlik doğru.
   - Sipariş "N/N ayrıştı" olmalı. Ayrışmayan varsa raporda hata metni →
     adaptör düzeltmesi.
   - Eşleşmeyen SKU'lar → ürünleri içe aktar ya da eşle, komutu tekrar çalıştır.
3. **Ürünleri içe aktar** (panel → Ürünler → kanaldan içe aktar). Sonra
   `pilot-check` stok bölümü "N ilanın N tanesi bizimle aynı" demeli.
   Fark varsa yazma açılmadan önce nedeni bulunur.
4. **Siparişleri izle (2–3 gün).** Panelde düşen siparişleri satıcının kanal
   paneliyle yan yana karşılaştır: adet, tutar, durum, sipariş saati
   (HB'de saat dilimi henüz doğrulanmadı — `HEPSIBURADA-API-NOTLARI.md`).
   İptal ya da iade gelirse onu da not et.
5. **Stok yazmayı aç — fark SIFIRKEN.** `pilot-check` stokta fark yokken satıcının
   onayıyla bağlantının stok iznini aç. Açılış anında kanala giden değerler
   zaten aynı olduğundan kanalda hiçbir şey değişmez. Sonra satıcının seçtiği
   **tek üründe** panelden stoğu 1 azalt → kanalda görüldü mü? Geri al.
6. **Kargo (kendi sözleşmesi olan satıcıda).** Gerçek bir siparişte panelden
   gönderi oluştur → takip numarası geldi mi, kanala kargo bildirimi gitti mi,
   satıcının kanal panelinde görünüyor mu?
7. **Nilvera (test ortamı).** Pilot siparişlerden biri için panelden e-fatura
   iste → test ortamında fatura oluştu mu, PDF açılıyor mu, tutar/KDV doğru mu?

## 4. Durdurma kuralı

Kanala yanlış stok ya da fiyat gittiğini gören herkes **o bağlantının yazma
iznini hemen kapatır** (panel → Kanallarım → bağlantı → "Stok gönderimi"). Sonra
nedenine bakılır. Satıcıya aynı gün haber verilir.

## 5. Kanıtı yazmak

Her doğrulanan hücre için `CertificationEvidence::records()`'a satır:
kanal, yetenekler, `PRODUCTION_VERIFIED` (Nilvera için `SANDBOX_VERIFIED`),
tarih, kim, ne görüldü (pilot-check rapor adı, sipariş sayısı, commit).
Emin olunmayan şey yazılmaz. Okuma ile doğrulanan (AUTH, sipariş alma) ile
yazma ile doğrulanan (stok gönderme, kargo bildirimi) ayrı satırlarda.
