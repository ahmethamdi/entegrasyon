# 07 · Test boşluk analizi (risk bazlı)

Statik analiz; test paketi bu turda çalıştırılmadı. ~2.055 test metodu, 182 dosya (veri sağlayıcılarla ~2.085).

## Envanter

| Klasör | Dosya | ~Test | Not |
|---|---|---|---|
| Feature/Channels | 71 | ~944 | Kanal başına sözleşme testleri, `Http::fake` |
| Feature/Sync | 21 | ~233 | Push işleri, sürüm kapısı, önkoşul, ölü mektup, toplu iş |
| Feature/Orders | 15 | ~141 | Alım, yoklama, kanal başına uçtan uca "dilim" |
| Feature/Catalog | 11 | ~135 | Fiyat kuralı, kampanya, içe aktarma |
| Feature/Observability | 5 | ~92 | |
| Feature/Billing | 7 | ~87 | |
| Feature/Identity | 9 | ~76 | |
| Feature/Reconciliation | 5 | ~71 | |
| Feature/Invoicing | 5 | ~67 | |
| Feature/Inventory | 6 | ~56 | 2 gerçek eşzamanlılık sınıfı |
| Feature/Messaging | 6 | ~53 | |
| Feature/Security | 6 | ~23 | |
| Feature/Tenancy | 3 | ~13 | |
| Diğer + Unit | 13 | ~64 | |

**Güçlü yanlar:** mutasyon testi kültürü (her testin hangi mutasyonu öldürdüğü yazılı), `Http::preventStrayRequests()`, `_test` olmayan veritabanını sıfırlamayı reddeden koruma, şifreli sipariş verisi denetimi, ikinci gerçek PDO bağlantısıyla kilit testleri, kanal başına uçtan uca dilim testleri.

## Risk matrisi

| Risk | Kapsam | Boşluk |
|---|---|---|
| Kopya sipariş alımı | YES | Aynı siparişin iki farklı inbox satırının (created + sahiplenen updated) eşzamanlı işlenmesi |
| Eşzamanlı stok değişimi | YES (betikli) | Gerçek paralel süreç yok; sipariş alımı ve inbox sahiplenme düzeyinde yarış yok |
| Fazla satış | YES | Hacim testi (100 sipariş, 1 SKU, defter = projeksiyon) |
| Yeniden deneme idempotensi | YES | SKU ile arama yapamayan kanalda zaman aşımı sonrası kopya |
| Başarısız API (5xx/zaman aşımı) | PARTIAL | "Belirsiz sonuç" (istek zaman aşımı ama kanal uyguladı) kargo/fatura yüklemede |
| Token süresi/yenileme/iptal | YES | Geçerli görünen token'da sunucu iptali → yenile → yeniden dene → devre sıfırla |
| Hız sınırı | YES | Yoklama turunda 429 → imleç korunur |
| Kısmi/sırasız güncellemeler | **PARTIAL** | **Oluşturmadan önce gelen iptal/iade — test bugünkü (yanlış) davranışı kilitliyor** (`InboundPipelineTest::event_for_unknown_order_is_marked_processed`) |
| İptaller | YES | Oluşturmadan önce iptal |
| İadeler | PARTIAL | Trendyol talepleri yok (S4) |
| Para iadesi tutarı | NO | Özellik yok |
| Fatura hataları | YES (Trendyol + Paraşüt) | Kiracılar arası fatura testi yok; faturadan sonra iptal |
| Etiket hataları | NO | Özellik yok (S2/S6) |
| Hakediş hesapları | NO | Özellik yok (S1) |
| Kiracı izolasyonu | YES (dağınık) | Mimari test yok (her `tenant_id`'li model trait kullanıyor mu?), rota taraması yok, fatura için sıfır |
| Para birimi yuvarlama | PARTIAL | Fatura satır/toplam KDV yuvarlaması |
| Ürün/kategori/öznitelik eşleme | YES | |
| Varyant eşleme | YES | (Gerçek çok varyantlı ürün olmadığı için sınırlı) |
| Webhook replay/imza | YES | Saatlik hash penceresi sonrası replay |
| Mutabakat sürüklenme/onarım | YES | Sipariş mutabakatı yok; **alınmamış satışı ezen onarım** test edilmiyor |

## En yüksek değerli 20 eksik test

| # | Test | Neyi doğrular | Neden | Sprint |
|---|---|---|---|---|
| 1 | `a_cancellation_before_creation_is_honoured_when_the_order_arrives` | İptal → oluşturma sırasında stok sonunda değişmemiş | Bugün iptal düşüyor, stok kalıcı eksik | **P0** |
| 2 | `a_return_before_creation_is_not_lost` | Aynısı, iade yolu | | **P0** |
| 3 | `reconciliation_does_not_overwrite_an_unreceived_channel_sale` | Kanal stoğu düşük + bekleyen inbox → ilk turda onarım yok | Fazla satışı büyütme | **P0** |
| 4 | `a_missed_shopify_webhook_is_recovered_by_order_reconciliation` | Kaybolan webhook siparişi 30 dk içinde içeri alınır, stok düşer | Kaçan sipariş | **P0** |
| 5 | `one_broken_connection_does_not_stop_token_refresh_for_others` | Adapter kurulumu istisna → diğer kiracıların token'ı yenilenir | Kiracılar arası etki | **P0** |
| 6 | `a_shopify_webhook_for_another_shop_is_rejected` | Mağaza alan adı ≠ bağlantı → 401 | | **P0** |
| 7 | `polling_stops_cursor_when_max_pages_is_reached` | Kalan sayfalar sonraki turda istenir | Sessiz sipariş kaybı | **P0** |
| 8 | `order_state_never_moves_backwards` | `shipped` sonrası gelen eski `picking` durumu yazılmaz | Otomatik fatura/eylem merkezi | S1 |
| 9 | `settlement_net_equals_gross_minus_commission_minus_cargo_to_the_cent` | Kuruş hassasiyeti | Hakediş | S1 |
| 10 | `settlement_import_is_idempotent_per_external_ref` | Yeniden çekme çift saymaz | | S1 |
| 11 | `profit_uses_cost_at_sale_time_not_current_cost` | Satış anı görüntüsü | | S1 |
| 12 | `invoice_net_lines_and_payment_match_to_the_cent` | 1000 adet × 10,00 TL senaryosu | Fatura ↔ tahsilat | S1 |
| 13 | `another_tenants_invoice_and_pdf_are_not_found` | Fatura rotaları kiracı izolasyonu | | S1 |
| 14 | `label_creation_timeout_does_not_create_a_second_shipment` | Belirsiz sonuçta önce var olanı sorar | Kargo | S2 |
| 15 | `bulk_label_partial_failure_reports_per_order_and_retries_only_failed` | | | S2 |
| 16 | `hb_listing_create_timeout_then_retry_adopts_instead_of_duplicating` | SKU araması olmayan kanalda kopya yok | | S3 |
| 17 | `trendyol_claim_accept_restocks_once_and_reject_does_not` | Talep yaşam döngüsü + replay | | S4 |
| 18 | `invoiced_order_cancelled_later_issues_a_credit_note_once` | | | S6 |
| 19 | `every_tenant_scoped_model_uses_BelongsToTenant` + `every_id_route_returns_404_for_another_tenants_id` | Mimari izolasyon testi | Roller öncesi | S7 |
| 20 | `ebay_rejects_price_push_when_currency_differs` + `fx_conversion_rounds_per_line_and_matches_total` | | | S9 |

(Eşzamanlı `created`+`updated` tek SALE ve 100-siparişlik yük testi S7'de Black Friday hazırlığına eklenir.)

## Aralıklı kırılan test

`PublishListingScreenTest::product_channels_screen_lists_publishable_connections` — statik okumada belirleyici bir sebep yok. En güçlü şüpheli: önce çalışan `DatabaseTruncation` sınıflarının (`ConcurrentTokenRefreshTest`, `Inventory/Concurrent*`, `ConcurrentOrderLockOrderTest`) ikinci PDO bağlantılarını **kapatmaması** ve `ConcurrentTokenRefreshTest`'te `rollBack()`'in `finally` dışında olması; bu test sınıfının ilk yazdığı global `channel_types` satırında kilit beklemesi. Öneri (S12 ya da ilk fırsatta): bu sınıflara `tearDown`'da `DB::disconnect($name)`, `rollBack()`'i `finally`'ye almak; kırıldığında `pg_locks` loglamak.
