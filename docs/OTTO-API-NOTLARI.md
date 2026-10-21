# OTTO Market API — notlar (21 Eki 2026)

Kaynak: https://api.otto.market/docs/sp-integration ve birleşik OpenAPI
https://api.otto.market/docs/openapi.json (21 Eki 2026 indirildi).

## Bağlanma (Service Partner)
1. 34Devs servicepartner.otto.market'te kayıt → OTTO Partner Connect (portal.otto.market) → App Management'ta uygulama.
   Üretimde başka satıcıların kurması için "Private Production Apps" (davet bağlantısı, 24 sa) ya da
   "Public Production Apps" izni gerekir; public için kapsam başına kalite kontrolü + OTTO eğitimi.
2. Satıcı uygulamayı kurar → panelde "Yetkilendir" → `GET {host}/oauth2/auth` (scope `installation partnerId`)
   → `POST /oauth2/token` (authorization_code) → `GET /v1/apps/{appId}/installation` → `installationId`.
3. Her API çağrısı: `POST /oauth2/token` (client_credentials, scope `developer`) → `POST /v1/apps/{appId}/installations/{installationId}/accessToken`
   (`scope=products orders availability`, ömür 1800 sn; 25 dk önbellek).
- Env: `OTTO_CLIENT_ID`, `OTTO_CLIENT_SECRET`, `OTTO_APP_ID`, `OTTO_SANDBOX` (sandbox.api.otto.market).
- Dönüş adresi uygulamada kayıtlı olmalı: `https://34pazar.com/channels/otto/callback`.

## Kullanılan uçlar
| İş | Uç |
|---|---|
| İçe aktarma | `GET /v5/products?page&limit≤100` (`productVariations[]`, kimlik `sku`) |
| Stok | `POST /v1/availability/quantities` `[{sku, quantity}]` ≤200; 207 = kalem hatası (`errors[].logref` = SKU) |
| Fiyat | `POST /v5/products/prices` `[{sku, standardPrice:{amount, currency}}]` → 202 görev |
| Sipariş | `GET /v4/orders?fromDate&limit≤256`, sonraki sayfa `nextcursor` |

- Kalem durumları: ANNOUNCED, PROCESSABLE, SENT, RETURNED, CANCELLED_BY_PARTNER, CANCELLED_BY_MARKETPLACE.
- Her kalem 1 adet; 34Pazar kalemleri `salesOrderId` altında toplar, iptal/iade kalem başına olay.

## Yapılmayanlar
- Kargo bildirimi: `POST /v1/shipments` her kalem için iade takip numarası (`returnTrackingKey`) ve
  `shipFromAddress` ister — panelde yok. Satıcı OTTO portalında girer.
- Ürün açma (`POST /v5/products`), iade kabul/red (`/v3/returns`).

## Doğrulanmadı
- Kurulum anahtarı yanıt alanı (`access_token` varsayıldı), `installation` yanıtında `installationId` alanı.
- `fromDate`'in iptalleri yakaladığı (lifecycleChangeDate'e göre).
