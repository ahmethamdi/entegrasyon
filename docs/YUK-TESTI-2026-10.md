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
stoğunu kendisi düşer. Sistem siparişin geldiği kanala normalde stok geri yazmaz (yankı
bastırma, `InventoryLevelChangedConsumer`) — 3. turdan beri yalnız son 30 dakikada o
varyanta başka kaynaktan hareket yoksa; bu yüzden "kanal sonunda doğru mu" ancak böyle
ölçülür. Fiyat da durumludur (`--price-variants`, 3. tur): patlama dakikasında N varyantın
fiyatı siparişlerin arasına serpilerek değişir, sonda kanal fiyatı yerel fiyatla karşılaştırılır.

## Çalıştırma

```bash
docker compose exec -d app php artisan horizon --environment=production
docker compose exec -d app php artisan outbox:relay
docker compose exec app php tests/Load/black-friday.php \
    --minutes=5 --per-minute=100 --burst-per-minute=300 --variants=2000 --channels=5 --latency-ms=150 \
    [--price-variants=2000]
```

Betik yalnız `APP_ENV=local`'da çalışır, kendi kiracısını kurup sonunda siler, sonucu
`storage/app/loadtest/` altına JSON yazar. Adapter `autoload-dev` altındadır — üretim
imajında yoktur.

🪤 **Adapter değişince Horizon'u yeniden başlat** (`horizon:terminate`): işçiler eski sınıfı
bellekte tutar. 🪤 Yerel Horizon yerel demo kiracısının bekleyen işlerini de çalıştırır
(23 Ekim'de hepsi istek atılmadan yerel ön koşulda düştü — Shopify konumu yok, Trendyol
satıcı kimliği yok — ama demo bağlantısı gerçek adres taşısaydı istek giderdi).

## Sonuç

| Ölçüm | 1. tur (düzeltme öncesi) | 2. tur (`6d3419e` sonrası) | 3. tur (yankı penceresi) | 3. tur + 2.000 fiyat |
|---|---|---|---|---|
| Webhook yanıtı p50 / p95 | 33 / 46 ms | 38 / 45 ms | — | 26 / 43 ms |
| **Uçtan uca: sipariş → stok tüm kanallarda** p50 | 5,3 sn | 5,1 sn | 5,0 sn | 4,5 sn |
| p95 / p99 / en kötü | **42,5 / 50,2 / 57,2 sn** | **7,5 / 8,1 / 8,6 sn** | 7,4 / 8,3 / 8,9 sn | 6,9 / 7,7 / 8,5 sn |
| Yük bitince kuyruğun erimesi | 123,5 sn | 7,1 sn | — | 10,1 sn |
| `inventory:high` tepe derinlik | 648 iş | 321 iş | — | 360 iş |
| **Postgres deadlock** | **159** | **0** | 0 | 0 |
| Başarısız deneme / ölü operasyon | **2.038 / 110** | 0 / 0 | 0 / 0 | 0 / 0 |
| Kanala giden stok kalemi / istek | 11.267 / 817 | 5.427 / 501 | 6.676 / 497 | 6.715 / 512 |
| Açılış stoğunun ilk gönderimi (10.000 listing) | 190 sn | 22 sn | — | 21 sn |
| Sipariş yazılan / düşülen adet | 700 / 2.130 (tam) | 700 / 2.053 (tam) | 700 (tam) | 700 / 2.128 (tam) |
| Ledger ↔ projeksiyon | uyumlu | uyumlu | uyumlu | uyumlu |
| **Yük bitince kanalda yanlış stok** | 6 satır (+1/+2) | **7 satır (+1/+2)** | **0** | **0** |
| Fiyat: değişim → tüm kanallarda p50 / p95 / en kötü | | | | 3,8 / 5,8 / 6,7 sn |
| Fiyat: sonda kanalda yanlış / hiç ulaşmayan | | | | 0 / 0 (10.000 listing) |

(3. tur fiyatsız koşu yalnız özet alanlarıyla kaydedildi; "—" ölçülmedi değil, rapora alınmadı.)

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

## Bulunan ve düzeltilen (3. tur): yankı bastırma yarışı

2. turda kampanya ürünlerinde kanalda 1–2 adet fazla kalıyordu (700 siparişte 7 satır).
B kanalındaki satışın paketi A'ya, A'nın kendi siparişi bizde işlenmeden ÖNCE hazırlanıp
pazaryerinin kendi düşüşünden SONRA varınca o düşüşü eziyordu; A'nın siparişinden doğan
olay A'ya bastırıldığı için değer düzelmiyordu.

Düzeltme (kullanıcı onayı, 23 Ekim): `InventoryLevelChangedConsumer` kaynak kanalı yalnız
varyanta son `ORIGIN_ECHO_WINDOW_MINUTES` (varsayılan 30, sıcak mutabakat penceresiyle
aynı) içinde **kaynak dışı** hareket yoksa atlar. Başka kanalın satışı, elle düzeltme,
içe aktarma — hepsi kaynağa da gönderim doğurur, o zaman kaynak da hedef olur. Mutlak değer
gittiği için tekrar zararsız; paket birleştirme ek isteği emer: kalem +%24, **istek +%2**.
Olay başına tek indeksli sorgu (`movements_variant_idx`). `0` eski davranıştır.

## 4. tur (24 Ekim, S9): kur değişimi patlama dakikasında

`--price-variants=500 --fx-channels=2`: 5 kanalın 2'sinde TL→USD kur çevirisi açık
(kur 40,0000). Patlama dakikasının ilk siparişiyle USD kuru %1 oynar (40,4000) →
çapa taşınır, iki kanalın **bütün** ilanları (4.000) yeniden fiyatlanır; aynı dakikada
300 sipariş ve 500 varyantın fiyat değişimi de akar. Sahte kanal 150 ms, 5 istek/sn.

| Ölçülen | Sonuç |
|---|---|
| Kur tetiği (`FetchExchangeRates::ingest`, 4.000 resync talebi) | 5,3 sn |
| Yeni USD fiyatı tüm çevrili ilanlarda (p50 / p95 / en kötü) | 14,6 / 19,4 / 21,4 sn |
| Sonda yanlış fiyat (çevrili 3.000 ilan + değişen 500 varyant × 5) | 0 / 0 |
| Sipariş → stok tüm kanallarda p95 | 7,0 sn (3. turda 7,4) |
| Deadlock / başarısız deneme / ölü işlem | 0 / 0 / 0 |
| `price:high` tepe derinliği | 4.298 |
| Yük bitince kuyruğun boşalması | 10 sn |

Stok hattı kur dalgasından etkilenmedi (ayrı kuyruk, ayrı kilit). Not: Horizon'un
`price:high` için TAHMİNİ bekleme süresi tepe anında 324 sn gösterdi (derinlik ×
ortalama süre / işçi); ölçülen gerçek gecikme en kötü 21 sn. `ops:watch` kuyruk alarmı bu
tahmine bakar — gerçek bir kur dalgasında `price:high` için bir kez yanlış alarm
verebilir.

## 5. tur — set / paket ürün (`--bundles=10`, 24 Ekim 2026)

10 set, her biri 2 × A + 1 × B kampanya ürününden; satırların ~%20'si set. Aynı
bileşenler hem tek başına hem sette satılır. Pazaryeri set satışında yalnız SET ilanını
düşürür — bileşen ilanları bizim gönderimimizle düzelmeli. Son durumda set ilanı
türetilmiş stokla (`SellableStock`) karşılaştırılır.

| Ölçüm | 1. koşu | Sıra düzeltmesi sonrası |
|---|---|---|
| Sipariş (set satırı) | 700 (434 set) | 700 (415 set) |
| Düşülmesi beklenen / düşülen bileşen adedi | 2.992 / 2.992 | 2.982 / 2.982 |
| Defter ↔ projeksiyon uyumsuz | 0 | 0 |
| Sonda kanalda yanlış stok / yanlış set ilanı | 0 / 0 | 0 / 0 |
| Sipariş → stok tüm kanallarda p50 / p95 / en kötü | 4,9 / 7,3 / 8,8 sn | 4,7 / 7,2 / 8,5 sn |
| **Postgres deadlock** | **2** | **0** |
| Başarısız deneme / ölü işlem | 0 / 0 | 0 / 0 |
| Yük bitince kuyruğun boşalması | 10,1 sn | 11,1 sn |

İlk koşudaki 2 deadlock: aynı setin ilanlarına iki ayrı bileşenin olayı aynı anda operasyon
açıyor, ilanlar sırasız okunduğu için durum satırları ters sırada kilitleniyordu. Set ilanları
artık kimlik sırasıyla işlenir (`InventoryLevelChangedConsumer::bundleListings`); iş
`attempts: 3` ile kurtarıldığı için ilk koşuda da kayıp yoktu.

## Kalan riskler

1. **Mutabakatın onarma süresi ölçülmedi** (sahte kanal `fetchInventory` boş döner). Yankı
   penceresinden sonra kalan sürüklenme artık yalnız mutabakata kalır.
2. **Fiyat kilidi yük altında sınandı (3. tur):** 2.000 varyant × 5 kanal fiyat değişimi
   300 siparişlik dakikayla aynı anda — deadlock 0, yanlış fiyat 0.
3. **Tek makine.** Yerel Docker ve canlı sunucu farklıdır; rakamlar karşılaştırma içindir.
   Asıl darboğaz kanal tarafıdır (hız sınırı × gecikme), o da sahte kanalda ayarlanabilir
   (`--latency-ms`).
4. **Uçtan uca ölçüm yaklaşıktır:** "bu siparişin düşüşünü içeren değer", gönderilen
   değerin o siparişe kadar satılan toplamı yansıtıp yansıtmadığıyla ölçülür; sonra
   gönderilip önce işlenen sipariş aynı koşulu sağlayabilir (yanılgı yalnız aşağı yönlü).
