# Devir Notu — 15 Ağustos 2026

Yeni sohbete bu dosyayı ve `CLAUDE.md`'yi okutarak başla.

## Bu sohbette ne yapıldı

Dört commit, hepsi `main` dalında, çalışma ağacı temiz. **145 test yeşil**
(547 assertion), Pint temiz, `--order-by=random` ile de yeşil.

| Commit | İş |
|---|---|
| `5362a08` | Stok çekirdeği: `ApplyMovement` + `LockInventoryRows`, P0 testleri T1/T2/T11/T12 |
| `f7d9f77` | Faz 1.5: outbox relay, fan-out tüketicisi, sürüm kapısı (T3/T7/T8) |
| `58b6f77` | Adapter mimarisi: 7 yetenek arayüzü + `AdapterRegistry` |
| `f83e71c` | Faz 1.6: sipariş alımı, iade, iptal (T9) |
| `90f9add` | Gelen hat: webhook → inbox → `OrderEventRouter` |

**Uzak depo yok** — `git remote` boş, push edilmedi. İstenirse kurulmalı.

## Mimari kaynak — ÖNCE BUNU YAP

**Doküman esastır.** Kod ile doküman çeliştiğinde doküman kazanır. Yeni
mimari turu yapılmıyor; Kafka, mikroservis, CQRS, event sourcing, Kubernetes
önerilmez.

PDF kalıcı: `~/Desktop/Entegrasyon-Mimari-v2.2.pdf`

Metin çıktısı her sohbette yeniden üretilmeli (scratchpad oturuma özeldir):

```bash
pdftotext -layout ~/Desktop/Entegrasyon-Mimari-v2.2.pdf /tmp/doc.txt
```

Sıradaki adımı **tahmin etme, dokümandan oku**:

| Ne arıyorsan | Nerede |
|---|---|
| Sınıf yazım sırası (14 sınıf) | §19 · "İlk yazılacak on dört sınıf" |
| P0 kararları ve bedelleri | §17 · P0 / P1 / P2 |
| Test matrisi T1–T16 | §18 · Test Acceptance Criteria |
| Stok transaction modeli | §5 |
| Outbox / inbox | §6 |
| Adapter mimarisi | §7 |
| Sync operation + sürüm kapısı | §8 |
| Mutabakat | §10 |

## Ortam

```bash
docker compose up -d
docker compose exec app php artisan test      # 145 yeşil olmalı
docker compose exec app vendor/bin/pint       # kod stili
```

Composer/artisan komutları **konteyner içinde** çalışır (yerel PHP 8.4,
container 8.3; `composer.json` içinde platform kilidi var).

Testler gerçek PostgreSQL'de koşar (`entegrasyon_test`), SQLite'ta değil.

## Sıradaki adım — giden yol

Zincirin **içe yönü tam**: webhook gelir → sipariş kaydedilir → stok düşer →
outbox olayı yazılır → fan-out kanal başına `sync_operation` açar.

**Eksik olan dışa yön**: operasyonlar `pending` durumda bekliyor, kimse onları
kanala göndermiyor.

Yazılacaklar (doküman §8, §12):

1. `InventoryBatchBuilder` — **gruplama** yapar, fan-out YAPMAZ. Aynı
   bağlantıya ait bekleyen operasyonları adapter'ın `maxInventoryBatchSize()`
   sınırına göre tek yüke birleştirir. Operasyon sayısı değişmez.
2. `PushInventory` işi — yalnızca orkestrasyon. Erken çıkış: operasyon
   `superseded` olmuşsa gönderme.
3. `SyncResultRecorder` — attempt + sync state + hata yazımı. **Adapter durum
   yazmaz**, sonuç nesnesi döner; yazan burasıdır.
4. Gerçek `WooCommerceAdapter` + `ChannelHttpClient` (istek yürütme +
   `api_calls` yazımı + maskeleme).

**P0 testi T4** (§18): üç kanalda listelenen bir varyantın stok değişiminde
biri 429 alır → `retrying`, diğer ikisi `completed` ve kendi
`listing_sync_states` satırlarını ilerletir. Bir kanalın hatası diğerlerini
kirletmez.

Doküman §18 testlerin **önce** yazılmasını şart koşuyor.

## Bu projede işe yarayan çalışma biçimi

1. **Testi önce yaz, kırmızı olduğunu gör**, sonra implementasyonu yaz.
2. **Mutasyonla sına**: kodu kasten boz, testin kırmızıya döndüğünü doğrula,
   geri al. Bu turda üç gerçek boşluk bu yolla bulundu — testler yeşildi ama
   invariantı korumuyorlardı.
3. Stok yazan her testin sonunda `assertLedgerMatchesProjection()` çağır.

## Tekrar tekrar ısıran tuzaklar

Üçü de bu projede gerçekten yaşandı; ayrıntı `CLAUDE.md`'de ve memory'de.

- **Zaman damgaları saniye hassasiyetli** (`datetime_precision = 0`) ve
  PostgreSQL'de `now()` transaction başlangıcında donar. Transaction içi
  tarama sorgularında `clock_timestamp()` kullan. "Bu satır yeni mi" sorusunu
  **asla zaman damgasıyla cevaplama** — `insertOrIgnore` sonrası kendi
  ürettiğin uuid'in geri gelip gelmediğine bak. Bu hata iki kez tekrarlandı.
- **Açılış stoğu ledger üzerinden girer.** Testte
  `InventoryLevel::create(['on_hand' => 5])` yazmak `on_hand = Σ delta`
  eşitliğini daha başta bozar; seed `IMPORT` hareketiyle yapılır.
- **Eşzamanlılık testi `RefreshDatabase` ile yazılamaz** (tek transaction
  içinde kilit çekişmesi oluşmaz, test yanlış yeşile döner).
  `DatabaseTruncation` + ayrı PDO bağlantısı gerekir; `DatabaseTruncation`
  kendi `setUp`'ında boşalttığı için `tearDown`'da
  `truncateDatabaseTables()` çağrılmalı, yoksa artık sonraki testlere sızar.

## Bilinen açık uç

Bir `--order-by=random` turunda tek bir test düştü; 20+ turda tekrar
üretilemedi ve hangi test olduğu yakalanamadı. Tekrar görülürse seed ile
kaydedilmeli.

## Ekran durumu

Şu an görülebilen tek sayfa `http://localhost:8080/` — ilk turdan kalma
iskelet Dashboard. Yazılan işlerin hiçbiri ekrana bağlı değil; doküman
ekranları bilinçli olarak sonraya bırakıyor. İlk gerçek görsel çıktı §19'daki
dikey dilim: "Woo'da sipariş → panelde stok düşer → 30 sn içinde Woo'ya geri
yazılır, tüm zincir panelde görünür."

Vite build alınmadıysa: `npm run build` (yerelde, container'da Node yok).
