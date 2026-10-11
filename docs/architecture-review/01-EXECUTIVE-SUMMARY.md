# 01 · Yönetici özeti — 34Pazar mimari denetimi

**Tarih:** 11 Ekim 2026 · **Kapsam:** depo `main` (`d830383`, canlı `bf0aaa1` + belge), yol haritası `docs/pdf/34Pazar-Yol-Haritasi-Ekim-2026.pdf` (S1–S12).
**Yöntem:** altı alan için salt okunur kod denetimi (alan modeli, sipariş, entegrasyon güvenilirliği, güvenlik/çok kiracılık, finansal doğruluk, testler). En ağır bulgular ayrıca elle doğrulandı (✔ ile işaretli). **Bu turda hiçbir kod, şema ya da canlı sistem değiştirilmedi.**

Sınıflar: **EXISTS** (var ve sağlam) · **PARTIAL** (var ama eksik) · **MISSING** (yok) · **RISKY** (var ama veri/güvenlik riski taşıyor).

---

## 1. Mimari temelde sağlam mı?

**Evet — çekirdek, bu ölçekteki bir SaaS için olağanın üstünde.** Yeniden tasarım gerekmiyor.

Korunması gereken güçlü yanlar:

- **Stok defteri + projeksiyon** (`inventory_movements` + `inventory_levels`): her hareket deterministik anahtarla tekil, bakiye kırpılmadan tutuluyor, `available` üretilmiş kolon, satır kilitleri sıralı tek sorguda. "Stok neden 23'ten 17'ye düştü?" sorusu bugün cevaplanabiliyor.
- **Inbox/outbox** ile webhook → kuyruk → işleme hattı; olay kimliğiyle tekilleştirme, koşullu sahiplenme, takılı mesaj kurtarma.
- **Senkron sürümleri** (istenen / gönderilen / gözlenen) listing × alan başına; devre kesici, hız sınırı, `RetryPolicy`, ölü mektup, mutabakat (stok + fiyat).
- **Kiracı izolasyonu** framework seviyesinde, fail-closed; kuyruk işleri kiracı bağlamını taşıyıp temizliyor.
- **Fiyat tek kaynaktan** (`Listing::effectivePrice()`), kuruş tamsayısıyla hesaplanıyor.
- Mutasyon testi kültürü olan, riskleri adlandıran ~2.085 test.

## 2. En büyük beş mimari risk

| # | Risk | Sınıf | Neden önemli |
|---|---|---|---|
| 1 | **Sipariş olay sırası korunmuyor** — sipariş oluşmadan gelen iptal/iade düşürülüyor (✔), durum geriye gidebiliyor | RISKY | Stok kalıcı eksik kalır; sessiz. Shopify canlıda. |
| 2 | **Kaçan sipariş kurtarılamıyor + mutabakat alınmamış satışı eziyor** (✔) — webhook kanalları yoklanmıyor; stok farkı ilk turda yönüne bakılmadan "onarılıyor" | RISKY | Fazla satışı *büyütebilir*. Black Friday öncesi en tehlikeli boşluk. |
| 3 | **Kanonik sipariş durumu yok** — kanal kelimeleri 4 ayrı, tutarsız listede (pano cirosu `UnPacked`/iade edilmiş siparişleri sayıyor) | RISKY | S1 kâr, S2 toplu işlem, S4 iade, S6 fatura, S11 eylem merkezi aynı soruyu kendi listesiyle soracak. |
| 4 | **Finansal anlık görüntü yok** — satış anındaki maliyet/komisyon/KDV/indirim saklanmıyor; Trendyol/Shopify tutarları indirim öncesi; iade tutarı yok | RISKY | S1'deki kâr raporu yanlış ve geriye dönük değişken olur. |
| 5 | **Gerçek varyant yok** — her ürün tek varyantlı (✔); Shopify'ın 5 varyantlı ürünü 5 ayrı ürün olarak geliyor; seçenek tabloları hiç yazılmıyor | RISKY | S3 (HB/N11 ürün gönderme), S5 (XML), S8 (AI öznitelik) bu modelin üstüne kurulursa giyim/beden kataloğu desteklenemez ve sonradan düzeltmesi en pahalı iş olur. |

Yakın takipte: rol/yetki modeli yok (S7 öncesi şart), Shopify webhook'unun mağazaya bağlanmaması, tek bozuk bağlantının tüm kiracıların token yenilemesini durdurabilmesi (✔), denetim kaydının iş değişikliklerinin çoğunu kapsamaması.

## 3. S1'den önce gerçekten düzeltilmesi gerekenler (P0)

Hepsi **küçük**, toplam tahmini **2–3 iş günü**; ayrıntı `08-ROADMAP-RECOMMENDATIONS.md`.

1. **Sırasız sipariş olayları:** sipariş henüz yoksa iptal/iade mesajı "işlendi" sayılmasın, ertelensin (ya da sipariş sahiplenilsin); durum geriye yazılmasın.
2. **Mutabakatta onarım kapısı:** kanal stoğu *beklenenden düşükse* (alınmamış satış ihtimali) ilk turda onarım açılmasın; bağlantının bekleyen inbox mesajı / yoklama gecikmesi varsa beklensin.
3. **Kaçan sipariş kurtarma (Shopify):** 30 dakikalık sipariş mutabakatı — kanaldan son 24 saat okunur, aynı inbox yolundan yazılır (tekilleştirme zaten var).
4. **Kiracılar arası hata izolasyonu:** `TokenRefresher` ve `PollChannelOrders` bağlantı başına tam `try/catch`; başarısız satır kuyruğun başını tıkamasın.
5. **Shopify webhook'u mağazaya bağlansın:** `X-Shopify-Shop-Domain` bağlantının hesabıyla eşleşmeli.
6. **Inbox yeniden denemesine bekleme + `failed` mesajı yeniden oynatma komutu;** yoklamada `MAX_PAGES` dolunca imleç ilerlemesin.

## 4. Ocak sonrasına güvenle kalabilecekler (P2/P3)

Çok depo (yalnız varsayılan depo aramasını tek yere toplamak S10'da), rezervasyon/gelen stok, paket ürün (bundle), otomasyon motoru (yalnız olay kancaları S4/S7'de), çoklu abone outbox, kur dönüşümünün tam hâli, Postgres RLS, kanal başına içerik geçersiz kılmanın tam PIM'i, Logo/Mikro.

## 5. S1–S12 yol haritası sürdürülebilir mi?

**Evet, tarihler korunarak.** Yeni sprint açılmadı; temel işler doğal oldukları sprinte gömüldü. İki sprint ağırlaşıyor:

- **S1**: P0'lar (≈2–3 gün) + finansal anlık görüntü temeli. Hakediş/kâr raporu **yalnız Trendyol** ile çıkar; Paraşüt'e hakediş aktarımı S2'nin başına kayabilir.
- **S3**: HB/N11 ürün göndermeden **önce** gerçek varyant grubu. Pazarama/ÇS ürün gönderme zaten S10'daydı.

Dış bağımlılıklar (test hesapları) hızın asıl belirleyicisi olmaya devam ediyor.

## 6. Şimdi verilmesi gereken en önemli mimari karar

> **Siparişi finansal ve durum açısından tek doğru kaynak yap:** `orders.state` (kanaldan bağımsız yaşam döngüsü, yalnız adapter eşler, geri gitmez) + `order_lines` üzerinde satış anı görüntüsü (net tutar, indirim, KDV oranı, birim maliyet, komisyon) + `order_refunds`. Hakediş (S1), etiket (S2), iade (S4), fatura (S6), eylem merkezi (S11) ve ileride otomasyon bu kayda bağlanır.

Bunu S1'in ilk günü yapmak ucuz (5 sipariş, 5 kiracı); S6'dan sonra yapmak her ekranı ve raporu yeniden yazmak demek. İkinci sıradaki karar — **gerçek varyant grubu** — S3 başlamadan verilmeli (ADR-003).

---

Belgeler: `02` mevcut mimari · `03` alan modeli · `04` entegrasyon güvenilirliği · `05` güvenlik · `06` finans · `07` test boşlukları · `08` yol haritası önerileri · `09` teknik borç kaydı · `10` uygulama kontrol listesi · `ARCHITECTURE-DECISIONS.md`.
