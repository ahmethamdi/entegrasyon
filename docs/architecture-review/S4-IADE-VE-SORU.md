# S4 · İade/talep yönetimi ve müşteri soruları — uygulama ve canlı doğrulama raporu (13 Ekim 2026)

**Sonuç: S4 = PARTIAL — IMPLEMENTED, LIVE VERIFICATION PENDING.**
Kod, testler ve mutasyonlar tamam. Yol haritasının kabul ölçütü (gerçek bir iade panelden onaylanıp stok dönsün; gerçek bir soru panelden cevaplansın) KARŞILANMADI: canlı Trendyol hesabında iade talebi yok ve cevaplanabilir soru yok. Test için gerçek müşteri işlemi yapılmadı. HB kimlik bilgisi yok.

Karar kaydı: ADR-011 (`ARCHITECTURE-DECISIONS.md`). Teknik borç: TD-18 güncellendi, TD-43…TD-46 eklendi.

---

## 1. S4 öncesi denetim

| Alan | Durum | Not |
|---|---|---|
| İade para kaydı (`order_refunds`, `RecordOrderRefunds`) | EXISTS | S1 BEKLENEN iade; S4 dokunmadı |
| İade stok hareketi (`ApplyOrderReturn`, defter) | EXISTS | olay çıpası `order_events (order_id,type,external_ref)` |
| Stok iadesi idempotensi | RISKY | anahtar OLAY kimliğine bağlı (B'); S4 yolunda ekonomik kimliğe bağlandı |
| Kanonik sipariş durumu (`syncFromLines`) | EXISTS | kalem sayaçlarından |
| Trendyol talep (claims) kodu | MISSING | iade yalnız paket `Returned` durumundan, paketin TAMAMI |
| HB talep kodu | PARTIAL | yalnız `Accepted` okunur ve stoğa döner; karar yok |
| N11 iade | PARTIAL | `ClaimReturnList APPROVED` (S4 kapsamı dışında bırakıldı) |
| Yoklama/uzlaştırma altyapısı | EXISTS | finans yoklaması kalıbı (pencere + imleç) |
| Ham kanal durumu/sebebi saklama | MISSING | eklendi |
| Onay/ret yöntemleri | MISSING | eklendi (Trendyol) |
| Soru modeli / adapter / cevap / sayaç | MISSING | eklendi (Trendyol) |
| Testler | PARTIAL | HB/N11 iade testleri vardı, Trendyol iade testi yoktu |

## 2. Trendyol API sözleşmesi (belge v2.0, 13 Eki okundu)

- **Liste:** `GET /integration/order/sellers/{id}/claims` — `startDate/endDate` talebin `lastModifiedDate`'ine göre, `page/size`, `claimIds` (verilirse öteki parametreler yok sayılır), `claimItemStatus`.
- **Kimlikler:** `claimId` talep; `claimItem.id` kalem (onay/red listesi bununla); `orderLineItemId` BİRİM kimliği → bir claimItem = 1 birim; `orderLine.id` sipariş satırı; `orderOutboundPackageId` GİDEN paket (bizde `orders.external_id`); `orderShipmentPackageId` İADE paketi.
- **Durumlar:** Created, WaitingInAction, InAnalysis, WaitingFraudCheck, Accepted, Rejected, Unresolved, Cancelled. Onay ucu "deponuza dönen iadeler" içindir ve yalnız WaitingInAction'da çalışır; onaylanan kalem sahtecilik kontrolüne (WaitingFraudCheck) düşebilir. WaitingInAction 48 saat beklerse kanal otomatik kabul eder (`autoAccepted`).
- **Sebep:** `customerClaimItemReason {name, code, externalReasonId}`, müşteri notu `customerNote`.
- **Onay:** `PUT …/claims/{claimId}/items/approve` gövde `{claimLineItemIdList, params}` → 200.
- **Red:** `POST …/claims/{claimId}/issue?claimIssueReasonId&claimItemIdList&description` (+ ek dosya form-data). Ek GEREKTİRMEYEN sebepler: 1651, 451, 2101 (2101 reddi değişim ONAYINA çevirir → kullanılmadı). 1651 ilk 24 saatte seçilemez.
- **Denetim kaydı:** `GET …/claims/items/{claimItemId}/audit` → `[{previousStatus,newStatus,date,…}]`.
- **Sorular:** `GET /integration/qna/sellers/{id}/questions/filter` (tarihsiz = son 1 hafta, en çok 2 hafta, `size` ≤ 50, sayfa 0'dan); `GET …/questions/{id}`; `POST …/questions/{id}/answers {text}` → `{answerId}`. Cevap 10–2000 karakter. Yalnız `WAITING_FOR_ANSWER` cevaplanır; diğer durumlar WAITING_FOR_APPROVE, ANSWERED, REPORTED, REJECTED, UNANSWERED (süre doldu, kapandı). Cevap yasak kelime denetiminden geçer; reddedilirse soru WAITING_FOR_ANSWER'a döner (`rejectedAnswer`). "Question already answered" hatası tekrar cevabı engeller.
- **Belge içi çelişki:** REJECTED bir tabloda "satıcının cevabı reddedildi", diğerinde "soru reddedildi ve kapandı" — ikisinde de cevaplanamaz; kanonik `rejected`.
- **Canlı gözlem:** canlı soru gövdesinin anahtar kümesi belgeyle uyumlu; UNANSWERED sorularda `reportReason/reportedDate` dolu (satıcı panelden bildirmiş).

## 3. HB API sözleşmesi (portal, gerçek Chrome ile 13 Eki)

- **Talep listesi:** `GET /claims/merchantId/{id}` (beginDate/endDate talebin AÇILIŞINA göre, offset/limit) ve `/status/{status}`. `model.Claim`: number, quantity, sku (HB kodu), status, orderNumber, claimType, explanation, finalizedWith… — **sipariş kalemi kimliği YOK**.
- **Durumlar:** NewRequest, AwaitingAction, AwaitingPreApproval, InDispute, Accepted, Refunded, Rejected, Cancelled.
- **Karar:** `POST /claims/number/{n}/accept {FinalizedWith, …}`, `/reject {ClaimRejectionReason, MerchantStatement, …}`, `/preapprovalconfirm`. **Belge çelişkili:** gövdesiz onay bir bölümde "değişim", başka bölümde "iade" olarak onaylanır; "ürün müşteride kalsın, para iadesi" seçeneği var → kabul fiziksel varışı kanıtlamaz. → **HB kararı BLOCKED.**
- **Sorular ("Satıcıya Sor", sürüm Draft):** `/api/v1.0/issues`, `/issues/{number}/answer`… Statü kodları belgede çelişkili (hem Rejected hem AutoClosed = 4), taban adresi ve cevap gövdesi rehberde yok → **HB soruları BLOCKED.**

## 4. Kanonik iade modeli

`return_requests`: tenant, connection, channel_type_code, external_claim_id (tekil/bağlantı), external_order_number, external_package_id, order_id, match_status (matched/partial/ambiguous/order_missing), status (kanonik), channel_status (ham özet), claim_type, claimed_at, remote_modified_at.
`return_request_lines`: external_item_id (EKONOMİK KİMLİK, tekil/bağlantı), external_order_line_id, sku, barcode, product_name, quantity, order_line_id, variant_id, match_status (matched/order_missing/line_missing/ambiguous), status, channel_status, reason_code/text, customer_note, accept_detail, receipt_proven_at, restock_status (not_applicable/pending/restored/blocked) + restock_reason_code + restock_order_event_id, karar alanları (decision, decision_status, decision_reason_code, issue_reason_id, description, requested_at, decided_at, decided_by).
Eşleşme TAHMİN ETMEZ: sipariş paket kimliğiyle (yoksa numara); kalem kanal satır kimliğiyle, yoksa SKU/barkodla YALNIZ TEK adayda.

## 5. Durum eşlemesi

| Kanonik | Trendyol | HB |
|---|---|---|
| requested | Created | NewRequest, AwaitingPreApproval |
| awaiting_action | WaitingInAction | AwaitingAction |
| under_review | InAnalysis, WaitingFraudCheck | — |
| accepted | Accepted | Accepted, Refunded |
| rejected | Rejected | Rejected |
| disputed | Unresolved | InDispute |
| cancelled | Cancelled | Cancelled |
| unknown | diğer her şey | diğer her şey |

Sorular: WAITING_FOR_ANSWER→awaiting_answer, WAITING_FOR_APPROVE→answer_pending_approval, ANSWERED→answered, REPORTED→reported, REJECTED→rejected, UNANSWERED→closed, diğer→unknown.

## 6–9. Ekonomik kimlik, stok tetiği, kısmi iade, defter

- Olay çıpası `return-item:{bağlantı}:{claimItem.id}` → `ApplyOrderReturn` → `inventory_movements` (RETURN). Bakiye doğrudan güncellenmez.
- Tetik: accepted + kanal stoklar (Trendyol) + varış kanıtı + kesin eşleşme + satır stoğu düşülmüş + kalan adet > 0. Önce olay var mı bakılır (varsa yeni hareket yok, `RETURN_STOCK_ALREADY_RESTORED`).
- Kısmi: 3 adetlik satırdan 1 claimItem → +1; ikinci meşru iade → toplam +2; tekrarlanan gözlem → değişmez. Satırı aşan kalem → `blocked` + `RETURN_QUANTITY_CONFLICT` (DB CHECK + `ApplyOrderReturn` kırpması + ön kontrol).
- Trendyol paket `Returned` olayı stoğa dokunmaz (bayrak `returns_restocked_via_claims`, router erken çıkar).

## 10. S1 ile ayrım

S4 hiçbir talepten finans kaydı üretmez. Paket `Returned` yolundaki BEKLENEN iade kaydı (S1) aynen sürer. S1 durumu değişmedi: **PARTIAL — LIVE FINANCIAL SEMANTICS PENDING**.

## 11–12. Onay/red ve yetki

`RequestReturnDecision` (panel; ağ yok): bağlantı aktif · adapter `SupportsReturnDecisions` · izin · red sebebi listede + açıklama 1–500 · yalnız `awaiting_action` kalemler · koşullu talep (`decision_requested_at`). `SubmitReturnDecision` (kuyruk): izin TAZE → bağlantı → devre kesici → hız sınırı → kanaldan güncel talep → hâlâ awaiting olanlara `pending→remote_state_unknown` → istek (onay: tek istek; red: kalem başına). Yetki: iki ayrı kolon `return_decision_enabled`, `question_answer_enabled`, varsayılan kapalı, HB/N11 kilitli (ADR-011).

## 13–15. Soru modeli, yaşam döngüsü, sayaç

`channel_questions` (müşteri adı/kimliği yok). Cevap: `pending` → (`submitted` | `failed` | `remote_state_unknown`); kanal reddi → `rejected` (soru yeniden cevap bekler). "Cevaplandı" yalnız kanal ANSWERED dediğinde. Sayaç: `awaiting_answer` ve cevap yolda/iletilmiş değil; belirsiz sayılır. Pano: "müşteri sorusu cevap bekliyor" ve "iade talebi kararını bekliyor".

## 16. Yoklama / uzlaştırma

`returns:sync` (15 dk) ve `questions:sync` (10 dk), `onOneServer`, `withoutOverlapping`. Pencere + sayfa; imleç yalnız pencere tüm sayfalarıyla bitince ilerler; hız kovası/sayfa sınırı/HTTP hatası turu yarıda keser ve imleci ilerletmez. Trendyol talep penceresi 14 gün, 1 gün örtüşme; HB her turda son 30 gün (süzgeç açılış tarihine bakar). Uzlaştırma: eşleşmemiş talepler yeniden eşlenir, varış kanıtı denetim kaydından aranır, kabul edilmiş kalemlerde stok denenir; açık sorular tek tek tazelenir. Uzlaştırma dışarı YAZMAZ.

## 17. Sebep kodları

`ReturnReason`: RETURN_MATCH_REQUIRED, RETURN_ORDER_NOT_FOUND, RETURN_STATE_UNKNOWN, RETURN_ACTION_NOT_ALLOWED, RETURN_ACTION_DISABLED, RETURN_ACTION_UNSUPPORTED, RETURN_ALREADY_PROCESSED, RETURN_STATE_CHANGED, RETURN_ACTION_REJECTED, RETURN_REMOTE_STATE_UNKNOWN, RETURN_REASON_REQUIRED, RETURN_QUANTITY_CONFLICT, RETURN_STOCK_ALREADY_RESTORED, RETURN_RECEIPT_UNPROVEN, RETURN_STOCK_NOT_DEDUCTED, RETURN_RESTOCK_UNSUPPORTED, RETURN_CHANNEL_UNAVAILABLE, RETURN_ACTION_FAILED.
`QuestionReason`: QUESTION_ACTION_DISABLED, QUESTION_ACTION_UNSUPPORTED, QUESTION_ALREADY_ANSWERED, QUESTION_NOT_ANSWERABLE, QUESTION_REMOTE_STATE_UNKNOWN, QUESTION_ANSWER_REJECTED, QUESTION_ANSWER_INVALID, QUESTION_ANSWER_FAILED, QUESTION_CHANNEL_UNAVAILABLE.

## 18. Kiracı yalıtımı

Controller'lar kimliği string alır ve kiracı scope'u altında `findOrFail` yapar (rota model bağlaması kiracı bağlamından önce çalışıyordu — testte yakalandı, 500 dönüyordu). Başka kiracının ve olmayan kimliğin cevabı aynı 404; sahte onay/red/cevap kanala hiçbir şey göndermez. Ekranlar ve sayaç kiracıya göre.

## 19. Migration

`2026_10_16_000100_create_returns_and_questions` — 3 tablo + `channel_connections` iki boolean kolon (default false). Veri dönüşümü YOK.

## 21–23. Testler ve mutasyon

Yeni: `tests/Feature/Returns/` — ReturnIngestTest (15), ReturnDecisionTest (11), QuestionTest (12), CustomerServicePanelTest (6); TrendyolOrderSliceTest'e paket iadesi testi. Tam takım **2417 yeşil**, Pint temiz, `npm run build` tamam.
Mutasyon 19/19 öldü: ekonomik kimlik, iade adedi, fazla iade koruması, varış kanıtı şartı, yalnız accepted stoklar, belirsiz eşleşme, kiracı sınırı (iade, soru), karar kapısı (istek, iş), bayat ekran, belirsiz karar kör tekrarı, cevap kapısı (istek, iş), çift cevap (belirsiz tekrar, kanalda cevaplı), cevapsız sayacı, Trendyol paket yolu, sayfa sınırında imleç. İlk turda 4'ü yaşadı (test boşluğu) → testler eklendi, yeniden ölçüldü.

## 24–26. Canlı kanıt

- **Trendyol okuma (13 Eki 07:09 UTC, yalnız GET, ham `/root/ty-s4-probe-20261013.json` 600):** claims tarihsiz ve 90 gün → 200, `totalElements=0`. Sorular: varsayılan pencere 0; 6×14 günlük pencerede 4 soru (1–15 Eyl): UNANSWERED×2 (satıcı panelden bildirmiş), ANSWERED×1, REJECTED×1. `WAITING_FOR_ANSWER` YOK.
- **Trendyol yazma:** YAPILMADI — cevaplanabilir soru ve karar bekleyen iade yok; gerçek müşteri işlemi test için yapılmaz.
- **HB:** kimlik yok; canlı kanıt YOK.

## 27. IMPLEMENTED — NOT LIVE VERIFIED

Trendyol iade okuma (uç 200 döndü ama veri yok: alan eşlemesi canlıda görülmedi) · Trendyol onay · Trendyol red (gövde biçimi dahil) · varış kanıtı (denetim kaydı ucu) · claimItem = tek birim varsayımı · `orderLine.id` = bizim `external_line_id` (v2 `lineId`) varsayımı · ~~Trendyol soru okuma~~ (13 Eki canlıda doğrulandı, §Canlıya alma) · Trendyol cevap · cevap reddinin algılanması · HB talep okuma.

## 28. Engeller

Canlı hesapta iade/soru yok · HB kimlik bilgisi yok · HB karar belgesi çelişkili · HB soru API'si Draft ve çelişkili · fotoğraf/belge gerektiren Trendyol red sebepleri.

## 29. Yeni teknik borç

TD-43 (HB eski Accepted stok yolu, varış kanıtsız) · TD-44 (Trendyol red: ek gerektiren sebepler + gövde doğrulaması) · TD-45 (answerId gelmezse cevap reddi algılanmaz) · TD-46 (belirsiz iade kalemi için elle eşleştirme ekranı yok).

## 30–33. Sprint durumları

- S1: PARTIAL — LIVE FINANCIAL SEMANTICS PENDING (değişmedi)
- S2: IMPLEMENTATION COMPLETE — LIVE VERIFICATION PENDING (ortak etiket yetkisi)
- S3: PARTIAL — IMPLEMENTED, LIVE VERIFICATION BLOCKED (HB/N11 hesap yok)
- **S4: PARTIAL — IMPLEMENTED, LIVE VERIFICATION PENDING**

| Yetenek | Durum |
|---|---|
| Trendyol iade uç/okuma taşıması | LIVE VERIFIED — boş gerçek sonuçla (13 Eki, üretim kodu, 200, 0 talep) |
| Trendyol gerçek talep yükü ayrıştırma | LIVE VERIFICATION PENDING (canlıda talep yok) |
| Trendyol iade onay | IMPLEMENTED — LIVE PENDING |
| Trendyol iade red | IMPLEMENTED — LIVE PENDING |
| Trendyol stok iadesi | IMPLEMENTED — LIVE PENDING |
| Trendyol soru okuma | **LIVE VERIFIED** (13 Eki, üretim kodu: 4 gerçek soru içe alındı, iki tur idempotent, zaman/durum ham yanıtla aynı) |
| Trendyol soru cevap | IMPLEMENTED — LIVE PENDING |
| Cevapsız sayacı | LIVE VERIFIED — kanonik durumdan 0 (4 sorunun hiçbiri `WAITING_FOR_ANSWER` değil) |
| HB iade okuma | IMPLEMENTED — NOT LIVE VERIFIED |
| HB iade onay/red | BLOCKED |
| HB soru okuma / cevap | BLOCKED |

## Canlıya alma notu

1 migration (veri dönüşümü yok). Deploy sonrası: `returns:sync` ve `questions:sync` zamanlayıcıda; ilk turda Trendyol talepleri (0) ve son 28 günün soruları içe alınır. İki yeni izin TÜM bağlantılarda kapalı başlar — satıcı Kanallar ekranından açar.

## Canlıya alma (13 Eki 2026 ~08:22 UTC) — `3789115`
- Push: `2733671` (kod) + `3789115` (belge); çalışma ağacındaki ilgisiz değişiklik yoktu. Canlı HEAD `3789115`.
- Yedek `/var/backups/oncesi-s4-34pazar-20261013-0820.dump` sha256 `bfa0b6255b9d066cc5cef4bde4bad7e9c83e359e1905504990c70b2f35936118`. Geçici DB'ye `pg_restore --exit-on-error` tamam; 21 tablonun adet + içerik md5'i alındı (connections 4, products 606, variants 606, listings 606, orders 5, order_lines 5, inventory_movements 611, inventory_levels 606, invoices 0, S1 fin_tx 4, inbox 28, outbox 612, failed_jobs 0), sonra silindi.
- Migration `2026_10_16_000100` batch 17, 57 ms. Yerelde up → rollback (3 tablo + 2 kolon gitti) → up temiz.
- İzinler: 4 bağlantının hepsinde `return_decision_enabled=false`, `question_answer_enabled=false` (Trendyol dahil; test için AÇILMADI). HB/N11 kilidi, kimlik≠yetki, kuyruktaki işin izni yeniden okuması, izin kapatılınca tekrar yazmama: `ReturnDecisionTest`, `QuestionTest`, `CustomerServicePanelTest` — 56/56 yeşil (yerel, 13 Eki).
- **Soru okuma (üretim kodu, yalnız GET):** varsayılan yoklama ilk turda son 28 günü okur → 0 (4 soru 11–15 Eyl). Aynı üretim yolu (`TrendyolAdapter::fetchQuestionPage` + `StoreRemoteQuestion`) tinker ile 6×14 günlük pencerede koşturuldu: 449538774 UNANSWERED→closed, 449544418 UNANSWERED→closed, 449565081 ANSWERED→answered, 450502715 REJECTED→rejected. `asked_at`/`answered_at` ham yanıtla saniyesine kadar aynı. İkinci tur: aynı 4 satır, aynı kimlikler, yalnız `updated_at`/`last_seen_at`. İmleç değiştirilmedi.
- Pano sayacı: 0 (kanonik `awaiting_answer` yok). Kiracı: 5 kiracıdan yalnız sahibi 4 soruyu görür; başka kiracıda yabancı kimlik ve olmayan kimlik ikisi de `null` → aynı 404.
- **İade yoklaması:** zamanlayıcı + elle `returns:sync` → `stored=0 restored=0 failed=0`. Bu yalnız taşıma düzeyini kanıtlar; talep yükü, eşleme, onay/red, varış, stok iadesi KANITLANMADI.
- **Dış yazma:** deploy sonrası `api_calls`'ta GET dışı tek çağrı Etsy OAuth token yenilemesi (S4 öncesinden süren, 403). Trendyol/HB iade onay/red ve soru cevabı = 0. qna/claims çağrıları 24×GET 200.
- **Stok:** inventory_movements 611 → 611, son hareket 8 Eki; canlıda hiç iade tipli hareket yok (IMPORT 599, MANUAL 9, SALE 3) → eski paket `Returned` yolunun yeniden yorumladığı kayıt yok; yolun stoğa dokunmadığı `trendyol_package_return_event_no_longer_restocks_by_itself` ile.
- **Taban farkı** (yedek ↔ +34 dk gözlem): products, variants, listings, orders, order_lines, order_refunds, inventory_movements/levels, invoices, S1 fin_tx, fulfillments, shipping_labels, inbox, outbox, jobs, failed_jobs içerik md5'i AYNI. `channel_connections`: eski sütunlar (settings/updated_at hariç) md5 aynı; settings'te yalnız Trendyol'a `returns_ingested_through`/`questions_ingested_through` eklendi ve `orders_polled_through` olağan yoklamayla ilerledi. Yeni: `channel_questions` 4.
- Sağlık: `/up` 200, 5 konteyner ayakta, Horizon running, failed 0, bekleyen iş 0; zamanlayıcıda `questions:sync` 08:30/08:40/08:50 ve `returns:sync` 08:45 hatasız; `questions.ingest_failed`/devre kesici/izin reddi günlüğü yok. Anomali: Etsy `credentials.refresh_failed` saatlik (pending bağlantı, S4'ten bağımsız).
- HB: bağlantı yok, kilitler yerinde. TD-43 HB etkinleştirme listesine SERT ENGEL olarak yazıldı.
