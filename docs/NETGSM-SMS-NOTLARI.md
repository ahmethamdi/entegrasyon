# Netgsm SMS — teknik notlar (17 Kas 2026)

34Pazar kendi adına SMS göndermez ve kredi satmaz. Satıcı **kendi Netgsm hesabını** bağlar
(Ayarlar › SMS bildirimleri); mesajlar onun onaylı gönderici başlığıyla, onun kredisinden gider.

## Kaynak

- Resmî API belgesi: https://api-docs.netgsm.com.tr/ (auth-v3, sms-v3, sandbox)
- Resmî SDK: https://github.com/netgsm/netgsm-sms-js (README, `src/sms/`, `MIGRATION.md`)
- `netgsm.com.tr/dokuman` JavaScript kabuğu; sunucudan içerik dönmüyor, kaynak olarak kullanılmadı.

Eski REST v2 (`api.netgsm.com.tr/sms/rest/v2/*`, Basic auth) SDK'da "eskidi" olarak işaretli
(API'nin emeklilik tarihi ilan edilmedi). **v3 kullanılır.**

## Uç noktalar

| İş | İstek | Not |
|---|---|---|
| Giriş | `POST /auth/v3/token` `{username, password, scope:"sms"}` | `access_token` (JWT, `expires_in` 86400). IP başına 30/dk → jeton önbellekte (şifreli) tutulur |
| Başlıklar | `GET /sms/v3/senders` | `{senders:[{name, type, brandCode, usable, reason}]}` — `usable:false` olan listeye girmez |
| Bakiye | `GET /sms/v3/balance?stip=3` | `{balance:[{amount, balance_name}]}` — yalnız ekranda bilgi |
| Gönderim | `POST /sms/v3/send` | `{sender, messages:[{text, recipient}], encoding, iysFilter, referenceId, appName}` → 202 `{jobId, status, accepted, rejected, rejectedItems}` |

- Canlı: `https://ext-api.netgsm.com.tr` · Test: `https://ext-sandbox.netgsm.com.tr`
- Numara biçimi `90XXXXXXXXXX`; `0…` ve `5…` ile başlayan REDDEDİLİR (`SmsPhone::normalize`).
- `encoding`: metinde Türkçe harf varsa `TURKISH`, yoksa `DEFAULT`.
- `iysFilter`: `NONE` — yalnız bilgilendirme iletisi (sipariş, stok, kargo) gönderilir; ticari/kampanya SMS'i
  bu özelliğin kapsamında YOK. BRAND/RETAILER başlıkta İYS marka kodu ister (`REJECTED_IYS`).
- Hata gövdesi `{code, message, requestId, violations}`; 422 `REJECTED_HEADER` / `REJECTED_IYS` /
  `NO_DELIVERABLE_MESSAGES`, 429 `RATE_LIMITED`. Giriş hataları problem+json (`code: invalid_credentials`).
- **Tekilleştirme YOK.** 503/zaman aşımında gönderim körlemesine tekrarlanmaz; tekrarı `sms_event_keys` engeller.

## Test ortamı (sandbox)

Gerçek SMS göndermez, kredi düşmez, sabit veri döner. Belgedeki herkese açık hesap:
`8500000000` / `password001`, başlık `TESTHEADER`. 17 Kas'ta uçtan uca denendi (giriş, başlık, bakiye,
gönderim, yanlış şifre, kayıtsız başlık, geçersiz numara); cevaplar `tests/Fixtures/netgsm/`.
**Gerçek bir Netgsm hesabıyla canlı gönderim henüz denenmedi.**

## Bildirimler (`sms:notify`, dakikada bir)

| Tür | Kime | Kural |
|---|---|---|
| `new_order` | satıcı | `created_at` ve `placed_at` pencerede; tek özet SMS |
| `stock_out` | satıcı | canlı ilanı olan ürünün toplam `available` ≤ 0; ürün başına günde bir |
| `channel_problem` | satıcı | bir kez sağlıklı olmuş bağlantı ≥15 dk sağlıksız; her kopuşta bir |
| `buyer_shipped` | alıcı | 34Pazar'dan kanala bildirilmiş (`push_status = sent`) takip numaralı kargo; yalnız `SupportsShippingRecipient` kanalları (Shopify, WooCommerce) |

- Pazaryeri alıcısına SMS GİTMEZ: telefon vermezler ve platform dışı iletişimi yasaklarlar.
- Alıcının telefonu kanaldan ANLIK okunur, hiçbir yere yazılmaz; kayıtta yalnız maskeli numara (`90532•••2233`).
- Sessiz saat (varsayılan açık) 22:00–08:00 TR: hiçbir şey gitmez, sabah 12 saatlik geriye bakışla özetlenir.
- Kimlik reddi hesabı `revoked` yapar; satıcı yeniden bağlayana kadar deneme yapılmaz.
- Acil kapatma: `SMS_ENABLED=false` (ekran 404, tarama hiçbir şey kuyruğa almaz).
