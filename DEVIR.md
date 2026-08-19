# Devir Notu — 19 Ağustos 2026 (Faz 3 sürüyor · Faz 4'ten iki madde)

Yeni sohbete bu dosyayı ve `CLAUDE.md`'yi okutarak başla.

## ÖNCE BUNU OKU — ÖNCEKİ DEVİR NOTU YANLIŞTI

Önceki not "FAZ 3 KAPANDI" diyordu. **YANLIŞ.** O ifade, benim devir
notunda tuttuğum dört maddelik alt listeyi (fiyat senkronu · resync ·
prune · mutabakat katmanları) kastediyordu; **dokümanın §13 · Faz 3
listesi başka.** Doğrusu aşağıdaki tabloda.

## BURADAN DEVAM ET

```bash
docker compose up -d
docker compose exec app php artisan test      # 634 yeşil olmalı
```

### Dokümanın gerçek faz tablosu (§13)

| Faz | Saat | Hafta | Durum |
|---|---|---|---|
| Faz 1 — Woo dikey dilimi | 140 | 1–8 | BİTTİ |
| Faz 2 — Trendyol + çift yönlü | 126 | 9–15 | BİTTİ |
| Faz 3 — Güvenilirlik + görünürlük | 84 | 16–20 | **~58/84** |
| Faz 4 — Ticarileşme | 90 | 21–25 | 20/90 |
| Faz 5 — Tampon | 28 | 26 | başlamadı |

**Toplam 468 saat · tahminen ~345 saat bitti → yaklaşık %74.**

### Faz 3'ün BEŞ maddesi — ikisi bitti

| # | Madde | Saat | Durum |
|---|---|---|---|
| 1 | Mutabakat motoru (3 katman, 4 aday, onarım) | 30 | BİTTİ |
| 2 | Metrik toplama, panel grafikleri, uyarı e-postaları | 16 | **HİÇ YOK** |
| 3 | Senkron geçmişi ekranı, hata gezgini, yeniden deneme | 14 | çekirdek var (`RequestResync`), **EKRAN YOK** |
| 4 | Ölü mektup ekranı, bağlantı sağlığı, fazla satış ekranı | 10 | fazla satış VAR, **ölü mektup ekranı YOK** |
| 5 | Toplu içe aktarma (Excel/CSV) + kanaldan ürün çekme | 14 | **CSV BİTTİ**, kanaldan çekme YOK |

**Sıradaki iş — KULLANICIYA SOR.** Aralarında teknik bağımlılık yok:

1. **Ölü mektup + senkron geçmişi ekranı** (madde 3+4, ~24 sa) —
   `RequestResync` çekirdekte HAZIR, sadece buton yok. Destek yükünü
   düşüren ekranlar (§17: "destek yükünü belirleyen tek ekran").
2. **Metrikler + alarm** (madde 2, 16 sa) — §17 "ölçülmeyen güvenilirlik
   iddia edilemez" diyor; sistem çalışıyor ama ne kadar iyi çalıştığı
   görünmüyor.
3. **Kanaldan ürün çekme** (madde 5'in kalanı) — Woo'da `fetchListing`
   TEK ürün okuyor, toplu listeleme yeteneği YOK ve Trendyol'da hiç
   yazılmamış. §7'ye yeni yetenek arayüzü gerekebilir: MİMARİ karar,
   dokümana bakılmadan yapılmaz.
4. **Faz 4'ün kalanı**: panel cilası · onay durumu ekranı ·
   abonelik/ödeme (90 sa'lık fazın 70 saati).

**EKRAN İŞİ ÇIKARSA TARAYICIDA DOĞRULA** — bu kural iki turdur
uygulanıyor ve iki turda da işe yaradı.

Yeni pazaryerleri (Hepsiburada → Amazon → Etsy → eBay) **Faz 3 + Faz 4
bittikten SONRA** — sıra ve gerekçeler aşağıda.

## Tek cümlede durum

**Faz 1 ve Faz 2 bitti; Faz 3'te 5 maddeden 2'si, Faz 4'te 2 madde
bitti.** **634 test yeşil** (2195 assertion), Pint temiz (303 dosya),
sekiz ardışık rastgele sıralı tur temiz. Panelde ON ekran.

## Bu turda ne eklendi

### §13 · Faz 3 · madde 5 · TOPLU İÇE AKTARMA — CSV (`f234303`)

| Ne | Nerede |
|---|---|
| Ayrıştırıcı | `Catalog/Support/CsvProductParser` (+ `CsvParseResult`) |
| Action | `Catalog/Actions/ImportProducts` (+ `ImportResult`) |
| İş | `Catalog/Jobs/ImportProductsJob` (kuyruk **`listing:bulk`**) |
| Durum | `product_imports` tablosu + `ProductImport` modeli |
| Ekran | `Products/Import.vue` · GET+POST `/products/import` |
| Testler | `CsvProductImportTest` (16) + `ProductImportScreenTest` (12) |

**KAPATILAN BOŞLUK:** satıcı 500 ürününü panelden tek tek giremiyordu.
§17 bu maddeyi "TEMEL" önceliğe koyuyor: ödeme mekanizması olsa bile
ürünlerini sisteme sokamayan satıcı sistemi kullanamaz.

**AYRIŞTIRMA İLE YAZMA AYRI.** `CsvProductParser` saf ve yan etkisizdir;
birleştirilselerdi ondalık ayırıcı / BOM / kolon eşleme kuralları ancak
veritabanı kurup ürün yaratarak test edilebilirdi.

**TÜRKÇE EXCEL BİÇİMİ BİRİNCİ SINIF VATANDAŞ** — gerçek dosya BOM +
noktalı virgül + virgüllü ondalık taşır:
- BOM atılmazsa ilk kolonun adı `"\u{FEFF}sku"` olur ve dosya "sku
  kolonu yok" diye reddedilir — kullanıcı gözüyle kolon ORADA.
- `(float) "1.299,90"` PHP'de **1.0** eder. Kuruşlar değil LİRALAR
  düşer. Virgül varsa nokta BİNLİK ayırıcıdır ve atılır.
- Virgül ondalık olduğunda Excel alan ayırıcısını noktalı virgüle
  çevirir; yalnızca virgül desteklenseydi Türkçe kaydedilmiş her dosya
  tek kolon olarak okunurdu.

**KOLONLAR ADIYLA EŞLENİR, KONUMLA DEĞİL** — konumla eşlenseydi fiyat
kolonu stok sanılır ve 500 ürün yanlış fiyatla kanala giderdi.

**AÇILIŞ STOĞU LEDGER ÜZERİNDEN GİRER** — `CreateProduct` çağrılır,
`inventory_levels` satırına DOKUNULMAZ. Doğrudan yazmak 500 satırlık
dosyada 500 bozuk bakiye ve 500 sahte sürüklenme demekti.

**VAR OLAN SKU GÜNCELLENİR (kullanıcı kararı) ama STOK SATIRDAN
YAZILMAZ.** Satıcının en sık işi toplu fiyat güncellemesidir. Stok
yalnızca ledger yollarından değişir; var olan üründe uygulansaydı
SATILMIŞ mallar bir dosya yüklemesiyle geri gelir ve bakiye kalıcı
bozulurdu — maddenin en tehlikeli hatası: sessiz, geri alınamaz, fazla
satışa yol açar. `applyUpdate()` stok parametresi ALMAZ.

**TEK BOZUK SATIR DOSYAYI DÜŞÜRMEZ** ve tur **TEK TRANSACTION'A
SARILMAZ**: 437. satırdaki hata önceki 436 ürünü geri alsaydı kullanıcı
her denemede baştan başlardı.

**KUYRUK `listing:bulk`** (§15) ve `reconciliation` ile havuz PAYLAŞMAZ
— §15'in açık kuralı. **Yeniden deneme YOK** (`$tries = 1`): içe aktarma
idempotent DEĞİLDİR ve yarıda kalan turda hangi satırın işlendiği
bilinmiyor.

**ON BEŞ MUTASYON, ON BEŞİ DE YAKALANDI** — biri ancak test eklendikten
sonra: `catch (Throwable)` daraltıldığında hiçbir test kırılmıyordu,
çünkü mevcut "bozuk satır" testlerinin hepsi AYRIŞTIRMADA eleniyor ve
yazma yoluna hiç ulaşmıyordu. Yani maddenin en kritik kuralı yazma
tarafında HİÇ SINANMAMIŞTI. Ayrıştırmayı GEÇİP yazarken patlayan satır
testi eklendi (300 karakterlik başlık; `products.title` 255 sınırlı).

**GERÇEK ÇALIŞTIRILDI (gerçek worker + gerçek Türkçe Excel dosyası):**
BOM + noktalı virgül + `"1.299,90"` içeren 5 satırlık dosya
`listing:bulk` kuyruğuna atıldı, `queue:work --queue=listing:bulk` işi
ALDI ve tamamladı. 3 ürün yazıldı (1299.90 / 449.50 / 59.90 doğru
okundu), 2 bozuk satır satır numarasıyla raporlandı. Ledger doğrulandı:
`type=IMPORT`, `delta=12`, `source=product_creation` ve ledger toplamı =
projeksiyon. Ekran Playwright ile sürüldü. Dev verisi geri alındı.

### §13 · Faz 4 · MUTABAKAT PANEL EKRANI (`513480d`)

| Ne | Nerede |
|---|---|
| Controller | `Http/Controllers/ReconciliationController` |
| Ekran | `Pages/Reconciliation/Index.vue` |
| Rota | `GET /reconciliation` (auth + tenant), SALT OKUNUR |
| Navigasyon | `PanelLayout` — Stok ile Kanallar arasında |
| Testler | `ReconciliationScreenTest` (13) |

**KAPATILAN BOŞLUK:** üç mutabakat katmanı da `reconciliation_items`
yazıyordu ve HİÇBİRİ gösterilmiyordu. §17 sürüklenme tespitini "ürünün
temel iddiası", panel görünürlüğünü "destek yükünü belirleyen tek ekran"
diye listeliyor; ikisi birleşince ekranın yokluğu şu demekti: satıcı
kanalda yanlış stok olduğunu ancak müşteri şikâyet edince öğrenir.

**`MANUAL_REVIEW` EN ÜSTTE VE AYRI SAYILIR.** O satırlarda otomatik
onarım DURMUŞTUR; `DRIFT_DETECTED` ile aynı kefeye konsaydı satıcı
"sistem hallediyor" sanır ve tam olarak müdahale bekleyen satırı hiç
görmezdi. Uyarı kutusu ne yapılacağını SOMUT söylüyor.

**ÜÇ SAYI, ÜÇ FARKLI EYLEM.** `REMOTE_UNREACHABLE` sürüklenme SAYILMAZ
(fark kanıtlanmamıştır) ama AYRI gösterilir — sessizce yutulsaydı satıcı
kanalının okunamadığını hiç bilmezdi.

**FAZLA SATIŞTA İKİ DEĞER AYRIŞIR VE İKİSİ DE GÖSTERİLİR** (§17 · P0):
"Bizde 0" (kırpılmış giden değer) + altında "bakiye −2" (ham kanonik).

**LISTING BAŞINA SON KALEM** — her tur yeni kalem yazar; hepsi
listelenseydi üç turdur sürüklenen tek listing ekranı üç satırla
doldururdu. `MAX(id)` KULLANILAMAZ (PostgreSQL'de uuid için `max()` yok,
sorgu patlar) → `DISTINCT ON`.

**ON DÖRT MUTASYON, ON DÖRDÜ DE YAKALANDI** — biri ancak test ölçeği
düzeltildikten sonra: sıralamanın TAMAMEN kaldırılması hiçbir testi
kırmıyordu, çünkü `MANUAL_REVIEW` satırı testte zaten önce yaratılmıştı
ve UUIDv7 zaman sıralı olduğu için sıralamasız da başta geliyordu. Kurgu
ters çevrildi: takılı satır SONRA yaratılıyor, başa gelmesi ancak
sıralamayla mümkün.

**TARAYICIDA DOĞRULANDI** — ekran işi kuralı bu turda İLK KEZ uygulandı.
Demo kiracıda gerçek mutabakat yolundan üç senaryo üretildi (sıradan
sürüklenme, üç tur üst üste `MANUAL_REVIEW`, fazla satış) ve ekran
Playwright ile sürüldü: navigasyon sekmesi, dört özet kartı, uyarı
kutusu, rozetler, "Bizde 0 / bakiye −2", "Son tur: Sıcak (5 dk)" ve
geçmiş filtresi doğrulandı. Dev verisi geri alındı.

### §10 · ONARIM DÖNGÜ EMNİYETİ — 3 TUR KURALI (`355c7a4`)

| Ne | Nerede |
|---|---|
| Sayaç | `Reconciliation/Support/DriftHistory` |
| Durum | `ItemStatus::MANUAL_REVIEW` (yeni) |
| Kapı | `ReconcileConnection::classify()` — geçmişe duyarlı |
| Testler | `RepairLoopSafetyTest` (11) |

**KAPATILAN BOŞLUK:** §10'un VERIFY adımı ve §1 · Karar 13 ("üç tur üst
üste sürüklenmede otomatik onarım duruyor") YAZILMAMIŞTI. Doküman bunu
P0 değer listesinde sayıyor. Onarım sürüm kapısını ATLAR ve
`desired_version`'ı ARTIRMAZ; bedeli, kanal 200 dönüp değişikliği
UYGULAMIYORSA aynı farkın her turda yeniden onarılmasıdır — sıcak
katmanda beş dakikada bir, SONSUZA KADAR.

**SAYAÇ GEÇMİŞTEN TÜRETİLİR, AYRI KOLON YOK** (kullanıcı kararı).
`reconciliation_items` zaten gerçeği taşıyor; ayrı sayaç kolonu, kalem
yazan HER yolun onu da güncellemesini zorunlu kılardı ve biri unutulunca
iki gerçek kaynağı sessizce ayrışırdı. **Sayılan şey ARDIŞIKLIKTIR**,
toplam değil: araya giren eşleşme zinciri KIRAR. Emniyet kalıcı ceza
değildir — kanal düzelip bir tur eşleşince kendiliğinden kalkar.

**`REPAIRED` DURUMU DA YAZILIYOR** (§10). Enum'da vardı ama HİÇ
yazılmıyordu: onarımın tuttuğu hiçbir yerde kayıtlı değildi.

**ON DÖRT MUTASYON: ON ÜÇÜ YAKALANDI, BİRİ HAYATTA KALDI VE KALMALI.**
İkisi ancak müdahaleden sonra:
- **`REMOTE_UNREACHABLE`'ın zinciri KIRMASI** hiçbir testi bozmuyordu. O
  hâlde emniyet pratikte hiç devreye giremezdi: gerçek kanallarda geçici
  hata KURALDIR ve araya giren tek bir ağ hatası sayacı sıfırlayıp sonsuz
  döngüyü baştan başlatırdı. Doğru davranış ÜÇÜNCÜ seçenektir — o tur ne
  SAYILIR ne zinciri KIRAR; YOK SAYILIR.
- **`MANUAL_REVIEW`'ın zinciri uzatmaması** altı turluk testte
  yakalanmıyordu: sayaç yalnızca son 10 kalemi okuyor ve ilk iki
  `REPAIR_QUEUED` hâlâ penceredeydi. Tur sayısı pencereden BÜYÜĞE
  çıkarıldı (14). **YENİ KURAL: bir pencere/limit varsa testin ölçeği o
  pencereyi AŞMALI.**

**HAYATTA KALAN (dürüst sınır):** `DriftHistory`'deki kiracı filtresi.
Sorgu zaten `listing_id` ile daraltılıyor, FK `listings`'e bağlı ve bir
listing TEK kiracıya ait. Gerekçe koda yazıldı, sahte test YAZILMADI.

**GERÇEK ÇALIŞTIRILDI (İNATÇI KANAL: 200 döner, yazmayı UYGULAMAZ):**
tur 1-2 `REPAIR_QUEUED` + iki onarım · tur 3-5 `MANUAL_REVIEW`, onarım
AÇILMADI · kanal düzelince tur 6 `MATCHED` ve emniyet KENDİLİĞİNDEN
KALKTI. Dev verisi geri alındı.

### §10 · ILIK VE SOĞUK MUTABAKAT KATMANLARI (`5df1983`)

| Ne | Nerede |
|---|---|
| Katman enum'ı | `Reconciliation/Enums/ReconciliationScope` |
| Örneklem | `Reconciliation/Support/SampledCandidates` |
| Aday seçimi | `CandidateSelector::for()` artık scope alır |
| Yönlendirme | `ReconcileConnection::selectCandidates()` |
| Komutlar | `reconcile:warm` (saatlik) · `reconcile:cold` (günlük 05:00) |
| Kayıt | `bootstrap/app.php` · Zamanlama `routes/console.php` |
| Testler | `ReconciliationLayersTest` (17) + `ScheduledScansTest`'e 9 iddia |

Dokümanın bütçe tablosu (§10):

| Katman | Sıklık | Kapsam | Bütçe |
|---|---|---|---|
| Sıcak | 5 dakika | 30 dk satış · geçici hata · 1 sa bekleyen | ≤ 50 |
| Ilık | Saatlik | 24 sa satış · 24 sa bekleyen | ≤ 300 |
| Soğuk | Günlük | Rastgele örneklem — uzun kuyruk | %2, üst sınır 500 |

**BEŞ ADIMLI AKIŞ YENİDEN KULLANILIR.** DETECT/RECORD/CLASSIFY/REPAIR/
VERIFY üç katmanda da AYNIDIR; değişen yalnızca aday seçimi ve bütçedir.
Akış katman başına kopyalansaydı üç kopya zamanla ayrışır ve
`max(available, 0)` gibi bir kural birinde düzeltilip ötekilerde eski
hâliyle kalırdı.

**PENCERELER KATMANDAN GELİR** (`ReconciliationScope`), sorguya gömülü
değil. Gömülü olsaydı ılık katman `CandidateSelector`'ın bir KOPYASI
olarak yazılırdı. Ilık katman sıcakla AYNI eşikleri kullansaydı 300'lük
bütçesini sıcak turun her beş dakikada bir zaten baktığı satırlarla
doldurur ve HİÇBİR ŞEY EKLEMEZDİ.

**SOĞUK KATMAN DÖRT SEBEP SORGUSUNU ÇALIŞTIRMAZ — maddenin tüm
gerekçesi budur.** Uzun kuyruk tam olarak o dört sebebin hiçbirine
takılmayan satırdır: satmıyor, hata almamış, bekleyen işi yok,
sürüklenme geçmişi yok. Satıcı kanal panelinden stoğu elle değiştirdiyse
o sürüklenme sıcak ve ılık katmanlarda SONSUZA KADAR görünmez. Dört
sorgu soğukta da koşsaydı soğuk katman ılığın günlük bir kopyası olur ve
500'lük bütçenin çoğunu ılık turun bir saat önce zaten baktığı satırlar
yerdi.

**SIRALAMA `last_observed_at NULLS FIRST` — "rastgele" DEĞİL, EN ESKİ.**
Doküman kapsamı "rastgele örneklem" diye adlandırıyor ama §4 bu iş için
AÇIKÇA `sync_states_observed_idx (domain, last_observed_at NULLS FIRST)`
tanımlıyor ve o indeksin başka hiçbir kullanıcısı yok. `ORDER BY
random()` hem indeksi kullanamaz (her turda tam tarama) hem de %2
bütçeyle bir satırın AYLARCA seçilmemesi demektir. `NULLS FIRST` kritik:
hiç gözlenmemiş satır sürüklenmeye en açık olandır ve `NULLS LAST`
olsaydı dar bütçede ASLA seçilmezdi.

**SOĞUK BÜTÇE ORANSALDIR, 500 yalnızca ÜST SINIR.** Sabit 500 kullanmak
50 listing'i olan bağlantıda TAM KATALOG TARAMASI demektir ve o hiçbir
katmanda yoktur. Alt sınır 1: küçük katalogda %2 sıfıra yuvarlanır ve
soğuk katman o satıcılar için HİÇ çalışmazdı.

## Mutasyonla sınandı — ON YEDİ mutasyon, ON YEDİSİ DE YAKALANDI

Ama ÜÇÜ ancak test veya düzeltme eklendikten sonra:

**1 · `lifecycle_status = 'live'` yükleminin kaldırılması hiçbir testi
kırmıyordu.** Sebep incedir: yalnızca draft satır içeren bir bağlantıda
`activeListingCount()` sıfır döner, bütçe sıfır olur ve `for()` SQL'e HİÇ
GELMEDEN çıkar — yani yüklem o senaryoda çalışmıyordu bile. Karışık
katalog testi eklendi (canlı + taslak) ve **taslak satır ÖNCE yaratıldı**:
her iki satır da hiç gözlenmemiş olduğu için sıralama `l.id ASC`
tie-breaker'ına düşer ve listing kimlikleri UUIDv7 — ZAMAN SIRALI —
olduğundan önce yaratılan başa gelir. Canlı satır önce yaratılsaydı bir
kişilik bütçe onu seçer, taslağa hiç sıra gelmez ve test YİNE SAHTE
YEŞİL kalırdı.

**2 · `reconcile:cold` komutunun scope'u `WARM`'a çevrildiğinde hiçbir
test kırılmıyordu.** Komut kayıtlıydı, zamanlanmıştı, sıfırla çıkıyordu ve
sweeper'ı gerçekten çağırıyordu — yalnızca YANLIŞ KATMANI sürüyordu.
Sonuç: uzun kuyruk hiç taranmaz ve `schedule:list` kusursuz görünür.
Kayıt testi, frekans testi ve "başarıyla çalışır" testinin ÜÇÜ DE bunu
göremez: hepsi komutun VAR OLDUĞUNU sınar, NE YAPTIĞINI değil. Komutu
gerçekten çalıştırıp yazılan turun `scope` alanını okuyan test eklendi ve
üç komut bağlaması da ayrı ayrı doğrulandı.

**3 · GERÇEK ÇALIŞTIRMADA BULUNDU — bütçe tabanı ile örneklem havuzu
AYRIŞIYORDU.** Dev veritabanında sayım 3 dedi, örneklem 2 satır döndü:
`activeListingCount()` `error_permanent` satırlarını sayıyor, örneklem
onları hariç tutuyordu. Kalıcı hataya düşmüş satırı çok olan bir
bağlantıda bütçe gerçekte taranabilecek satır sayısının ÜSTÜNE çıkar ve
"aktif listing'lerin %2'si" kuralı sessizce daha büyük bir orana dönerdi
— sapma tam da oranın en çok korumak istediği yerde (büyük katalog, çok
hatalı satır) en büyük olur. Sayım havuzla aynı yüklemleri taşıyacak
şekilde düzeltildi ve testi yazıldı. Testler bunu göremezdi çünkü küçük
kataloglarda alt sınır 1 her iki hesabı da aynı sayıya indiriyor.

## RASTGELE SIRADA DÜŞÜŞ — YAKALANDI VE DÜZELTİLDİ

Yeni `each_command_drives_its_own_layer` testi `latest('started_at')`
kullanıyordu ve **altı turda bir düşüyordu**. Sebep:
`reconciliation_runs.started_at` SANİYE hassasiyetlidir ve üç komut aynı
saniye içinde koştuğunda ikisi AYNI damgayı taşır; hangisinin "son"
olduğu belirsiz kalır ve sorgu bazen ılık turu döndürür. Sıralama `id`'ye
(UUIDv7 — zaman sıralı ve saniye içinde de ayırt edici) alındı.
Düzeltmeden sonra **sekiz ardışık rastgele tur temiz** ve mutasyon
koruması korundu (mutasyon altında hâlâ kırmızı).

Bu, projenin zaman damgası hassasiyeti tuzağının bir kez daha tekrarı —
bu kez `outbox_events` değil `reconciliation_runs` üzerinde ve sorguda
değil TESTTE.

## GERÇEK ÇALIŞTIRILDI — gerçek HTTP + gerçek worker

Yerel TLS stub'ı (`host.docker.internal:9911`) kanal olarak kullanıldı,
sertifika container'ın güven deposuna eklendi ve tur bitince kaldırıldı.

1. **Soğuk tur örneklemle aday seçti** (`reason=sampled` — dört sebebin
   hiçbiri değil), Woo adapter'ını sürdü ve gerçek istek attı:
   `GET /products?include=4242&per_page=100`.
2. **Sürüklenme bulundu:** kanonik `available=17`,
   `expected_remote=17`, kanal `99` → `DRIFT_DETECTED`, `magnitude=82`.
3. **Onarım açıldı:** `intent=REPAIR`, anahtar
   `inv:{listing}:4:repair:{reconciliation_item_id}` — kalem kimliğini
   çıpa olarak taşıyor. **`desired`/`synced` 4'te KALDI** (§10: onarım
   sürüm kapısını atlar ve sürümü ARTIRMAZ).
4. **`queue:work --queue=inventory:high` `PushInventory`'yi çalıştırdı**
   ve kanala kanonik değer gitti:
   `POST /products/batch {"id":4242,"stock_quantity":17}`.
5. **Doğrulama AYRI turda:** kanal artık 17 döndürünce SICAK tur kalemi
   `drift_detected` sebebiyle yeniden aday etti ve **MATCHED** yazdı —
   sürüklenme kapandı ve bir sonraki tur onu artık aday etmeyecek.

Ayrıca doğrulandı: `error_permanent` satırı için SIFIR kalem yazıldı
(üç katmanda da), draft satır örneklenmedi, `schedule:list` üç katmanı da
doğru cron ifadesiyle gösteriyor (`*/5 * * * *` · `0 * * * *` ·
`0 5 * * *`). **Dev verisi geri alındı** (bu oturumun 50 turu, 14 kalemi
ve 1 onarım operasyonu silindi; 17 Ağustos'tan kalan 6 tur korundu).

### Bir önceki tur — §13 · Faz 3 · fiyat senkron yolu (`d17aa8a`)

Tetikleyici `UpdateProduct` → `VariantPriceChanged` → fan-out tüketicisi
→ `PRICE_PUSH` → `PushPrices` (kuyruk **`price:high`**). Gruplama
`PriceBatchBuilder`'da, bağlantı başına. `pushPrices` gövdeleri (Woo VE
Trendyol) ilk günden beri hazırdı ama **çekirdekte çağıranı yoktu**.
Kuyruk adı `price:default` yazılmıştı; §15 ve `config/horizon.php`
`price:high` diyor — düzeltildi ve adı Horizon yapılandırmasıyla
karşılaştıran test eklendi.

### Bir önceki tur — §13 · Faz 3 · RequestResync + T10 (`9ec5ac0`)

`error_permanent → pending` geçişi AYNI transaction içinde
`ListingResyncRequested` yazar; durum değişikliği tek başına hiçbir iş
üretmez. Niyet REPAIR ve ayırt edici çıpa OLAY KİMLİĞİDİR
(`resync:` ön eki, mutabakatın `repair:` ön ekinden ayrı).

### Bir önceki turlar

**`PruneApiCalls` (`a452a27`):** ölçüt `expires_at`, durum kodu değil;
silme partilenir; tur başına üst sınır var; transaction YOK.

**Sipariş güncelleme + kargo (`ab4bffe`):** ikisi de stok hareketi
ÜRETMEZ; NULL "değişmedi" demektir; paket başına tek satır.

## Ortam

```bash
docker compose up -d
docker compose exec app php artisan test      # 581 yeşil olmalı
docker compose exec app vendor/bin/pint       # kod stili
npm run build                                 # YERELDE (container'da Node yok)
```

Panel: `/` özet · `/products` ürünler · `/products/{id}/channels` kanala
gönderme · `/orders` siparişler · `/inventory` stok · `/channels` kanallar ·
`/mappings` eşleştirme

## YOL HARİTASI — NE BİTTİ, NE KALDI (19 Ağustos 2026)

### Bitti

**Çekirdek:** stok ledger'ı (`ApplyMovement`, `LockInventoryRows`), outbox
relay + fan-out, adapter mimarisi (7 yetenek arayüzü), sipariş alımı,
gelen hat (webhook → inbox → router), giden hat (`InventoryBatchBuilder`,
`PushInventory`, `SyncResultRecorder`), koruma katmanı (`ChannelRateLimiter`,
`CircuitBreaker`), ürün aktarımı (`PushListing`, `PublishListing`),
§6 bütünlük taramaları (iki seviye), **§10 mutabakatın ÜÇ KATMANI DA**
+ **onarım döngü emniyeti (3 tur kuralı)**,
ön koşul kapısı + onay takibi, sipariş yoklaması, sipariş güncelleme +
kargo, `PruneApiCalls`, resync yolu, fiyat senkron yolu.

**Kanallar (2):** WooCommerce (tam) · Trendyol (taksonomi, katalog, onay,
stok/fiyat itme, sipariş yoklaması).

**Panel (9 ekran):** özet · ürünler · ürün oluştur/düzenle · ürün-kanal ·
siparişler · sipariş ayrıntısı · stok · **mutabakat** · kanallar ·
eşleştirme.

**Testler:** 605 yeşil (2110 assertion), 63 test dosyası.
**P0/P1'in TAMAMI yeşil** — T1…T12. Yazılmamış P0/P1 testi KALMADI.

### Kaldı — FAZ 4 (panel + abonelik)

Sıra kullanıcının kararına bağlı; teknik bağımlılık yok.

- ~~**Onarım döngü emniyeti**~~ — **BİTTİ** (`355c7a4`). Çekirdek ön
  koşuldu: ekran onu göstermek zorundaydı.
- ~~**Mutabakat panel ekranı**~~ — **BİTTİ** (`513480d`), tarayıcıda
  doğrulandı.
- ~~**Toplu içe aktarma (CSV)**~~ — **BİTTİ** (`f234303`), gerçek
  worker'da ve tarayıcıda doğrulandı. Bu Faz 3 · madde 5'in CSV
  yarısıdır; **KANALDAN ÜRÜN ÇEKME hâlâ YOK.**
- **Panel cilası** (§13 · Faz 4, 20 sa): boş durumlar, yükleniyor, mobil.
  Artık ON ekrana dokunur.
- **Onay durumu için ayrı ekran** (rozet + red sebebi ürün-kanal
  ekranında var). En küçük madde.
- **Abonelik/ödeme** (hafta 21–25): planlar, kota, iyzico. Şema kararı
  alınmış, YAZILMADI. Kota neyi sınırladığını senkron davranışından alır
  ve o davranış artık OTURDU — yani bu madde artık teknik olarak da
  yazılabilir durumda.

**Trendyol'da kapsam dışı bırakılanlar** (eksik DEĞİL): `delist`,
`fetchListing`, `acknowledgeOrder` — kargo §14 gereği kapsam dışı.

### KULLANICI KARARI — YENİ PAZARYERLERİ (19 Ağustos 2026)

**Bu maddeler FAZ 4 BİTTİKTEN SONRA ele alınır.** Sıra (kullanıcı aksini
söylemedikçe):

1. **Hepsiburada** — TR pazarı, Trendyol'a en yakın model (taksonomi +
   zorunlu öznitelik + onay süreci). `ListingMapper` · `TaxonomyClient` ·
   `TrackApprovalStatus` kalıbı doğrudan örnek olur; en düşük riskli.
2. **Amazon (SP-API)** — en büyük iş değeri, en yüksek karmaşıklık: LWA
   OAuth + farklı rate limit modeli, feed tabanlı asenkron aktarım
   (`submitFeed` → `getFeedResult` yoklaması), FBA/FBM ayrımı. Muhtemelen
   §7'ye yeni bir yetenek arayüzü gerekir — bu MİMARİ bir karardır ve
   dokümana bakılmadan yapılmaz.
3. **Etsy** — OAuth 2.0 + PKCE, "taxonomy_id" + shop section modeli;
   envanter uç noktası varyant bazlı ve Woo'dan farklı.
4. **eBay** — Inventory API (offer/inventory item ayrımı) + politika
   nesneleri (payment/return/fulfillment policy) bağlantı kurulumunda
   zorunlu. `channel_connections.settings` bunu taşıyabilir ama bağlama
   akışı ekstra adım ister.

**Shopify BU LİSTEDE DEĞİL** — kullanıcı açıkça istemedi. Memory'deki
"Teknoloji Kararları" notu "Laravel + Node Shopify app" diyor; o karar
artık geçerli değil.

**MİMARİ SÖZ:** yeni kanal eklemek çekirdeği DEĞİŞTİRMEMELİ. Kanal başına
bir adapter + (varsa) mapper/normalizer; stok matematiği, outbox,
fan-out, kilit ve mutabakat aynı kalır. `if ($channel === '...')`
YAZILMAZ — yetenek `instanceof` ile okunur. Kanal başına kabaca
**40–60 saat**, Amazon'da daha fazla.

## Demo verisi panelde duruyor

`demo@entegrasyon.local` / `demo12345` — gezilebilir bir kiracı.
6 ürün, 2 kanal bağlantısı, `demo-v3` taksonomisi (4 yaprak) ve
**bilinçli olarak KISMİ** eşleştirmeler: `mutfak` eşleşmedi ·
`kadin-elbise` zorunlu öznitelik eksik (Renk) · `tisort` hazır.
`TSH-201` fazla satış taşıyor (bakiye −3).

Bu veri commit'lerde DEĞİL, yalnızca yerel veritabanında.

## Bu projede işe yarayan çalışma biçimi

1. **Testi önce yaz, kırmızı olduğunu gör**, sonra implementasyonu yaz.
2. **Mutasyonla sına** — ve **mutasyonun testi gerçekten KIRDIĞINI doğrula**.
   Python yamalarında `assert old in s` kullan.
3. **Mutasyon hayatta kalırsa SAHTE TEST YAZMA** — ya gerçek test bul, ya
   yapısal sınırı belgele.
4. **`Http::fake()` her adrese cevap verir** — "bozuk yapılandırma"
   senaryosu fake altında bozuk DEĞİLDİR. Gerçek hata kodu kullan.
5. **Kalıcılık sınarken Eloquent'e güvenme** — kimlik haritası aynı bellek
   nesnesini geri verir. **Ham satırı oku.**
6. **`DB::table()` yazdıysan kiracı filtresinin TESTİNİ de yaz.**
7. Stok yazan her testin sonunda `assertLedgerMatchesProjection()` çağır.
8. **Ekran işi bittiğinde TARAYICIDA çalıştır.**
9. **Kuyruk işi / komut yazdıysan GERÇEK ÇALIŞTIR.**
10. **Adapter yazdıysan BAĞLAM DIŞINDA çağırmayı da sına.**
11. **Testte "işi çalıştır" derken reflection'a sapma.**
12. **Adapter gövdesi yazdıysan ÇEKİRDEĞİN ONU SÜRDÜĞÜNÜ de sına.**
13. **Ekran yazdıysan TARAYICIDA sür.** Playwright CLI ile giriş yap,
    snapshot al, ekran görüntüsü al ve GÖZLE bak. Mutabakat ekranı bu
    turda böyle doğrulandı; `npx --package @playwright/cli playwright-cli`
    ile çalışır (wrapper script kurulu değil). `open` YENİ OTURUM açar ve
    çerezi düşürür — giriş sonrası navigasyonu menü linkine tıklayarak yap.
14. **`--order-by=random` ile en az birkaç tur koş.** Bu turda yeni bir
    test altı turda bir düşüyordu ve sıralı koşuda ASLA görünmezdi.

## Mutasyonla / gerçek çalıştırmayla bulunan gerçek boşluklar (tarihçe)

Hepsi testler yeşilken bulundu:

- **Bütçe tabanı örneklem havuzuyla ayrışıyordu** (§10 soğuk katman).
- **Komut kayıtlı ve zamanlı olup YANLIŞ KATMANI sürebiliyordu.**
- **`supports_webhooks` eager-load'da seçilmiyordu** — webhook kapısı ölüydü.
- **`pushPrices`'ın çekirdekte çağıranı yok** — Woo dahil.
- **Engellenen gönderim "zaten güncel" diyordu** (panelde).
- **Onay yanıtında olmayan satır "reddedildi" sayılabiliyordu** (mutasyon).
- **Tek bozuk bağlantı tüm kanalın taksonomisini durduruyordu.**
- **Başarısız yanıt sessizce boş kategori ağacı yazıyordu.**
- **Kimlik bilgisi bağlam dışında hiç gönderilmiyordu** — Woo dahil.
- **`DB::table()` kiracı filtresinin testi yoktu** — DÖRT ayrı turda.
- **Kalıcılık testi Eloquent kimlik haritası yüzünden sahte yeşildi.**
- **Kuyruk işi kiracı bağlamını kurmuyordu.**
- **`TenantAwareJob::$tenantId` readonly'di.**
- **Kanal ilişkisi eager-load edilmiyordu** (lazy loading kapalı).
- **Rozet delisted listing'leri sayıyordu.**
- **Eager-load'da `adapter_class` seçilmiyordu.**
- **Kurtarma taramaları zamanlayıcıya bağlanmamıştı.**
- **`ApplyMovement` outbox yüküne `origin_connection_id` yazmıyordu.**
- **`verifyWebhookSignature` kiracı bağlamı bekliyordu.**
- **Başarıda sürüm kapısı yoktu.**

## Davranışla sınanamayan kurallar (dürüst sınır)

Mutasyon hayatta kalır ve kalmalı; sahte test YAZILMADI:

- **Router'ın `FULFILLED` dalı ve kargo olay çıpası** — hiçbir normalizer
  `fulfilled` tipi ÜRETMİYOR (Woo ayrı webhook göndermiyor, Trendyol'da
  kargo §14 gereği kapsam dışı). O olayı üreten kaynak olmadan davranış
  testi yazılamaz.
- **`SyncTaxonomy` içindeki `runAsSystem`** — `ChannelCategory`
  `BelongsToTenant` kullanmıyor, global scope hiç uygulanmıyor.
- **`AdjustStock` içindeki `LockInventoryRows` çağrısı** — `ApplyMovement`'ın
  UPDATE'i aynı satır kilidini zaten koyuyor.
- **`published_at IS NOT NULL` yüklemi** — NULL karşılaştırması satırı zaten
  eler.
- **`hash_equals` → `===`** — zamanlama saldırısı işlevsel testte görünmez.
- **Adapter'a `max($q, 0)`** — `InventoryPushItem` negatifi kurucuda reddeder.
- **`ctype_digit` yerine yalnızca `(int)`, `"sınırsız"` girdisiyle** —
  `(int) "sınırsız"` zaten `0`. Kural `"600, 300"` ile sınanır.
- **`PruneApiCalls`'ta `expires_at < ` → `<=`** — kolon saniye
  hassasiyetli, `clock_timestamp()` mikrosaniye taşır; eşitlik ulaşılamaz.
- **`PruneApiCalls`'ta `while ($deleted < $maxRows)` → `while (true)`** —
  `min()` clamp'i sınırı zaten uyguluyor.
- **`PruneApiCalls`'ta `clock_timestamp()` → `now()`** — tur transaction
  DIŞINDA çalışıyor. Kural "TRANSACTION YOK" kararı geri alınırsa diye.
- **`PruneApiCalls`'ta `runAsSystem` kaldırma** — `api_calls`'un modeli
  YOK, global scope hiç uygulanmıyor.

## Tekrar tekrar ısıran tuzaklar

- **`latest('<timestamp>')` KULLANMA — kodda da testte de.** Bu turda İKİ
  mutabakat testinde ve BİR controller'da bulundu; beş turda bir rastgele
  düşüş üretiyordu. Zaman damgaları SANİYE hassasiyetli ve aynı saniyede
  yazılan satırlarda sıra belirsiz. UUIDv7 birincil anahtar zaman
  sıralıdır: **`orderByDesc('id')` kullan.** `DashboardController` örneği
  daha sinsiydi — fan-out tek olaydan onlarca operasyonu aynı saniyede
  açıyor ve "son 15" her yenilemede farklı bir alt küme gösterebiliyordu
  (testi yoktu, o yüzden düşüş de görünmüyordu).
- **PENCERE/LİMİT VARSA TESTİN ÖLÇEĞİ O PENCEREYİ AŞMALI.** `DriftHistory`
  yalnızca son 10 kalemi okuyor; altı turluk test mutasyonu yakalayamadı
  çünkü eski kalemler hâlâ penceredeydi. Ölçek 14'e çıkarılınca yakalandı.
- **UUIDv7 ANAHTAR, SIRALAMASIZ TESTİ SAHTE YEŞİL TUTAR.** Kimlikler zaman
  sıralı olduğundan satırlar YARATILIŞ sırasında gelir; beklediğin satırı
  ÖNCE yaratırsan sıralamanın tamamen kaldırılması testi kırmaz. Elenmesini
  ya da sonda gelmesini beklediğin satırı ÖNCE yarat.
- **`(float) "1.299,90"` = 1.0** — Türkçe Excel biçimi. Kuruşlar değil
  LİRALAR düşer. Virgül varsa nokta BİNLİK ayırıcıdır ve atılır. Türkçe
  Excel ayrıca BOM ekler ve alan ayırıcısını NOKTALI VİRGÜLE çevirir;
  üçü birden ele alınmalı.
- **PostgreSQL'de `max(uuid)` YOKTUR** — `MAX(id)` ile "grup başına son
  satır" sorgusu doğrudan patlar. `DISTINCT ON (col) ... ORDER BY col, id
  DESC` kullanılır.
- **`TenantAwareJob::handle()` FINAL'dir** — alt sınıf `handleForTenant()`
  yazar ve bağımlılığı `app()` ile alır (imza değiştirilemez).
- **`Http::fake()` her adrese cevap verir.**
- **İkinci `Http::fake()` ilkini DEĞİŞTİRMEZ** — `Http::sequence()` kullan.
- **Adapter bağlam DIŞINDA çağrılabilir** — kimlik `runAsSystem()` ile okunur.
- **`DB::table()` global scope'a TABİ DEĞİLDİR** — filtre VE testi yazılır.
- **Kalıcılık testinde Eloquent kimlik haritası yanıltır** — ham satır oku.
- **Rota model bağlaması kiracı bağlamından ÖNCE çalışır.**
- **ENUM'a cast edilen alan ekrana `->value` ile gider.**
- **Lazy loading KAPALI** — ilişki kullanacaksan eager-load et.
- **Kuyruk işi bağlamı KENDİ kurar** ve `finally` ile bırakır.
- **Ana sınıfta `readonly` promoted property + `SerializesModels` = ölüm.**
- **Statik fabrika ile örnek metodu AYNI ADI paylaşamaz** (PHP).
- **`inventory_movements` kolonu `type`, `movement_type` DEĞİL.**
- **`channel_connections` kolonu `label`, `name` DEĞİL.**
- **`api_calls` zaman kolonu `called_at`** — `created_at` YOK.
- **`sync_operations`'ta `listing_id` YOK** — `entity_type` + `entity_id`
  ve `domain` da YOK (`operation_type` var). Bu turda tinker'da ısırdı.
- **`RemoteListing` parametresi `url`**, `externalUrl` DEĞİL.
- **`ErrorClass` case'i `RATE_LIMITED`**, `RATE_LIMIT` DEĞİL.
- **`SyncOperationStatus`'ta `FAILED` YOK** — kalıcı hata `DEAD`.
- **`OpenSyncOperation` `Sync\Actions\` altında** ve parametresi
  `eventVersion`; dönüşü NULLABLE.
- **`TenantContext` metodu `runFor()`**, `run()` DEĞİL.
- **`MissingTenantContextException` `Support\Tenancy\Exceptions\` altında.**
- **`assertLedgerMatchesProjection()` ÜÇ argüman alır.**
- **`(channel_type_code, external_account_id)` GLOBAL tekildir** — aynı
  test içinde iki kez `connection()` çağırmak kısıtı ihlal eder.
- **`clock_timestamp()`** — zaman damgaları saniye hassasiyetli.
- **PostgreSQL'de `interval ?` BAĞLANAMAZ** — `?::interval` cast'i kullan.
  Metni sorguya gömmek katman değerini enjeksiyon yüzeyine taşır.
- **`Command::run()` REZERVE İMZADIR.** Mantık `Support/` altında.
- **Domain komutları otomatik keşfedilmez** — `bootstrap/app.php`.
- **`QUEUE_CONNECTION=sync` gerçek worker'ı taklit etmez.**
- **Açılış stoğu ledger üzerinden girer** (IMPORT).
- **Eşzamanlılık testi `RefreshDatabase` ile yazılamaz** → `DatabaseTruncation`.
- **`StoreUrl` HTTPS'i zorunlu tutar** — yerel stub'a TLS eklenir ve
  sertifika container'ın güven deposuna konur
  (`/usr/local/share/ca-certificates/` + `update-ca-certificates`).
- **CI'da `public/build` yoktur** — `Tests` job'ı `npm run build` çalıştırır.
- **CI'da `codeload` 429'u** — `--prefer-source` ile kaynak yoldan denenir.
- **Eager-load'da OKUNACAK HER ALAN AÇIKÇA SEÇİLMELİ.**
- **KUYRUK ADI UYDURULAMAZ — HORIZON'UN DİNLEDİĞİ AD OLMALI.** Adlar §15
  tablosunda ve `config/horizon.php` içinde: `orders:high` ·
  `inventory:high` · `price:high` · `listing:default` · `inbox:process` ·
  `outbox:consume` · `reconciliation` · `listing:bulk` · `maintenance`.
- **YÜK OPERASYON LİSTESİ TAŞIMAZSA `SyncResultRecorder` HİÇBİR ŞEY YAZAMAZ.**
- **MEVCUT BİR TESTİN PREMİSİ BAYATLAYABİLİR.** Bir yol yazdığında onu
  "yok" varsayan testleri ARA.
- **TÜKETİCİYİ DOĞRUDAN ÇAĞIRAN TEST YÖNLENDİRMEYİ SINAMAZ.** Yeni bir
  outbox olay tipi eklediysen `ConsumeOutboxEvent`'in `match` dalını da sına.
- **REPAIR NİYETİ AYIRT EDİCİ ÇIPA İSTER.**
- **SINIR TESTİ YAZDIYSAN İKİ OPERATÖR ALTINDA DA GEÇMEDİĞİNİ DOĞRULA.**
  Farkın göründüğü ÖLÇEĞİ kullan (gün değil saniye) ve mutasyonla doğrula.
- **KOMUT KAYITLI + ZAMANLI + BAŞARILI OLUP YİNE DE YANLIŞ İŞİ YAPABİLİR.**
  Üç test de komutun VAR OLDUĞUNU sınar, NE YAPTIĞINI değil. Komut yeni
  bir parametre/mod alıyorsa **onu gerçekten çalıştırıp yazdığı satırı
  oku** (bu turda `reconcile:cold`'un ILIK katmanı sürmesi hiçbir testi
  kırmıyordu).
- **ERKEN ÇIKIŞ, ARKASINDAKİ SQL YÜKLEMİNİ TEST DIŞI BIRAKIR.** `for()`
  bütçe sıfırken SQL'e hiç gelmiyordu; o senaryoyla yazılan test
  yüklemin kaldırılmasını GÖREMEZ. Filtreyi sınayan testi, sorgunun
  GERÇEKTEN KOŞTUĞU bir kurulumda yaz.
- **TESTTE `latest('<timestamp>')` SIRALAMAYI GARANTİ ETMEZ.** Zaman
  damgaları saniye hassasiyetli; aynı saniyede yazılan iki satırda sıra
  belirsizdir ve rastgele sırada aralıklı düşüş üretir. UUIDv7 birincil
  anahtar zaman sıralıdır — **`orderByDesc('id')` kullan.**
- **`api_calls`'un MODELİ YOK** — tablo `DB::table()` ile yazılıp okunuyor.
- **`TrendyolAdapterTest`'teki "yazılmamış yetenek" listesi madde kapandıkça
  KÜÇÜLTÜLMELİ.** Listede kalan: `delist`, `fetchListing` — ikisi de Faz 2
  kapsamı dışı.

## Bilinen açık uçlar

**1 · CI'ın 429 düzeltmesinden sonraki durumu buradan HÂLÂ görülemiyor.**
`gh` kimlik doğrulamalı değil (`gh auth status` → "not logged into any
GitHub hosts") ve bu turda da doğrulanamadı. **`gh auth login` tarayıcı
veya cihaz kodu ister; oturum içinden tamamlanamaz — kullanıcının bir
kez yapması gerekiyor.** Sonrasında `gh run list --limit 5` ile bakılır.
Düzeltmenin kendisi (`6e2217e`) yerinde.

**2 · `--order-by=random` düşüşü BU TURDA TEKRAR ÜRETİLDİ VE KAPATILDI.**
Sebep yeni testin `latest('started_at')` kullanmasıydı (yukarıda). Bu,
beş oturumdur aranan ESKİ düşüşle aynı şey OLMAYABİLİR — eski düşüş hiç
tekrar üretilemedi ve bu turda da (düzeltmeden sonra sekiz tur) görünmedi.
Toplamda **yirmi beş ardışık temiz tur**. Bu turun seed'leri: 1787137572 ·
1787137611 · 1787137656 · 1787137769 · 1787137822 · 1787137885 ·
1787137923 · 1787137966. PHPUnit 11'de `--seed` seçeneği YOK; seed
çıktının sonunda raporlanır ve düşüş görülürse KAYDEDİLMELİ.

**3 · `acknowledgeOrder` yazılmadı ve "yazılmamış yetenek" listesinde de
YOK.** Sipariş onaylama Trendyol'da kargo akışının parçasıdır ve §14
kargoyu kapsam dışı bırakır. Bilinçli kapsam sınırı, eksik değil.
