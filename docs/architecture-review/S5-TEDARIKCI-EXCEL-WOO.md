# S5 · Tedarikçi XML, Excel toplu fiyat/stok, WooCommerce — denetim, kararlar, uygulama (14 Ekim 2026)

Bu belge S5'in tek kaydıdır. Bölüm 1 KOD DEĞİŞMEDEN yapılan denetimdir; bölüm 2 kararlar (ADR-012); sonraki bölümler uygulama ve kanıt.

---

## 1. S5 öncesi denetim (salt okuma)

### 1.1 Ürün / varyant / kimlik

| Bileşen | Durum | Not |
|---|---|---|
| `products` / `variants` (ADR-003: ürün = grup, varyant = satılabilir birim) | EXISTS | `UNIQUE(tenant_id, sku)` ürün ve varyantta AYRI ad alanı; barkod kısmi tekil `(tenant_id, barcode) WHERE barcode IS NOT NULL` |
| Fiyat tipi | EXISTS | `variants.price decimal(12,2)`, varyant başına `currency` (TRY varsayılan); kuruş yalnız karşılaştırmada |
| Seçenekler (`option_definitions/values`, `variant_options`) | EXISTS | kiracı düzeyi tanım; `AssignVariantOptions` ezmez |
| Görseller (`product_images`) | EXISTS | `source_connection_id` FK `channel_connections` → tedarikçi kaynağı bu kolona yazılamaz |
| Kategori / marka | PARTIAL | serbest metin (`internal_category_id`, `brand`); kategori tablosu yok |
| Kanonik yaratma (`CreateProduct`, `AddVariantToProduct`) | EXISTS | açılış stoğu `IMPORT` hareketi; bütün içe aktarıcılar bunları kullanıyor |
| `UpdateProduct` | RISKY | boş geçilen açıklama/marka/kategoriyi NULL'a ezer; çok varyantlıda fiyatı sessizce atlar |
| Ürün kotası (`EnforceQuota::remaining`) | EXISTS | yalnız ürün sayılır, varyant sayılmaz; CSV ve kanal içe aktarması uyguluyor |
| Tedarikçi kaynağı / kaynak ↔ varyant bağı | MISSING | `listings` yalnız ÇIKIŞ (dağıtım) bağıdır |

### 1.2 Envanter

| Bileşen | Durum | Not |
|---|---|---|
| Defter `inventory_movements` (ekleme-yalnız, `(tenant_id, idempotency_key)` tekil) | EXISTS | |
| Bakiye `inventory_levels` (`on_hand` eksi olabilir, `available` türetilmiş) | EXISTS | doğrudan yazılmaz |
| `ApplyMovement` + `LockInventoryRows` | EXISTS | idempotens bul-sonra-yaz (kilit altında güvenli) |
| `AdjustStock::setTo` (SAYIM: "rafta X var") | EXISTS | anahtar her çağrıda YENİ (bilinçli: elle düzeltme) → toplu/tekrar edilebilir iş için çıpa YOK |
| Tedarikçi / toplu düzenleme hareket anahtarı | MISSING | |
| Depo | EXISTS (tek varsayılan) | ADR-009; S5 varsayılan depoya yazar |
| Stok fan-out (`InventoryLevelChanged` → `PushInventory`) | EXISTS | `stock_sync_enabled` hem planlamada hem iş başında (ADR-010) |

### 1.3 Fiyat / maliyet

| Bileşen | Durum | Not |
|---|---|---|
| `SetVariantPrice` (outbox `VariantPriceChanged`, aynı transaction) | EXISTS | float girdi; değişmese de `content_version` artar |
| `variants.cost_price` + `SetVariantCost` | EXISTS / RISKY | transaction ve denetim kaydı yok |
| `channel_price_rules` | EXISTS | `markup_percent` = SATIŞ FİYATI üzerine kanal farkı; `min_margin_percent` = maliyet × (1+%) TABANI |
| Maliyetten satış fiyatı türetme | MISSING | |
| Satış anı maliyet görüntüsü (ADR-002, `order_lines.unit_cost` + değişmezlik tetikleyicisi) | EXISTS | maliyet değişikliği geçmişi değiştirmez |
| `price_sync_enabled` kapısı | EXISTS | planlama + iş başı |

**Terim bulgusu (S5'te değiştirilmedi, TD-47):** `min_margin_percent` adında "margin" geçer ama formülü `maliyet × (1 + %)` — matematiksel olarak MARKUP'tır (kâr/maliyet), MARGIN (kâr/satış) değil. Panel metni "En az kâr" dediği için kullanıcıya yanlış bir şey söylenmiyor; ad koddaki tek yanıltıcı yer.

### 1.4 İçe/dışa aktarma

| Bileşen | Durum | Not |
|---|---|---|
| CSV içe aktarma (`ImportProducts`, `product_imports`) | EXISTS | önizleme YOK; ürün SKU'suyla eşler |
| Kanal katalog içe aktarması (`ImportProductsFromChannel`, `RemoteProduct`) | EXISTS | bağ önceliği Listing → varyant SKU → ürün SKU → grup |
| Excel/XLSX kütüphanesi | MISSING | composer'da yok |
| Dışa aktarma (CSV/XLSX) | MISSING | |
| Akışla XML ayrıştırma | MISSING | yalnız SOAP için `DOMDocument::loadXML(LIBXML_NONET)` |

### 1.5 Kuyruk / güvenilirlik

| Bileşen | Durum | Not |
|---|---|---|
| Horizon havuzları + `HorizonQueueContractTest` | EXISTS | `listing:bulk` `background` havuzunda |
| `TenantAwareJob` | EXISTS | |
| Zamanlayıcı (`onOneServer()->withoutOverlapping()`) | EXISTS | :00 dolu → saatlik iş başka dakikaya |
| İş tekilliği (`ShouldBeUnique`) | MISSING | DB koşullu güncelleme / `SKIP LOCKED` kalıbı kullanılıyor |
| İmleç | EXISTS | `channel_connections.settings`; yalnız tam tur bitince ilerler |

### 1.6 Güvenlik

| Bileşen | Durum | Not |
|---|---|---|
| `OutboundUrlGuard` (özel/ayrılmış aralık, iç sonekler, DNS çözüm, IP sabitleme) | EXISTS | |
| Şema denetimi | **RISKY** | guard şema bakmaz; `ChannelHttpClient::download('http://…')` sabitlemeyi 443'e yazar, bağlantı 80'e gider ve curl kendi DNS'ini çözer → port 80 SSRF + DNS rebinding. Tedarikçi görsel adresleri kanallara bu yoldan inebileceği için **S5 engeli olarak S5'te kapatıldı** (§8) |
| `download()` boyut sınırı | RISKY | gövdeyi tamamen okuduktan sonra ölçer |
| Kiracı yalıtımı (`BelongsToTenant`, string kimlik + `findOrFail`) | EXISTS | |
| Sır saklama (`CredentialVault`, `encrypted` cast) | EXISTS | |
| Dosya yükleme | PARTIAL | CSV 2 MB, mimetype; XLSX yok |

### 1.7 WooCommerce

| Alan | Durum | Not |
|---|---|---|
| AUTH | PARTIAL | anahtar/sır elle yapıştırılıyor (Basic auth); `/wc-auth/v1/authorize` YOK |
| PRODUCT READ | EXISTS | yalnız üst ürün; `type=variable` tek varyant gibi gelir |
| PRODUCT WRITE | PARTIAL | hep `type=simple`; `GuardsAmbiguousCreate` yok |
| VARIANTS | **MISSING** | `products/{id}/variations` hiç okunmuyor/yazılmıyor |
| STOCK / PRICE | EXISTS (yalnız basit ürün) | `products/batch`; varyasyon kimliği gelirse kalem hatası |
| ORDERS | EXISTS | satır `line_items[].id`; varyant yalnız SKU ile eşleşir (`variation_id` okunmuyor) |
| ORDER STATUS | EXISTS | `refunded/checkout-draft/trash` eşlenmemiş |
| CANCELLATION | PARTIAL | yalnız gelen |
| REFUNDS | EXISTS | kümülatif, `_refunded_item_id` |
| WEBHOOKS | EXISTS | HMAC-SHA256 base64, `hash_equals`, teslim kimliğiyle tekilleştirme |
| POLLING | — | webhook kanalı yoklanmaz |
| RECONCILIATION | **MISSING** | kaybolan webhook = kaybolan sipariş |
| RATE LIMIT / RETRY | EXISTS | `Retry-After` okunmuyor |
| TENANT ISOLATION | EXISTS | bağlantı başına sır |
| OUTBOUND AUTHORIZATION | EXISTS / RISKY | stok/fiyat/yayın kapılı; bağlanırken webhook kaydı izinsiz (yapılandırma yazması, okuma için gerekli); `PushFulfillment` kapısız (ADR-010 kapsam dışı); eski bağlantılar geçişte üç izin `true` aldı |

---

## 2. Mimari kararlar (ADR-012)

Ayrıntı `ARCHITECTURE-DECISIONS.md` ADR-012. Özet:

1. **Kaynak ≠ ürün.** `supplier_sources` (kaynak), `supplier_records` (tedarikçi kaydı başına TEK satır: ham alanlar + yorum + bağ), `supplier_sync_runs` (tur ve özet), `supplier_run_changes` (tur başına değişiklik satırları). Kanonik ürün/varyant tedarikçi durumu TAŞIMAZ.
2. **Kimlik:** `(kaynak, source_key)` — eşlemede seçilen kimlik alanı (tedarikçi kodu/ID/varyant ID). Dizi sırası ASLA. Kimliği boş kayıt `SUPPLIER_IDENTITY_MISSING`; aynı kimlik iki kez → ikisi de `SUPPLIER_DUPLICATE_IDENTITY`, hiçbiri uygulanmaz.
3. **Bağ:** ilk eşleşmede `supplier_records.variant_id` yazılır; sonraki turlar bağı kullanır, yeniden eşlemez. Eşleşme sırası: mevcut bağ → varyant SKU → barkod. SKU ve barkod farklı varyantı gösterirse `SUPPLIER_MATCH_AMBIGUOUS`; başka kaynağa bağlı varyant `SUPPLIER_VARIANT_ALREADY_BOUND`. Belirsizlikte stok/fiyat/ürün yazılmaz. Bulanık eşleme YOK.
4. **Stok politikası (kaynak başına, açık seçim):** `informational` (VARSAYILAN — tedarikçi stoğu yalnız kaydedilir, deftere dokunmaz) · `authoritative` (tedarikçi DEĞİŞTİRDİĞİNDE eldeki stok tedarikçinin değerine ayarlanır). "Değişince ayarla" seçildi: tedarikçi aynı değeri tekrarladığında bizim satışımız geri eklenmez; tedarikçi bizim siparişimizi kendi stoğundan düşünce de çift düşüş olmaz. Bilinen pencere: tedarikçi bizim satışımızı işlemeden stok artırırsa en fazla bir tur fazla görünür (belgelendi).
5. **Defter:** stok yalnız `ApplyMovement` (kilit altında); anahtar `supplier:{kayıt}:{tur}`; kaydın `last_applied_stock` alanı aynı transaction'da yazılır → tekrar ve çökme sonrası yeniden deneme ikinci hareket üretmez.
6. **Yokluk ≠ sıfır.** Akışta görünmeyen kayıt `presence = missing` ve özet satırı olur; stok DEĞİŞMEZ. Yokluktan sıfırlama seçeneği S5'te YOK (en küçük güvenli model).
7. **Bilinmeyen ≠ sıfır.** Her sayısal alan `state ∈ {present, missing, invalid}` + değer; `missing/invalid` hiçbir yazma üretmez. Fiyat 0 geçerli bir değerdir ama satış fiyatı olarak kullanılmaz (`SUPPLIER_PRICE_ZERO`).
8. **Tamlık:** akış önce boyut sınırlı geçici dosyaya iner, sonra TAM bir doğrulama geçişi (iyi biçimlilik + kimlik tekrarı) yapılır; geçiş bitmeden hiçbir şey uygulanmaz. Yarıda bozuk XML = `PARSE_FAILED`, sıfır yazma. Uygulama kayıt başına transaction; tur yarıda çökerse `failed`, `last_succeeded_at` İLERLEMEZ, yokluk işaretlemesi YAPILMAZ; sonraki tur idempotent baştan geçer.
9. **Maliyet ve fiyat:** eşlenen alış fiyatı `variants.cost_price`'a (`SetVariantCost`); satış fiyatı politikası `none` · `feed_price` (akıştaki satış fiyatı) · `cost_markup` = **MARKUP**: `maliyet × (1 + %/100) + tutar`, kuruşta, mevcut yuvarlama kipleriyle (`none/whole/x90/x99`, daima yukarı). Geçerli maliyet yoksa fiyat UYDURULMAZ. Para birimi varyantla tutmazsa `SUPPLIER_CURRENCY_MISMATCH`. Geçmiş maliyet `order_lines` görüntüsünde değişmez.
10. **Fan-out:** tedarikçi işi kanala HİÇBİR ZAMAN doğrudan istek atmaz; stok defter → outbox → `PushInventory` (`stock_sync_enabled`), fiyat `SetVariantPrice` → outbox → `PushPrices` (`price_sync_enabled`).
11. **Excel:** dışa aktarma teknik kolon olarak `variant_id` ve DIŞA AKTARIM ANINDAKİ stok/fiyatı taşır; değişiklik = kullanıcının hücreyi dışa aktarımdakinden farklı yapması. Anlam **SET** ("stok = X"), fark kilit altında. Yükleme yalnız ayrıştırır ve önizler; uygulama ayrı ve açık eylem, tek sefer (koşullu durum geçişi), satır anahtarı `bulk:{yükleme}:{satır}`.
12. **Woo:** resmî `/wc-auth/v1/authorize` (bekleyen bağlantı + tek kullanımlık `user_id` nonce, callback yalnız 200, sırlar kasada). Bağlantı üç yazma izni KAPALI doğar.

---

## 3. Uygulama durumu (14 Eki 2026 gece) — commit'ler yerelde, PUSH/DEPLOY YOK

| Commit | İş |
|---|---|
| `6d7d73d` | Tedarikçi XML (alan modeli, SSRF, akışla XML, doğrulama geçişi, defter, maliyet+markup, önizleme/uygulama, saatlik tur) + `ChannelHttpClient` http SSRF kapatması |
| `e278ed7` | Excel toplu fiyat/stok (openspout, iki aşama, SET, satır sebep kodları) |
| `0c52fd2` | Woo bağlantı sihirbazı (`/wc-auth/v1/authorize`) |
| `896a393` | Woo hazırlık: varyasyonlar, ilan kimliği bağı (Woo içe aktarması HİÇ bağlamıyordu), varyasyon uçları, satır `variation_id` eşleşmesi, sipariş mutabakatı |
| `55d939e` | M25 mutasyonunu öldüren test |

Migration'lar: `2026_10_17_000100_create_supplier_tables`, `2026_10_17_000200_create_bulk_edit_tables` (veri dönüşümü yok). Yeni bağımlılık `openspout/openspout ^4.28`. Zamanlayıcı: `suppliers:sync` 5 dk.

**Testler:** tam takım 2531 yeşil (+114). **Mutasyon:** 34'ün 33'ü öldü; M02 eşdeğer (geçersiz stok `hasValue()` korumasında döner, mutasyonlu satıra ulaşılmaz). İlk turda M25 yaşadı (önizleme kanal sayısı testi yoktu) → test eklendi, öldü.

**Performans:** 500 kayıtlık ilk içe aktarma test ortamında ~6 sn. 5000 kayıt ölçümü GEÇERSİZ: `RefreshDatabase` tek dış transaction → Postgres "out of shared memory" (kayıt başına savepoint kilidi birikir). Üretimde kayıt başına commit var; ölçüm dev DB'de, test sarmalayıcısı dışında YENİDEN yapılmalı. N+1: kayıt başına ~6–8 sorgu (kayıt, SKU, barkod, bağ, seviye) — 500'de kabul edilebilir, büyük akışta toplu ön yükleme TD-48.

**Canlı kanıt: YOK.** Gerçek toptancı XML'i ve Woo test mağazası verilmedi → kabul ölçütleri karşılanmadı.

| Yetenek | Durum |
|---|---|
| Tedarikçi XML içe aktarma / eşleme / önizleme / uygulama | IMPLEMENTED — LIVE VERIFICATION PENDING |
| 500 ürün kabulü | PENDING (gerçek akış yok; fixture kanıt sayılmaz) |
| Saatlik güncelleme | IMPLEMENTED — ikinci tur canlıda gözlenmedi |
| Excel dışa/içe aktarma | IMPLEMENTED — LIVE VERIFICATION PENDING |
| Woo sihirbazı / okuma / varyasyon / mutabakat / yazmalar | IMPLEMENTED — LIVE VERIFICATION PENDING |

**Yeni teknik borç:** TD-47 `min_margin_percent` adı (formül markup) · TD-48 tedarikçi turunda kayıt başına sorgu (toplu ön yükleme) · TD-49 Woo değişken ürün YAYINI yok (gönderim hâlâ `type=simple`; çok varyantlı ürünü Woo'ya göndermek tek ürün gibi gider) · TD-50 Woo `PushFulfillment` ADR-010 kapısız (S5 öncesi) · TD-51 bağlanırken Woo webhook kaydı izinsiz yapılandırma yazması (sipariş okumak için gerekli, bilinçli) · TD-52 tedarikçi alış fiyatı KDV dahil/hariç ayrımı yok (olduğu gibi alınır).

**Sprint durumları:** S1–S4 DEĞİŞMEDİ. **S5: PARTIAL — IMPLEMENTED, LIVE VERIFICATION PENDING.**

---

## 4. Canlıya alma (15 Eki 2026 21:20 UTC) — `8d48a11`

- **Push:** altı S5 commit'i (`6d7d73d` `e278ed7` `0c52fd2` `896a393` `59e5b0f` `8d48a11`). Push öncesi Pint `55d939e`'deki M25 testinde 1 biçim hatası verdi (tam nitelikli sınıf adı); push'lanmamış commit'e katıldı (`55d939e`→`59e5b0f`, belge `7be6e1c`→`8d48a11`), içerik farkı yalnız `use` satırları. İlgisiz dosya yok. Önceki canlı `3789115`.
- **OpenSpout:** kilitte yalnız `openspout/openspout v4.32.0` eklendi (başka paket değişmedi), PROD bağımlılığı; ister php ~8.3, ext-dom/fileinfo/filter/libxml/xmlreader/zip — canlı PHP 8.3.35'te hepsi var. Üretim imajı (`composer install --no-dev` kilitten + `dump-autoload --optimize --classmap-authoritative` + `check-platform-reqs`) yerelde ve sunucuda derlendi; canlıda `OpenSpout\Writer\XLSX\Writer` yükleniyor (classmap'te 150 sınıf).
- **Yedek:** `/var/backups/oncesi-s5-34pazar-20261015-2112.dump` (21:12:45Z) sha256 `b38969eca25c1757c4af3c44dedc5657453bb16c6091f71f48f8eb467f083ab3`. `s5_restore_test`'e `pg_restore --exit-on-error` tamam; 38 tablonun adet + içerik md5'i canlıyla birebir aynı (products 646, variants 653, listings 653, orders 5, order_lines 5, inventory_movements 655, inventory_levels 653, invoices 0, S1 fin_tx 4, return_requests 0, channel_questions 4, inbox 32, outbox 661, jobs 0, failed_jobs 0, bağlantı 6).
- **Migration (yedek kopyasında, S5 imajıyla):** UP 62,7 ms + 23,4 ms → DOWN 29,0 ms + 44,2 ms (6 tablo gitti) → UP 62,5 ms + 24,6 ms; üç durumda da 38 tablonun md5'i yedekle aynı, `channel_connections` izinleri değişmedi. Varsayılanlar: `is_active=false`, `stock_policy=informational`, `price_policy=none`, `create_products=false`. **Canlıda:** `2026_10_17_000100_create_supplier_tables` 74,5 ms, `2026_10_17_000200_create_bulk_edit_tables` 28,2 ms.
- **Ölçüm (yerel ayrı DB `s5_bench`, üretim verisi yok, bağlantı 0 → dış yazma imkânsız; Postgres 16.15 ayarları canlıyla aynı: max_locks_per_transaction 64, shared_buffers 128 MB):**

| N | Tur | Süre | Pik bellek | Sorgu/kayıt | Sonuç | Stok hareketi |
|---|---|---|---|---|---|---|
| 500 | ilk | 2,0 sn | 50,5 MB | 19,2 | 500 yeni, 0 hata | +429 (71 kayıt stok 0) |
| 500 | aynı akış | 0,5 sn | 50,5 MB | 3,0 | 500 değişmedi | 0 |
| 500 | %10 stok | 0,5 sn | 50,5 MB | 3,9 | 50 değişti | +50 |
| 1000 | ilk | 4,2 sn | 50,5 MB | 19,2 | 1000 yeni | +858 |
| 1000 | aynı akış | 0,9 sn | 50,5 MB | 3,0 | 1000 değişmedi | 0 |
| 1000 | %10 stok | 1,1 sn | 50,5 MB | 3,9 | 100 değişti | +100 |
| 5000 | ilk | 22,2 sn | 52,5 MB | 19,1 | 5000 yeni, 0 hata | +4286 |
| 5000 | aynı akış | 6,1 sn | 52,5 MB | 3,0 | 5000 değişmedi | 0 |
| 5000 | %10 stok | 7,2 sn | 52,5 MB | 3,9 | 500 değişti | +500 |

  Doğrusal ölçekleniyor, bellek sabit; iş zaman aşımı 540 sn'nin çok altında. Ölçüm sonrası defter = projeksiyon (uyuşmazlık 0), çift anahtar 0; ölçüm penceresinde Postgres hatası 0. **Kök neden doğrulandı:** önceki "out of shared memory" `RefreshDatabase`'in tek dış transaction'ında kayıt başına savepoint kilitlerinin birikmesiydi; üretim yolunda kayıt başına commit olduğundan oluşmuyor. Kalan maliyet: ilk turda kayıt başına ~19 sorgu (oluşturma), sonraki turlarda ~3 → TD-48 (toplu ön yükleme) iyileştirme, engel değil.
- **Testler:** tam takım **2532 yeşil** (belge 2531 diyordu: sayı M25 testinden önce alınmıştı), Pint temiz (düzeltme sonrası), `npm run build` tamam. S5 dosyaları 134/134.
- **Deploy:** `nohup ./deploy.sh`, 21:20:42 başladı; app/horizon/scheduler/relay/web yeniden oluşturuldu, dördünde de S5 kodu. `/up` 200, Horizon running, failed 0, jobs 0.
- **Kapalı doğma (tedarikçi):** zamanlayıcıda `suppliers:sync` */5; 21:25 turu 254 ms, kaynak 0 → tur 0, iş 0. supplier_sources/records/sync_runs/run_changes, bulk_edit_imports/rows = 0. Deploy sonrası (21:20–21:27) yeni stok hareketi 0, outbox 0, GET dışı dış çağrı 0 (yalnız 5 olağan GET 200).
- **Kapalı doğma (Woo):** canlıda Woo bağlantısı YOK, `channel_types.woocommerce.is_active=false`; üretimde bağlantı açılmadı. `channel_connections` izin sütunlarının DB varsayılanı beşi de `false`; sihirbaz yolu `callback_stores_keys_once_then_health_and_webhooks_run_with_writes_closed` ile.
- **Taban farkı T0 (21:20:38) ↔ T1 (21:27:17):** 38 tablonun adet + içerik md5'i, bağlantıların sabit sütunları ve 6 bağlantının 5 izni AYNI. Fiyat toplamı 112463.24, maliyet tanımlı varyant 0, on_hand toplamı 589057 — değişmedi.
- **Gözlem T2 (21:58:17, deploy +38 dk):** 38 tablodan 37'si T0 ile aynı; tek fark `channel_credentials` — yedekle sütun sütun karşılaştırıldı, değişen yalnız `refresh_failures` ve `refresh_retry_at` (Etsy pending bağlantısının saatlik token yenilemesi, 21:30 `POST oauth/token` 403 — S4'ten beri bilinen anomali, S5 ile ilgisiz). İzinler aynı; deploy'dan beri GET dışı tek dış çağrı bu Etsy yenilemesi; outbox 0, stok hareketi 0, S5 tabloları 0, jobs/failed 0; `suppliers:sync` 35 dk'da 7 kez DONE; Horizon running, `/up` 200, uygulama günlüğünde ERROR 0. Geçici DB'ler (`s5_restore_test`, `s5_mig_test`) ve kontrol imajı silindi; yedek yerinde.
- **Ek kanıt testleri (commit'lenmedi, kapsam altı commit):** Woo içe aktarma → 3 kaydın üçüne dış kimlik bağı → içerik değişikliği + yayın → tek yazma `PUT /products/60`, `POST /products` 0. SSRF görsel indirme: http ve https için 127.0.0.1, localhost, 10/8, 192.168, 172.16, 169.254.169.254, [::1], [fd00::1], [fe80::1], [::ffff:127.0.0.1] ve iç IP'ye çözülen genel ad → 29/29 engel, istek 0; genel https → 302 → metadata: tek istek, iç hedefe 0 (yönlendirme kapalı). Bu iki dosya bir sonraki işte kalıcı teste dönüştürülmeli (TD-53).
- **Yeni teknik borç:** TD-53 yukarıdaki iki kanıt testinin depoya alınması.
