# 34Pazar — özellik önerileri (24 Ekim 2026)

Ajans ekibi gözüyle (ürün · satış · Shopify/pazaryeri). Kod değişikliği yok, öneri belgesi.

**Kaynaklar:** 12 rakibin paket sayfası (erişim 24.10.2026; Entegra, Platin360, Sentos, Dopigo,
StockMount, BirFatura, Yengeç, Sopyo, ikas, Ticimax, Akınsoft — URL'ler aşağıda),
`docs/RAKIP-YOL-HARITASI.md`, kod (`app/Domain/*`, `routes/web.php`) ölçümü.
Rakip fiyatları sayfada yazdığı gibidir; **"tahmin"** yazan her şey bizim tahminimizdir.

## Bizde bugün olan (kodda ölçüldü, 24.10)

15 kanal adaptörü (Trendyol, HB, n11, Çiçeksepeti, Pazarama, İdefix, Amazon, eBay, Etsy, OTTO,
Kaufland, Shopify, WooCommerce, ikas, Ticimax) · tek stok + fan-out + mutabakat · fiyat kuralları,
zarar koruması, süreli kampanya · TCMB kur çevirisi · sipariş, iade, iptal, soru-cevap · toplama
listesi + toplu etiket (`Fulfillment/Picking.vue`, `Batch.vue`) · Yurtiçi + Aras gönderi · e-fatura
(yalnız Paraşüt; **uygulama anahtarı yok, canlıda kapalı**) · tedarikçi XML içe aktarma · Excel
toplu düzenleme (dışa + içe) · katkı/kâr raporu, hakediş (yalnız **Trendyol** finans tipleri —
`TrendyolFinancialTypes`) · AI listeleme (anahtar bekliyor) · roller + ekip · firma seçici
(birden çok kiracı) · depo modeli var ama tek varsayılan depo (`warehouses_one_default_per_tenant`).

**Yok (ölçüldü):** set/paket ürün, buybox/rakip fiyat izleme, otomatik (rakibe göre) fiyat, açık
API (`routes/api.php` yok), mobil uygulama/barkod okutma, B2B bayi portalı, XML **çıktısı**,
ikinci e-fatura sağlayıcısı, muhasebe/ERP aktarımı, Yurtiçi/Aras dışında kargo, Temu/Ozon/Trendyol
yurtdışı, hesap kapatırken tam veri dışa aktarımı (yalnız ürün Excel'i var).

## 1) Rakiplerde olup bizde olmayanlar

| Özellik | Kimde var | Satıcıya değeri | Bizde altyapı | İş | Dış bağımlılık |
|---|---|---|---|---|---|
| Çalışan e-fatura (ikinci sağlayıcı) | Entegra (11 e-fatura), BirFatura, Sopyo, StockMount (Bien), Platin360 Starter | Yüksek — Türkiye'de satış şartı; fiyat sayfamızda var ama çalışmıyor | `InvoiceProvider` sözleşmesi hazır, Paraşüt örneği var | M | Açık API'li bir entegratörle hesap (aday: Nilvera, Uyumsoft, EDM — **API erişimi doğrulanmadı**) |
| Set / paket ürün (bileşen stoğundan düşer) | Entegra P1 | Yüksek — hediye seti, 2'li paket; elle yapılınca fazla satış | Stok hareketi + fan-out var; bileşen ilişkisi yok | M | Yok |
| Daha fazla kargo (HepsiJet, MNG/DHL eCom, Sürat, PTT) | Entegra (13 kargo), Platin360, Sentos Plus | Orta-yüksek — satıcının sözleşmesi Yurtiçi/Aras değilse kargo özelliğimiz işe yaramaz | `Shipping/Providers` kalıbı (Yurtiçi, Aras) hazır | M (firma başına) | Her firmanın API erişimi/test hesabı |
| Buybox analizi | Dopigo, Yengeç (1.000 ₺/ay ek) | Yüksek — Trendyol/HB'de satışın çoğu buybox'tan | Fiyat kuralı motoru var; rakip fiyat okuma yok | L | Pazaryeri buybox uçları (Trendyol/HB belgede **doğrulanmadı**) |
| Otomatik / rakibe göre fiyat | Entegra P2 ("akıllı fiyat"), Sentos Premium, Dopigo | Yüksek — buybox ile birlikte satılır | `PriceRuleCalculator` + zarar koruması hazır (alt sınır var) | M (buybox'tan sonra) | Buybox verisi |
| Muhasebe / ERP aktarımı (Logo, Mikro, Paraşüt ön muhasebe…) | Entegra P3 (21 muhasebe/ERP), Sentos Pro/Premium, Dopigo Muhasebe paketi, ikas Scale Plus | Orta-yüksek — büyük satıcı için şart | Sipariş + fatura verisi hazır | L (sistem başına) | Yazılım firmasıyla anlaşma / API erişimi |
| Açık API | Sentos Premium, Dopigo | Orta — ajanslar ve büyük satıcılar | Domain eylemleri hazır; API katmanı yok | M | Yok |
| Mobil uygulama / mobil depo (barkod okutma) | Dopigo (iOS/Android), Entegra P4 (mobil depo) | Orta — depo ekibi için | Toplama listesi + roller var | M (PWA ile — **tahmin**) | Yok (mağaza uygulaması yapılırsa Apple/Google hesabı) |
| Çoklu depo | Platin360 | Orta — depo + mağaza satanlar | `Warehouse` modeli var, tek varsayılan | M | Yok |
| WMS / fulfillment | Entegra P4 + WMS 30.000 ₺, Sentos Plus | Düşük-orta (bizim hedef kitle küçük satıcı) | Toplama listesi | L | Yok |
| B2B bayi portalı | Entegra P2 | Düşük-orta | Yok | L | Yok |
| Temu, Ozon, Trendyol yurtdışı | Entegra P5, Platin360, Sentos (Temu eklenti) | Orta — e-ihracat | Kanal kalıbı + kur çevirisi hazır | M (kanal başına) | Satıcı hesabı / API erişimi |
| XML çıktısı (bayilere ürün beslemesi) | Sentos (ücretli eklenti) | Düşük-orta | Katalog hazır | S | Yok |
| AI çeviri | Platin360 (15 çeviri ücretsiz) | Orta — yurtdışı kanallar için | AI listeleme altyapısı var | S | Anthropic anahtarı |

## 2) Rakiplerin boş bıraktığı açıklar ve bizim dürüstçe kapatabileceklerimiz

| Açık | Rakiplerde durum | Bizim yapabileceğimiz |
|---|---|---|
| Aylık plan | Yalnız Yengeç ve BirFatura aylık; diğerleri yıllık peşin, giriş 9.500–30.000 ₺/yıl | Zaten var (499 ₺/ay'dan) — sayfada en başa al |
| İade / cayma | Yalnız BirFatura (15 gün) yazıyor; Sentos "iade yok" | Kullanıcı kararı (24 Eki): ilk ödemeden 14 gün içinde tam iade — Stripe iade akışı test edilmeli |
| Hafta sonu destek | Entegratörlerin hepsi hafta içi gündüz; yalnız ikas/Ticimax 7/24 (ama onlar altyapı) | Kullanıcı kararı: WhatsApp + e-posta, hafta sonu dahil |
| SLA / çalışma süresi | Hiçbir paket sayfasında yok | Canlı durum sayfası (`/up` + `ops:watch` verisi). **Yüzde taahhüdü verme** — önce 3 ay ölç, sonra yaz |
| Ölçülebilir teknik söz | Yalnız Yengeç: "sipariş çekme 8 dk / 4 dk" | Yük testi: sipariş → tüm kanallarda stok p95 ≈ 7 sn (**yerel ölçüm**). Canlıda kanıt ekranı dolunca canlı rakamı yaz |
| Şeffaf ek ücret | Platin360/Sentos/StockMount "ücretli" diyor, fiyat yok | Ek kalem açılırsa fiyatı sayfada yaz |
| KDV belirsizliği | Sentos, Dopigo, Sopyo, Ticimax, ikas KDV yazmıyor | Fiyatın KDV durumunu tek cümleyle yaz (34Devs Almanya'dan faturalıyor — doğru ifadeyi muhasebeciye sor) |
| Veri çıkışı | StockMount 30 gün girişsizlikte silebilir, Sentos deneme sonrası siler | "Abonelik bitince verin X gün saklanır, istediğin an Excel/CSV indir" — tam hesap dışa aktarımı yazılmalı (S–M) |
| Kontör kilidi | StockMount hediye kontörü tek entegratöre bağlı | Fatura hakkını pakete sayı olarak koyuyoruz (kontör yok) — bunu vurgula |
| Altyapıların pazaryeri ücreti | ikas/Ticimax/Akınsoft pazaryerini üst pakete ya da ek ücrete koyuyor | ikas + Ticimax adaptörümüz var: "ikas/Ticimax mağazan + pazaryerleri, aylık 499 ₺'den" mesajı |

## 3) Paket farklılaştırma — "bütün özellikler her pakette" kalsın mı?

**Öneri:** çekirdek her pakette kalsın (stok, sipariş, fiyat kuralı, kur, kargo bildirimi, roller) —
"fazla satış koruması paket özelliği değildir" mesajı güven veriyor ve bu bizim tek farkımız değil
ama en temiz farkımız. Yeni gelecek **2–3 ileri özelliği** üst pakete koy:

| Üst pakete aday | Neden yükseltme sebebi | Artı | Eksi |
|---|---|---|---|
| Buybox + otomatik fiyat | Rakipler bunu üst pakete/ek ücrete koyuyor (Entegra P2, Sentos Premium, Yengeç +1.000 ₺/ay) | Ölçülebilir para getirir, satıcı farkı hemen görür | Henüz yok (L iş) |
| Açık API + çoklu mağaza/ajans paneli | Büyük satıcı ve ajans ihtiyacı | Altyapı kısmen hazır (firma seçici) | Küçük satıcıya anlamsız |
| AI hakkı miktarı | Zaten sayı olarak farklı (20/300/2.000/10.000) | Kod hazır | Anahtar yok, değer henüz kanıtlanmadı |

**Şimdilik yapma:** var olan bir özelliği (ör. kur çevirisi, roller) üst pakete taşıma — mevcut
sayfadaki "her pakette aynı" sözüyle çelişir ve ilk müşterilerde güven kaybettirir.

## 4) Ek gelir kalemleri (rakip fiyatlarıyla)

| Kalem | Rakip fiyatı (kaynak) | Bizde öneri |
|---|---|---|
| Kurulum + ilk aktarım hizmeti | Sentos: yalnız Plus ve üstünde dahil; Platin360 Enterprise: implementasyon mühendisi (fiyat yok) | Kullanıcı kararı: ücretli paketlerde ücretsiz. Ücretsiz pakette ücretli hizmet olarak sunulabilir (fiyat **kullanıcı kararı**) |
| Ek kanal (paket üstü) | StockMount: ek mağaza ücretli (fiyat yazmıyor); Akınsoft: pazaryeri opsiyonel (fiyat yazmıyor) | Paket atlamadan tek kanal ekleme — ör. kanal başı aylık ücret (**tahmin**, fiyat kullanıcı kararı) |
| Ek kullanıcı | Platin360 ücretli (fiyat yok), StockMount alt kullanıcı ücretli | Aynı model; şeffaf fiyatla |
| E-fatura kontörü | Entegra 5.000 adet 3.000 ₺ (KDV hariç); Sopyo 1.000 adet 875 ₺; BirFatura 0,96–0,355 ₺/adet | Pakete dahil hak + aşan kısım için kontör paketi (e-fatura çalıştıktan sonra) |
| Modül eki (buybox, XML) | Yengeç: Buybox 1.000 ₺/ay, XML 700 ₺/ay (1 kaynak, 5.000 ürün), ürün-stok 1.400 ₺/ay | Buybox geldiğinde: alt paketlere ek, üst pakette dahil |
| Ajans paneli | Rakiplerde ayrı ürün görülmedi | 34Devs'in kendi Shopify müşterileri + diğer ajanslar: çoklu kiracı tek ekran, ajans indirimi. Dağıtım kanalı olarak değerli (**tahmin**) |
| Ücretli WMS/hakediş modülleri | Entegra: WMS 30.000 ₺, hakediş/mutabakat 20.000 ₺ (ek modül) | Bizde hakediş + mutabakat pakette — fark olarak yaz, ayrı satma |

## 5) Öncelik sırası — ilk 5 iş

Takvim: Black Friday dondurması **23–27 Kasım** (yeni özellik yok); Shopify App Store onayı
bekleniyor (Shopify kargo/e-fatura için korumalı veri izni onaya bağlı).

1. **Çalışan e-fatura (ikinci sağlayıcı)** — fiyat sayfasında var, canlıda çalışmıyor; satışta en
   çok sorulacak soru. İş M + entegratör hesabı. BF öncesi (≤20 Kas) bitmeli.
2. **Set / paket ürün** — BF'de hediye setleri satılır; yoksa satıcı elle yönetir ve fazla satar
   (bizim ana sözümüz). İş M, dış bağımlılık yok. BF öncesi.
3. **Hafta sonu destek + iade + veri çıkışı + canlı durum sayfası** (güven paketi) — kullanıcı
   kararları alındı; tam hesap dışa aktarımı S–M, durum sayfası S. Fiyat sayfası işiyle birlikte.
4. **Ek kargo firması (önce HepsiJet ya da MNG — hangisinin API'si açıksa)** — Yurtiçi/Aras dışı
   sözleşmeli satıcıyı kaybetmemek için. M, firma hesabı gerekir. BF sonrası olabilir.
5. **Buybox izleme + otomatik fiyat** — rakiplerin para aldığı en net fark; üst paket sebebi.
   L iş, pazaryeri uçları önce doğrulanmalı. BF sonrası (Aralık).

Sonraki aday: açık API + ajans paneli (34Devs müşterileri dağıtım kanalı), Temu/Ozon, çoklu depo.

## Kaynaklar (erişim 24.10.2026)

entegrabilisim.com/fiyatlar · platin360.com/entegrasyon-paketleri · sentos.com.tr/fiyatlar ·
dopigo.com/fiyatlar · stockmount.com/tr-tr/fiyatlar · birfatura.com/pazaryeri-entegrasyonu ·
birfatura.com/e-fatura-kontor-fiyatlari-e-fatura-fiyatlari · yengec.co/entegrasyon-paketleri-fiyatlari ·
sopyo.com/fiyatlar · ikas.com/tr/e-ticaret-paketleri · ikas.com/tr/entegrasyonlar ·
ticimax.com/e-ticaret-paketleri · akinsofteticaret.com/e-ticaret-paketleri
