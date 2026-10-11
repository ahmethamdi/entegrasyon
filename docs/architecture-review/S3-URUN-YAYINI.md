# S3 · Hepsiburada ve N11'e ürün gönderme (12 Ekim 2026)

**Durum: PARTIAL — IMPLEMENTED, LIVE VERIFICATION BLOCKED** (iki kanalda da gerçek hesap/kimlik yok; N11'in test ortamı yok).

## 1. Denetim (kod öncesi)

| Yetenek | Hepsiburada | N11 | Not |
|---|---|---|---|
| Kimlik / ortam | EXISTS (SIT+canlı) | EXISTS (yalnız canlı) | |
| Kategori / öznitelik okuma | MISSING | MISSING | yalnız belgede |
| Marka | MISSING | MISSING | HB serbest metin; N11 = özellik `id 1` |
| Ürün / varyant yayını | MISSING | MISSING | stub bile yok |
| Fiyat / stok yayını | EXISTS | EXISTS | `stock-uploads`/`price-uploads`, `price-stock-update` |
| Listing güncelleme | MISSING | MISSING | |
| Onay / red okuma | MISSING | PARTIAL | N11 task-details vardı |
| Görsel | PARTIAL (yalnız içe aktarma) | PARTIAL | |
| Barkod / SKU kuralı | MISSING | MISSING | |
| Hız sınırı / yeniden deneme / devre kesici | EXISTS | EXISTS | HB katalog 403'ü yanlışlıkla kimlik hatası sayılıyordu |
| İdempotens | PARTIAL | PARTIAL | `PushListing` "önce ara"; belirsiz asenkron yaratma koruması yok |
| Kiracı izolasyonu | EXISTS | EXISTS | |
| Testler | yalnız adapter (inline fake) | aynı | canlı fixture yok |
| Gerçek hesap | **YOK** | **YOK** | `channel_connections` boş, iki kanal `is_active=false` |

Yeniden kullanılan mimari: `PublishListing` → `PrerequisiteGate` → `OpenSyncOperation` → `PushListing` (varyant başına listing), `TrackApprovalStatus`, eşleme tabloları, `ChannelImages`, `Listing::effectivePrice()`, `RetryPolicy`, devre kesici, hız kovası.

## 2. Kaynaklar
- **HB**: developers.hepsiburada.com portalı bot isteklerine 403 verir; gerçek Chrome (Playwright, boş profil) ile portalın genel belge API'si okundu: "Katalog Ürün Giriş Önemli Bilgiler", "Katalog Ürün Entegrasyonu Test Süreci", "Ürün Güncelleme Önemli Bilgiler" (11 Eki) + `docs/hepsiburada-openapi/katalog.json`.
- **N11**: developer.n11.com "Ürün Yükleme", "Kategori Ağacı Listeleme", "Kategori Özellikleri Listeleme", "Ürün Bilgisi Güncelleme", "Task Detail Sorgulama", "Satıcı Ürün Sorgulama" (11 Eki).

## 3. Uçlar
| Kanal | Uç | Kullanım |
|---|---|---|
| HB | `GET mpop/product/api/categories/get-all-categories?leaf=true&status=ACTIVE&available=true` | yaprak kategoriler (sayfalı, ≤2000) |
| HB | `GET …/categories/{id}/attributes` | `baseAttributes`, `attributes`, `variantAttributes` |
| HB | `GET …/categories/{id}/attribute/{attrId}/values` | enum değerleri `{id, value}` |
| HB | `POST …/api/products/import` (JSON dosyası, form-data) | ürün girişi → `trackingId` |
| HB | `POST …/api/products/check-product-status` | merchantSku → statü ("önce ara", onay) |
| HB | `GET …/api/products/status/{trackingId}` | satır sonucu, `hbSku`, doğrulama hataları |
| HB | `GET listing-external/Listings/merchantid/{id}?merchantSkuList=` | onaydan sonra `hbSku` |
| N11 | `GET /cdn/categories`, `GET /cdn/category/{id}/attribute` | taksonomi (key+secret) |
| N11 | `POST /ms/product/tasks/product-create` / `product-update` | görev → `taskId` |
| N11 | `GET /ms/product-query?stockCode=` | "önce ara", onay statüsü |
| N11 | `POST /ms/product/task-details/page-query` | görev satır sonucu |

## 4. Eşleme
- **Ürün → listing:** kanonik ürün kanal ilanı OLMAZ. Listing VARYANT başınadır (`listings_connection_variant_unique`); grup kimliği kanal tarafında: HB `VaryantGroupID` = ürün SKU'su, N11 `productMainId` = ürün SKU'su. `external_parent_id` grup kimliğini taşır.
- **Varyant → kanal varyantı:** HB `merchantSku` (BÜYÜK HARF) → onaydan sonra `hbSku`'ya geçer (`channel_metadata.hb_sku`, `merchant_sku`); N11 `stockCode` (içe aktarmayla tutarlı). Barkod varyantın kendisi.
- **Kategori / öznitelik:** `category_mappings` (iç kategori → yaprak). Varyant belirleyici (`is_variant_defining`) öznitelik `attribute_mappings` + `attribute_value_mappings` ile varyantın KENDİ seçeneğinden; açık eşleme yoksa kanal listesinde **birebir** aynı etiket; değilse serbest metin kabul eden öznitelikte seçenek metni; aksi engel. Betimleyici zorunlu öznitelik ürün düzeyinde `product_channel_attribute_values` (yeni) — panelde "Kanal öznitelikleri" bölümü. Marka: HB `Marka` metni; N11 özellik `1` (listede birebir → `valueId`, değilse serbest değer kabul ediliyorsa `customValue`, değilse `BRAND_MAPPING_REQUIRED`). `brand_mappings` tablosu AÇILMADI: iki kanalda da marka ayrı kimlik servisi değil.

## 5. Yayın doğrulaması (dış yazmadan ÖNCE, ürünün TÜM varyantları)
`ValidatesPublication` (kanalın kendi kuralları, kapı `PrerequisiteGate` içinden). Tek eksik varsa HİÇBİR varyant gönderilmez (yarım grup = kısmi dış yazma).
- Ortak (çözücü `CatalogRequirements`): zorunlu varyant/betimleyici öznitelik, varyant değer eşlemesi, kardeşlerin ayırt edilebilirliği (`INVALID_VARIANT`).
- HB: marka, başlık, açıklama (belge: her üründe), EAN-13 barkod (kontrol hanesi), merchantSku karakter kuralı, HTTPS görsel, fiyat > 0, KDV ∈ {0,1,10,20} (bağlantı ayarı `vat_rate`).
- N11: başlık ≥ 15, açıklama, marka, KDV, `shipment_template`, `preparing_day` ≥ 1 (bağlantı ayarları), stockCode ≤ 255, görsel, fiyat, para birimi TL/USD/EUR.

## 6. Fiyat / stok
`ListingProjection`: stok = varsayılan depo `inventory_levels.available` (`OutboundQuantity`), fiyat = `Listing::effectivePrice()` (kanal fiyatı → kural → kampanya), liste fiyatı = karşılaştırma fiyatı ya da satış. HER ŞEY listing'in kendi varyantından. Yayın `inventory_movements` YAZMAZ (testli). Onay geldiğinde (`TrackApprovalStatus`) korumalı akışla açılmış satır için INVENTORY + PRICE yeniden senkronu istenir — onay beklerken değişen stok kanala eski kalmaz. HB: onaydan sonra ürün gönderilen fiyat/stokla otomatik satışa açılır (belge).

## 7. Görseller
`ChannelImages::urlsFor(variant, kanal)`: önce varyantın KENDİ görselleri, sonra ortak görseller; kardeşin görseli hiç gelmez; kanal dışlaması uygulanır. HB `Image1..5`, N11 `images[{url, order}]` (≤8). İki kanalda da görsel öğe (varyant) düzeyinde gider; ortak görseller her varyant öğesine projekte edilir.

## 8. Yaşam döngüsü
`draft → (blocked) → pending_approval → live | rejected`. 200/IN_QUEUE **yayında demek değildir**.
- HB (belge statüleri; canlıda doğrulanmadı): MATCHED/CREATED + `hbSku` bulundu → live (kimlik `hbSku`); PRE_MATCHED → bekliyor + "Hepsiburada: PRE_MATCHED" (satıcı MPOP'ta onay/ret); MISSING_INFO/REJECTED → rejected (doğrulama/yükleme mesajları); WAITING, MATCHED_WITH_STAGED → bekliyor; girişte yok + yükleme FAILED → rejected. `hbSku` başka satırdaysa onay UYGULANMAZ.
- N11: Active → live; InCatalogApproval/InApproval → bekliyor; CatalogRejected → rejected (`rejectInfo`); Suspended/Prohibited/Unlisted → bekliyor (inactive); ürün yok + görev satırı FAIL → rejected.

## 9. CREATE / UPDATE / RETRY / RE-PUBLISH
- **CREATE:** `external_id` yok → önce ara (`findExistingListing`) → yoksa `listings.create_requested_at` KOŞULLU talep → tek istek. Kimlik (merchantSku/stockCode) başarıda hemen yazılır.
- **RETRY** (kuyruk): talep doluysa POST YOK; yalnız ara. Bulunursa kimlik benimsenir (uzlaştırma, güncelleme çağrısı yok); bulunmazsa `REMOTE_STATE_UNKNOWN` (geçici; sonraki denemeler yine arar). Kesin ret (4xx doğrulama, kimlik, 429, HB katalog 403) talebi geri alır.
- **UPDATE:** HB onay öncesi = aynı merchantSku ile yeniden yükleme (belge); canlı HB ürünü → `LISTING_UPDATE_UNSUPPORTED` ("Ürün Güncelleme" ticket servisi YAZILMADI). N11 yalnız `live` → `product-update` (açıklama, KDV, hazırlık, şablon, betimleyici öznitelik; başlık/görsel/marka/renk-beden N11'de yönetilir).
- **RE-PUBLISH:** yalnız satıcının açık "kanala gönder" eylemi (`PublishListing`) kimliksiz satırın talebini sıfırlar.
- `GuardsAmbiguousCreate` yalnız HB/N11'de; Trendyol/Shopify/Woo davranışı değişmedi.

## 10. Sebep kodları (`PublishReason`, `listing_sync_states.reason_code`)
CATEGORY_REQUIRED, CATEGORY_MAPPING_REQUIRED, ATTRIBUTE_MAPPING_REQUIRED, ATTRIBUTE_VALUE_MAPPING_REQUIRED, ATTRIBUTE_VALUE_REQUIRED, BRAND_REQUIRED, BRAND_MAPPING_REQUIRED, BARCODE_REQUIRED, BARCODE_INVALID, SKU_INVALID, IMAGE_REQUIRED, TITLE_INVALID, DESCRIPTION_REQUIRED, PRICE_INVALID, VAT_RATE_INVALID, CURRENCY_UNSUPPORTED, INVALID_VARIANT, CHANNEL_SETTING_REQUIRED, LISTING_UPDATE_UNSUPPORTED, LISTING_REJECTED, LISTING_SYNC_FAILED, RATE_LIMITED, REMOTE_STATE_UNKNOWN. Kanalın red metni `listings.approval_rejection_reason`'da ham kalır.

## 11. Canlı doğrulama
| Kanal | Yetenek | Durum |
|---|---|---|
| HB | taksonomi, doğrulama, import, arama, onay, kimlik geçişi | **IMPLEMENTED — LIVE VERIFICATION BLOCKED** (kimlik/SIT yok; SIT'te ürün onaylayan ekip yok — belge) |
| N11 | taksonomi, doğrulama, product-create/update, arama, onay | **IMPLEMENTED — LIVE VERIFICATION BLOCKED** (anahtar yok; sandbox yok → her yazma gerçek ürün) |
| İkisi | kanal açma (`is_active`) | **BLOCKED** — kapalı kaldı |

**IMPLEMENTED — NOT LIVE VERIFIED:** HB temel öznitelik kimliklerinin kategori yanıtındaki gerçek adları (`AUTO_FILLED`), `import` yanıtında `trackingId` konumu (`data.trackingId` ya da kök), `check-product-status` statü değerlerinin gerçek yazımı, `status/{trackingId}` alan adları, aynı `VaryantGroupID` ile ayrı dosyalarda gelen varyantların gruplanması, aynı merchantSku ile yeniden yüklemenin davranışı, değer çağrısı yanıt sarmalayıcısı; N11 `rejectInfo` biçimi, `product-query` stockCode filtresinin tam eşleşmesi, görev sonucu gecikmesi, KDV/fiyat yuvarlama kabulü; iki kanalda onay süresi ve red metinleri.

## 12. Bilinen sınırlar / sonraki
HB canlı ürün içerik güncellemesi (ticket servisi), HB/N11 `delist`, toplu (çok ürünlü) yayın ekranı, yayın çağrılarının ayrı hız kovası, marka için elle eşleme ekranı (gerekirse), kategori değişince eski betimleyici değerlerin temizliği.
