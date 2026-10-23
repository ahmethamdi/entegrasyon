# Black Friday yük testi — Ekim 2026 (S7)

**Tarih:** 23 Ekim 2026 · **Ortam:** yerel Docker (MacBook, konteynerde 8 çekirdek / 7,8 GB),
Horizon **üretim havuzuyla** (`--environment=production`: critical 6–8 işçi) + `outbox:relay`.
**Canlıya yük bindirilmedi.** Kanallar sahte (`tests/Load/LoadTestChannelAdapter.php`) — ağa çıkmaz.

## Soru

Satıcının en yoğun dakikasında, sipariş düştükten **kaç saniye sonra stok bütün kanallarda
doğru?** Kuyruğun boşalması bunun kanıtı değildir; kanıt, kanalın elindeki değerdir.

## Senaryo

| | |
|---|---|
| Kiracı | 1 |
| Kanal | 5 (aynı ürünler hepsinde listelenmiş) |
| Varyant / listing | 2.000 / 10.000 |
| Sipariş | dakikada 100 · 100 · **300** · 100 · 100 → 5 dakikada 700 sipariş, ~2.100 adet |
| Sepet | 1–3 kalem, kalem başına 1–2 adet; kalemlerin %30'u 20 "kampanya ürününe" düşer |
| Kanal gecikmesi | 150 ms / istek, hız sınırı 5 istek/sn, paket 100 kalem (Trendyol düzeyi) |
| Yol | **gerçek webhook ucu** → inbox → Horizon → stok düşüşü → outbox → fan-out → 5 kanala gönderim |

Sahte kanal **durumludur**: gönderim değeri üzerine yazar; sipariş anında pazaryeri kendi
stoğunu kendisi düşer. Sistem siparişin geldiği kanala stok geri yazmaz (yankı bastırma,
`InventoryLevelChangedConsumer`), bu yüzden "kanal sonunda doğru mu" ancak böyle ölçülür.

## Çalıştırma

```bash
docker compose exec -d app php artisan horizon --environment=production
docker compose exec -d app php artisan outbox:relay
docker compose exec app php tests/Load/black-friday.php \
    --minutes=5 --per-minute=100 --burst-per-minute=300 --variants=2000 --channels=5 --latency-ms=150
```

Betik yalnız `APP_ENV=local`'da çalışır, kendi kiracısını kurup sonunda siler, sonucu
`storage/app/loadtest/` altına JSON yazar. Adapter `autoload-dev` altındadır — üretim
imajında yoktur.

🪤 **Adapter değişince Horizon'u yeniden başlat** (`horizon:terminate`): işçiler eski sınıfı
bellekte tutar. 🪤 Yerel Horizon yerel demo kiracısının bekleyen işlerini de çalıştırır
(23 Ekim'de hepsi istek atılmadan yerel ön koşulda düştü — Shopify konumu yok, Trendyol
satıcı kimliği yok — ama demo bağlantısı gerçek adres taşısaydı istek giderdi).

## Sonuç

| Ölçüm | 1. tur (düzeltme öncesi) | 2. tur (`6d3419e` sonrası) |
|---|---|---|
| Webhook yanıtı p50 / p95 | 33 / 46 ms | 38 / 45 ms |
| **Uçtan uca: sipariş → stok tüm kanallarda** p50 | 5,3 sn | 5,1 sn |
| p95 / p99 / en kötü | **42,5 / 50,2 / 57,2 sn** | **7,5 / 8,1 / 8,6 sn** |
| Yük bitince kuyruğun erimesi | 123,5 sn | 7,1 sn |
| `inventory:high` tepe derinlik | 648 iş | 321 iş |
| Horizon tepe bekleme | 3 sn | 1 sn |
| **Postgres deadlock** | **159** | **0** |
| Başarısız deneme / ölü operasyon | **2.038 / 110** | 0 / 0 |
| Kanala giden kalem (aynı iş) | 11.267 | 5.427 |
| Açılış stoğunun ilk gönderimi (10.000 listing) | 190 sn | 22 sn |
| Sipariş yazılan / düşülen adet | 700 / 2.130 (tam) | 700 / 2.053 (tam) |
| Ledger ↔ projeksiyon | uyumlu | uyumlu |
| Yük bitince kanalda yanlış stok | 6 satır (+1/+2) | **7 satır (+1/+2)** |

(İki turun sipariş içerikleri rastgeledir; adetler bu yüzden farklı.)

## Bulunan ve düzeltilen: aynı bağlantıya eşzamanlı iki gönderici

`InventoryBatchBuilder` bekleyen operasyonları `lockForUpdate()` ile topluyordu ama iş bir
transaction içinde değildi: kilit SELECT bitince düşüyordu. Yoğunlukta aynı bağlantının
iki işi aynı operasyonları toplayıp **ikisi de** gönderdi:

- sonuç kayıtları aynı satırları farklı sırayla güncelledi (tetikleyici paketin başına
  alınır) → deadlock → deneme başarısız → bütçe dolunca operasyon **ölü**;
- eski değerli paket yenisinden sonra kanala vardı → kanalda stok fazla kaldı;
- aynı kalem iki kez gitti (kanal kotası boşa harcandı).

Düzeltme `ConnectionPushLock`: bağlantı × alan başına oturum düzeyinde Postgres advisory
kilidi. Kilidi alamayan iş 1 sn sonra yeniden kuyruğa girer, kota harcamaz, deneme açmaz.
İşçi ölürse bağlantısı kapanır ve kilit kendiliğinden düşer.

## Kalan riskler

1. **Yankı bastırma yarışı — kampanya ürününde kanalda 1–2 adet fazla.** Gönderim paketi,
   o kanalın kendi siparişi işlenmeden ÖNCE hazırlanıp pazaryerinin kendi düşüşünden SONRA
   varırsa düşüşü ezer. Sistem o kanala bu satış için ayrıca gönderim yapmadığından değer,
   aynı ürün bir daha değişene ya da mutabakat (§10, sıcak katman) düzeltene kadar fazla
   kalır. 700 siparişte 7 satır, hepsi çok satan ürünler. Mutabakatın onarma süresi bu
   testte ölçülmedi (sahte kanal `fetchInventory` boş döner). Öneri: son 30 dakikada satılan
   ürünlerde yankı bastırmayı kapatmak (o kanala da gönder) — mimari karar, kullanıcı
   onayıyla.
2. **Fiyat gönderimi aynı desendeydi — kilit eklendi, yük altında ÖLÇÜLMEDİ.**
   `PriceBatchBuilder` da transaction dışında `lockForUpdate` kullanıyordu; `PushPrices`
   aynı `ConnectionPushLock` ile (`SyncDomain::PRICE`) kapatıldı ve birim testi var, ama
   kampanya başlangıcındaki toplu fiyat değişimi bu turda yük olarak koşulmadı.
3. **Tek makine.** Yerel Docker ve canlı sunucu farklıdır; rakamlar karşılaştırma içindir.
   Asıl darboğaz kanal tarafıdır (hız sınırı × gecikme), o da sahte kanalda ayarlanabilir
   (`--latency-ms`).
4. **Uçtan uca ölçüm yaklaşıktır:** "bu siparişin düşüşünü içeren değer", gönderilen
   değerin o siparişe kadar satılan toplamı yansıtıp yansıtmadığıyla ölçülür; sonra
   gönderilip önce işlenen sipariş aynı koşulu sağlayabilir (yanılgı yalnız aşağı yönlü).
