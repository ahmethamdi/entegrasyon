# 05 · Güvenlik ve çok kiracılık

Gizli değer, token ya da müşteri verisi bu belgede yer almaz; ayarlar yalnız adıyla anılır.

## 1. Kiracı izolasyonu

| Bulgu | Sınıf | Not |
|---|---|---|
| `BelongsToTenant` global scope, bağlam yoksa istisna (fail-closed) | EXISTS | `app/Support/Tenancy/BelongsToTenant.php` |
| `creating` kancası `tenant_id` boşsa dolduruyor ama **verilen değerin bağlamla eşleştiğini denetlemiyor**; `tenant_id` 38 modelde `$fillable` | RISKY (gizil) | Bugün `$request->all()` kalıbı yok; tek savunma denetleyici disiplini |
| `TenantContext` set/clear/runFor/runAsSystem, `finally` ile geri yükleme | EXISTS | |
| Kiracı ara katmanı üyeliği her istekte doğruluyor | EXISTS | Rol okunmuyor (bkz. 3) |
| Rota model bağlama sırası tuzağı | EXISTS (çözülmüş) | Denetleyiciler `string` kimlik + kapsam altında `findOrFail`; alt kaynaklar ebeveynine bağlı |
| `BelongsToTenant`'sız modeller | EXISTS (gerekçeli) | User, Tenant, Plan, kanal taksonomisi, TenantUser |
| Ham sorgular (~90 `DB::table`) | PARTIAL | Örneklenenlerin hepsi `tenant_id` süzüyor; kural yapısal değil. `MetricsController::history` platform geneli metrikleri her kiracıya gösteriyor (küçük bilgi sızıntısı) |
| Unique kısıtlar kiracıyı içeriyor | EXISTS | `(channel_type_code, external_account_id)` bilinçli olarak global |
| Alt tablolarda `(tenant_id, parent_id)` bileşik FK | PARTIAL | Bütünlük uygulama katmanında |
| Postgres RLS | MISSING | P3 — mevcut test kültürü ve fail-closed scope ile şimdilik gerekmiyor |

## 2. Kuyruk ve zamanlanmış işlerde kiracı bağlamı

| Bulgu | Sınıf |
|---|---|
| 10 kuyruk işinin hepsi `tenantId` taşıyor ve `finally`'de temizliyor; `QueueServiceProvider` ikinci savunma hattı | EXISTS |
| Çok kiracılı komutlar (`ReconcileActiveConnections`, `TrackApproval…`, `PollChannelBatches`, `SyncTaxonomy…`, kampanya tick, eşleşmeyen kalem) `runFor` + kalem başına `try/catch` | EXISTS |
| **`TokenRefresher`**: adapter kurulumu ve kasa yazımı `try` dışında (✔); hepsi `runAsSystem`; başarısız satırlar kuyruğun başında kalıyor → bir kiracının bozuk bağlantısı diğerlerinin Etsy/eBay token'larını süresi doldurtabilir | **RISKY (yüksek)** |
| **`PollChannelOrders`**: `adapters->for()` `try` dışında → tek yanlış yapılandırılmış bağlantı sonraki kiracıların yoklamasını durdurur | **RISKY** |
| `ChannelHealthSweep`, `RegisterWebhooksCommand` `runAsSystem` altında (scope kapalı) | PARTIAL |
| Kiracı/bağlantı anahtarsız önbellek | EXISTS (yok; Trendyol marka önbelleği global platform verisi — kabul edilebilir) |

**İlke:** "Kiracı A'nın hatası kiracı B'yi etkilememeli" kuralı iki komutta ihlal edilebiliyor → **P0**.

## 3. Yetkilendirme

| Bulgu | Sınıf |
|---|---|
| Rol/izin modeli (`tenant_users.role` yalnız `'owner'`), policy/gate, davet akışı | **MISSING** |
| Sahiplik kontrolü (URL'deki her kimlik kiracı kapsamında, başka kiracıda 404) | EXISTS |
| Süper yönetici (doğrulanmış e-posta + env listesi) | EXISTS; 2FA ve süper yönetici okuma denetimi MISSING |
| Giriş hız sınırı `trustProxies('*')` ile `X-Forwarded-For`'a dayanıyor | PARTIAL (Caddy başlığı ezmiyorsa sahtelenebilir) |

## 4. Kimlik bilgileri

| Bulgu | Sınıf |
|---|---|
| `CredentialVault` (Crypt, `APP_PREVIOUS_KEYS`, `key_version`, okumada yeniden şifreleme) | EXISTS; toplu yeniden şifreleme komutu yok (PARTIAL) |
| `InvoiceAccount.credentials` şifreli + `$hidden` | EXISTS |
| Sırlar `settings` jsonb'a girmiyor (testli) | EXISTS |
| Maskeleme (`ChannelErrorText`, `PayloadRedactor`, `PersonalDataMask`, şifreli inbox gövdesi) | EXISTS; iç içe sırlar ikinci katmanda yakalanmıyor (PARTIAL) |
| Uygulama log kanalında maskeleme işlemcisi yok; ~30 yer ham `$e->getMessage()` yazıyor (HTTP istisnası yanıt gövdesi parçası taşır) | PARTIAL |
| `SESSION_ENCRYPT=false` + veritabanı oturumu → OAuth `state`/Etsy `code_verifier` düz metin | PARTIAL |
| Inertia'ya model değil seçilmiş dizi gidiyor | EXISTS |

## 5. Webhook'lar

| Bulgu | Sınıf |
|---|---|
| Ham gövde imzası, `hash_equals`, sır yoksa ret | EXISTS |
| **Shopify webhook'u mağazaya bağlı değil** — uygulama sırrıyla imzalı istek her `/webhooks/{connectionId}`'de kabul ediliyor; `X-Shopify-Shop-Domain` bağlantının hesabıyla karşılaştırılmıyor; Shopify abonelik güncellemesi global aranıyor | **RISKY** |
| Replay | PARTIAL (olay kimliği kalıcı tekil; kimliksizlerde saatlik hash; zaman damgası toleransı yok) |
| Hız sınırı imzadan **önce** uygulanıyor → bağlantı UUID'sini bilen biri kovayı tüketebilir; pasif/iptal bağlantılar webhook kabul ediyor | PARTIAL |
| CSRF muafiyeti dar (`api` grubu) | EXISTS |

## 6. OAuth

Etsy (PKCE + state), eBay, Shopify (state + HMAC + mağaza alan adı doğrulama), Paraşüt (state) — EXISTS. Başka kiracının mağazasını sahiplenme engelli (global unique) — EXISTS; "ilk bağlayan kazanır", devir/itiraz yolu yok — PARTIAL.

## 7. Toplu atama, SSRF, yükleme, denetim

| Bulgu | Sınıf |
|---|---|
| `$fillable` her yerde; sistem yazımları `forceFill` | PARTIAL (bkz. tenant_id) |
| SSRF: `OutboundUrlGuard` (özel ağlar, DNS sabitleme, yönlendirme kapalı, yalnız 443) | EXISTS; şema denetimi yok (`http://` görsel adresi), `download()` sınırı okuduktan sonra | PARTIAL |
| CSV yükleme boyut/MIME sınırı | EXISTS; kiracı başına içe aktarma hız sınırı yok |
| **Denetim kaydı** | PARTIAL — kapsanan: kanal bağlama/kimlik, stok düzeltme, kanal fiyatı, fiyat kuralı, kampanya aç/iptal, plan atama, fatura hesabı. Yazım "en iyi çaba" (hata yutuluyor), DB'de değişmezlik yok |
| **Denetimsiz iş değişiklikleri** | MISSING — taban fiyat ve ürün düzenleme, maliyet, yayınlama/kaldırma, görsel kanal hariç tutma, kategori/öznitelik eşleme, kanal ayarları, panel kargo + yeniden deneme, fatura kes/yeniden dene/otomatik fatura, hata yeniden deneme, içe aktarmalar, otomatik kampanya başlangıç/bitiş, bağlantı iptali, abonelik değişiklikleri, giriş/çıkış, süper yönetici görüntülemeleri, rol değişiklikleri |

**Teknik log ile iş denetimini ayırma:** mevcut ayrım doğru (`audit_logs` ≠ `api_calls` ≠ uygulama log'u). Eksik olan kapsam ve "sistem" aktörü: otomatik işlemler (`campaigns:tick`, `MaybeAutoInvoice`, mutabakat onarımı) `actor_type=system` + sebep ile yazılmalı. Stok için iş denetimi zaten defterin kendisi (`inventory_movements`) — ayrıca `audit_logs`'a kopyalanmamalı, ekranda birleştirilmeli.

## 8. Faturalandırma

Stripe imza + tolerans — EXISTS; olay kimliği tekilleştirme ve sıra kontrolü yok (geç gelen `updated`, `deleted`'ı ezebilir) — PARTIAL. Shopify Billing dönüşü Shopify'dan yeniden okunuyor — EXISTS.

## Önem sırasıyla ilk beş

1. **Yüksek:** `TokenRefresher` / `PollChannelOrders` hata izolasyonu (P0).
2. **Yüksek (çok kullanıcıyla birlikte):** rol/izin modeli yok (S7).
3. **Orta:** Shopify webhook ↔ mağaza bağı (P0, küçük).
4. **Orta:** denetim kapsamı ve güvenilirliği (S7; S1'den itibaren her yeni iş eylemine `AuditAction` eklenir).
5. **Orta:** log/oturum sızıntısı ve sahtelenebilir istemci IP'si (S7).

## Çok kullanıcı ve rollerden ÖNCE (S7) yapılması gerekenler

Rol modeli (owner/admin/staff/read-only) ve `EstablishTenantContext`'te çözülmesi · eylem başına policy/gate · süreli tek kullanımlık davet + üyelik kaldırma (oturum iptali) + son sahip koruması · kullanıcı/rol `AuditAction`'ları ve eksik iş eylemleri · üyelik kontrollü kiracı değiştirici · sahip ve süper yönetici için 2FA · `creating` kancası bağlamla uyuşmayan `tenant_id`'yi reddetsin · güvenilir proxy'yi Caddy adresine sabitle.
