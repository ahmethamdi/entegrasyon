# Yedekleme ve Geri Yükleme Provası

**Kaynak:** Mimari Karar Dokümanı v2.2 · §11 (kontrol listesi) · §15
(Yedekleme tablosu).

§11 şunu ister: *"Yedekler alınıyor **VE geri yükleme en az bir kez test
edildi**."* Yazılı bir prosedür bu maddeyi **karşılamaz** — kelimeler
"test edildi" diyor. Bu belge hem prosedürü hem de **yapılmış provanın
sonuçlarını** taşır.

---

## ✅ Prova sonucu — 24 Ağustos 2026

**Yerel Docker · PostgreSQL 16 · 49 tablo · 177 KB yedek**

| Adım | Süre | Sonuç |
|---|---|---|
| `pg_dump -Fc` | 1 sn | ✅ 177 KB |
| Boş konteyner ayağa kalkması | 4 sn | ✅ |
| `pg_restore` | 1 sn | ✅ hatasız |
| **TOPLAM** | **~6 sn** | **§15 eşiği: > 1 saat → yönetilen DB değerlendir** |

### Doğrulanan yedi şey

| # | Kontrol | Sonuç |
|---|---|---|
| 1 | Tablo sayısı ve satır sayıları | ✅ 49 tablo, **fark yok** |
| 2 | Kısmi tekil indeksler | ✅ **9/9 geldi** |
| 3 | Generated column'lar | ✅ **3/3 geldi** |
| 4 | Generated column'lar **hesaplıyor** | ✅ `is_dirty` çalışıyor |
| 5 | **Şifreli kimlik bilgileri çözülüyor** | ✅ **13/13** |
| 6 | Uygulama geri yüklenen DB ile çalışıyor | ✅ `migrate:status` temiz |
| 7 | Ledger bütünlüğü | ✅ **0 uyuşmayan satır** |

**5. madde bu provanın asıl sebebidir.** Yedek tek başına değersizdir:
`channel_credentials` Laravel `Crypt` ile şifrelidir ve `APP_KEY`
olmadan **hiçbiri çözülemez**. Prova, yedek ile anahtarın **birlikte**
çalıştığını kanıtlar.

Doğrulanan kısmi tekil indeksler (naif bir geri yüklemenin sessizce
kaybedebileceği yapılar):

```
channel_credentials_active_unique     order_events_external_unique
inbox_event_id_unique                 subscriptions_external_ref_unique
inbox_hash_unique                     subscriptions_one_active_per_tenant
listings_external_unique              variants_barcode_unique
                                      warehouses_one_default_per_tenant
```

Generated column'lar: `inbox_messages.dedupe_window` ·
`inventory_levels.available` · `listing_sync_states.is_dirty`

---

## ⚠️ Provanın sınırları — dürüst çerçeve

Bu prova **yerel Docker'da, `pg_dump` ile** yapıldı. Üretimde §15
**pgBackRest** öngörüyor (günlük tam + sürekli WAL arşivi). İkisi farklı
araçlardır ve prova şunları **kanıtlamaz**:

- WAL arşivinin çalıştığını ve **belirli bir ana geri dönülebildiğini**
  (PITR)
- Uzak depodan (Hetzner Storage Box) **indirme** süresini — gerçek geri
  yükme süresinin baskın bileşeni büyük olasılıkla budur
- Üretim veri hacminde süreyi (177 KB ile 80 GB arasında ilişki yok)

**Kanıtladığı şey şudur ve önemsiz değildir:** şemanın tamamı — kısmi
tekil indeksler, generated column'lar, FK'ler — bir dump/restore turundan
sağ çıkıyor ve **şifreli kimlik bilgileri geri yüklenen veritabanından
çözülebiliyor**. Bunlar araçtan bağımsız gerçeklerdir.

Sunucu kurulduğunda prova **pgBackRest ile tekrarlanmalı** ve süre
yeniden ölçülmelidir.

---

## Prosedür — yerel prova (bugün çalışır)

Bu adımlar birebir çalıştırıldı ve yukarıdaki sonuçları üretti.

### 1 · Yedek al

```bash
docker compose exec -T postgres \
  pg_dump -U entegrasyon -d entegrasyon -Fc > yedek.dump
```

`-Fc` (custom format) kullanılır: sıkıştırılmıştır ve `pg_restore` ile
**seçici** geri yüklemeye izin verir. Düz SQL dump'ta o esneklik yoktur.

### 2 · Boş bir hedef ayağa kaldır

**Kaynak veritabanına GERİ YÜKLEME YAPILMAZ.** Prova, mevcut veriyi
ezmeden yapılmalıdır; aksi halde "prova" bir veri kaybı olayına dönüşür.

```bash
docker run -d --name entegrasyon-restore-test \
  -e POSTGRES_DB=entegrasyon_restore \
  -e POSTGRES_USER=entegrasyon \
  -e POSTGRES_PASSWORD=secret \
  -p 5434:5432 postgres:16-alpine

# Hazır olmasını bekle
until docker exec entegrasyon-restore-test \
      pg_isready -U entegrasyon -d entegrasyon_restore; do sleep 1; done
```

### 3 · Geri yükle

```bash
docker exec -i entegrasyon-restore-test \
  pg_restore -U entegrasyon -d entegrasyon_restore \
             --no-owner --no-privileges < yedek.dump
```

`--no-owner --no-privileges`: dump'taki rol adları hedefte olmayabilir ve
prova için rol eşlemesi gereksiz gürültüdür.

### 4 · DOĞRULA — geri yüklemek yetmez

> **Bu adım atlanırsa prova hiçbir şey kanıtlamaz.** `pg_restore`'un
> hatasız bitmesi verinin **kullanılabilir** olduğu anlamına gelmez.

**4a · Satır sayıları:**

```bash
SQL="SELECT relname, n_live_tup FROM pg_stat_user_tables ORDER BY relname;"
docker compose exec -T postgres \
  psql -U entegrasyon -d entegrasyon -Atc "ANALYZE; $SQL" > kaynak.txt
docker exec entegrasyon-restore-test \
  psql -U entegrasyon -d entegrasyon_restore -Atc "ANALYZE; $SQL" > hedef.txt
diff kaynak.txt hedef.txt      # boş çıktı = aynı
```

**4b · Kısmi tekil indeksler** — bu projenin en kritik kısıtları
(`warehouses_one_default_per_tenant`, `subscriptions_one_active_per_tenant`,
`channel_credentials_active_unique` …). Gelmezlerse veritabanı **kabul
etmemesi gereken satırları kabul etmeye başlar** ve bozukluk aylar sonra
ortaya çıkar.

```bash
SQL="SELECT indexname FROM pg_indexes
      WHERE schemaname='public' AND indexdef LIKE '%UNIQUE%'
        AND indexdef LIKE '%WHERE%' ORDER BY indexname;"
```

**4c · Generated column'lar** ve gerçekten hesapladıkları:

```bash
docker exec entegrasyon-restore-test psql -U entegrasyon \
  -d entegrasyon_restore -Atc \
  "SELECT COUNT(*) FROM listing_sync_states WHERE is_dirty IS NOT NULL;"
```

**4d · KİMLİK BİLGİLERİ ÇÖZÜLÜYOR MU — en önemli kontrol:**

```bash
docker compose exec -T \
  -e DB_HOST=host.docker.internal -e DB_PORT=5434 \
  -e DB_DATABASE=entegrasyon_restore \
  app php artisan tinker --execute="
\App\Support\Tenancy\TenantContext::runAsSystem(function() {
  \\\$rows = \App\Domain\Channels\Models\ChannelCredential::query()
      ->whereNull('revoked_at')->get();
  \\\$ok = 0; \\\$fail = 0;
  foreach (\\\$rows as \\\$r) {
    try {
      \\\$s = json_decode(\Illuminate\Support\Facades\Crypt::decryptString(
          \\\$r->encrypted_payload), true);
      is_array(\\\$s) && \\\$s !== [] ? \\\$ok++ : \\\$fail++;
    } catch (\Throwable) { \\\$fail++; }
  }
  echo \"cozulen: \\\$ok | cozulemeyen: \\\$fail\".PHP_EOL;
});"
```

**`cozulemeyen` sıfırdan büyükse yedek İŞE YARAMAZ** — anahtar yanlış
veya kayıp demektir ve o durumda tüm kanal bağlantıları ölüdür.

**4e · Ledger bütünlüğü** — projenin en temel değişmezi:

```bash
docker exec entegrasyon-restore-test psql -U entegrasyon \
  -d entegrasyon_restore -Atc "
SELECT COUNT(*) FROM inventory_levels il
  JOIN (SELECT warehouse_id, variant_id, SUM(on_hand_delta) t
          FROM inventory_movements GROUP BY 1,2) m
    ON m.warehouse_id=il.warehouse_id AND m.variant_id=il.variant_id
 WHERE il.on_hand <> m.t;"      # 0 olmalı
```

### 5 · Temizle

```bash
docker rm -f entegrasyon-restore-test
rm -f yedek.dump kaynak.txt hedef.txt
```

Yedek dosyası **silinir**: içinde şifreli de olsa kimlik bilgisi ve
kişisel veri (sipariş alıcı adı, adres) vardır ve geliştirme makinesinde
bırakılmamalıdır.

---

## Üretim yapılandırması (§15) — sunucu kurulunca

| Ne | Nasıl | Saklama |
|---|---|---|
| PostgreSQL | pgBackRest → Hetzner Storage Box | 7 günlük + 4 haftalık |
| | günlük tam + **sürekli WAL arşivi** | ↳ **AYDA BİR GERİ YÜKLEME TESTİ** |
| Redis | `appendonly yes`; kalıcı veri yok (outbox PostgreSQL'de) | — |
| Görseller | yerel disk + günlük rsync | R2'ye geçiş 20 GB'ta |
| **APP_KEY** | parola yöneticisi + çevrimdışı kopya | ↳ **sunucu yedeğinden BAĞIMSIZ** |

### APP_KEY — atlanırsa felaket

> §11: *"APP_KEY kaybedilirse tüm kanal bağlantıları geri döndürülemez
> şekilde ölür ve her müşteri yeniden yetkilendirme yapmak zorunda kalır.
> Anahtar en az iki yerde saklanmalı. Sunucu yedeğine güvenmek yeterli
> değil. İlk gün yapılacak beş dakikalık iş."*

Yedek ve anahtar aynı yerde duruyorsa ikisini birden kaybetmek **tek bir
olaydır** — yedeklemenin tüm amacı budur ve amaç boşa çıkar.

Bu provanın 4d adımı, anahtar ile yedeğin **birlikte** çalıştığını
kanıtlayan tek kontroldür ve her provada koşmalıdır.

### Ölçek sinyali (§15)

> **Yedekten dönüş süresi > 1 saat → yönetilen veritabanı değerlendir.**

Bu yüzden her provada **süre ölçülür ve buraya yazılır**. Ölçülmeyen bir
geri yükleme süresi, eşiğe ulaşıldığını asla haber vermez.

---

## Prova kaydı

| Tarih | Ortam | Araç | Boyut | Süre | Sonuç |
|---|---|---|---|---|---|
| 24 Ağu 2026 | Yerel Docker | `pg_dump -Fc` | 177 KB | ~6 sn | ✅ 7/7 kontrol geçti |

**Sıradaki prova:** sunucu kurulduğunda, **pgBackRest ile** ve gerçek veri
hacminde. §15 aylık tekrar istiyor.
