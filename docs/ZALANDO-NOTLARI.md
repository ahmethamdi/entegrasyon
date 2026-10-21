# Zalando — entegratör erişimi araştırması (21 Eki 2026)

**Sonuç: şimdilik kod yok.** Zalando'ya bağlantı iki programdan geçer ve ikisi de 34Pazar'ın
bugünkü hedef müşterisine (küçük/orta satıcı, TR + DE) uymuyor.

## 1. Zalando Partner Program (zDirect)
- Satıcı Zalando'ya **başvurarak** kabul edilir; açık pazar yeri değildir. Moda/ayakkabı/aksesuar
  kategorisinde marka sahibi ya da yetkili dağıtıcı olmak, AB'de depo ve iade süreci, Almanca müşteri
  hizmeti, belli ürün kalitesi ve görsel standartları beklenir.
- Teknik bağlantı **zDirect API** (merchant API) ile yapılır: ürün, stok/fiyat (offers), sipariş ve kargo.
  Erişim satıcı onboarding'i sırasında açılır; API kimlik bilgileri satıcının zDirect hesabına bağlıdır.
- Entegratörler (ör. ChannelAdvisor, Tradebyte, Productsup) Zalando'nun **onaylı entegratör
  listesindedir**; listeye girmek Zalando ile ayrı bir iş ortaklığı süreci gerektirir.

### İkincil kaynaklardan (21 Eki 2026 arama, resmî belge değil)
- zDirect API OAuth 2.0 (client credentials, kapsam `zapi_partner`, partner portalında yapılandırılır) —
  ecosire.com "Odoo Zalando connector" rehberi; Zalando belgesiyle DOĞRULANMADI.
- Şartlar (aynı kaynak + zunapro.com): bütün ürünlerde GTIN/EAN, AB ürün uyumu, Zalando beden
  standartları, fotoğraf standardı, aynı/ertesi gün kargo (ya da ZFS), Zalando uyumlu iade etiketi;
  entegrasyon zDirect API ya da **onaylı bağlayıcı** üzerinden.
- Partner kademeleri: Standard, Premium, Connected Retail.

## 2. Connected Retail
- Fiziksel mağazası olan perakendeciler için; stok mağaza sisteminden (ERP/POS) gelir. Bizim
  ürünümüzün kapsamı dışında.

## 34Pazar için değerlendirme
- Müşterilerimizin çoğu Zalando'ya kabul şartlarını karşılamaz (marka, AB deposu, iade lojistiği).
- Zalando'nun onaylı entegratör olmadan genel bir "API anahtarını gir, bağlan" yolu yok.
- **Öneri:** Zalando'da satan ilk gerçek müşteri gelene kadar bekle; o müşterinin zDirect API
  erişimiyle (satıcı kendi kimliğini verir) özel bağlantı yazılır. Onaylı entegratör başvurusu,
  ≥ birkaç Zalando satıcısı olduğunda anlamlı.
- **Doğrulanmadı:** zDirect API belgeleri herkese açık değil; bu not Zalando partner sayfaları ve
  entegratör duyurularına dayanıyor. Gerçek şartları ilk satıcıyla birlikte netleştir.
