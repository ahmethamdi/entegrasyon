# Amazon SP-API — başvuru metinleri ve teknik notlar (21 Eki 2026)

34Pazar kodu hazır (beta, `AmazonAdapter`); canlıda çalışması için 34Devs'in **Solution Provider
Portal** (https://solutionproviderportal.amazon.com) kaydı ve onaylı bir **public** SP-API uygulaması gerekir.
Aşağıdaki metinler form alanlarına doğrudan yapıştırılmak içindir (İngilizce istenir).

## 1. Geliştirici profili
- **Organization:** 34Devs Shopify Agency (sole proprietorship), Hedwigstraße 27, 41352 Korschenbroich, Germany
- **Contact:** info@34devs.com
- **Developer type:** Public developer — "I build applications for other sellers"
- **Website:** https://34pazar.com

## 2. Use case (kullanım durumu)
> 34Pazar is a multichannel inventory and order management SaaS for small and mid-sized merchants
> selling on Amazon, Shopify, Etsy, eBay, Kaufland, OTTO and Turkish marketplaces. Sellers connect
> their Amazon account via the website authorization workflow. 34Pazar keeps stock quantities and
> prices in sync across channels to prevent overselling (Listings Items API), imports the seller's
> existing SKUs (searchListingsItems), polls orders to reduce stock in real time (Orders API, order
> and item data only — no buyer PII), and confirms shipments with tracking numbers (confirmShipment).

## 3. Roller (Roles)
- **Product Listing** — Listings Items (stok/fiyat yaması, SKU listesi)
- **Pricing** — fiyat güncellemesi
- **Inventory and Order Tracking** — Orders API (yoklama, kalemler)
- **Direct-to-Consumer Shipping** — İSTENMEZ (adres/PII gerekmiyor); `confirmShipment` Inventory and Order Tracking ile yeterli.
  ⚠️ Doğrula: kargo onayı başvuru sırasında farklı rol isterse yalnız o rol eklenir.
- Restricted (PII) roller **istenmez**: alıcı adı/adresi okunmuyor, RDT kullanılmıyor.

## 4. Veri koruma (Amazon Data Protection Policy) cevapları
| Soru | Cevap |
|---|---|
| Network protection | Production on a hardened Ubuntu 24.04 VPS (IONOS, Germany), only 443 exposed via Apache reverse proxy; database and Redis not reachable from the internet; outbound SSRF guard for all channel calls. |
| Access management | Personal accounts, least privilege, SSH key-only root access, admin panel restricted to named staff. |
| Encryption in transit | TLS 1.2+ for every connection (Let's Encrypt); SP-API calls over HTTPS only. |
| Encryption at rest | LWA refresh tokens stored encrypted (Laravel encrypter, AES-256-CBC, app key outside the repository); order payloads stored encrypted (`encrypted:array`); personal fields masked before storage. |
| Data retention | No buyer PII requested. Order/item data kept while the seller's account is active; deleted on disconnect/uninstall and on account deletion; database backups rotated (14 days). |
| Incident response | Documented incident process: detection via alerts (ntfy + e-mail), containment, credential rotation, notification of affected sellers and Amazon (security@amazon.com) within 24 hours. |
| Logging & monitoring | API call log with secrets redacted, failed-sync dashboards, alerting on authentication failures. |
| Vulnerability management | Dependency audit in CI (composer + npm) on every push; OS security updates applied weekly. |
| Third parties | Hosting: IONOS SE (Germany). No PII shared with third parties. |

## 5. Uygulama kaydı (onaydan sonra)
- **OAuth Login URI:** boş (website workflow 34pazar panelinden başlar)
- **OAuth Redirect URI:** `https://34pazar.com/channels/amazon/callback`
- Env: `AMAZON_LWA_CLIENT_ID`, `AMAZON_LWA_CLIENT_SECRET`, `AMAZON_APPLICATION_ID`, `AMAZON_APP_DRAFT` (taslakken `true` → `version=beta`).

## 6. Teknik notlar
- Uç nokta: `https://sellingpartnerapi-eu.amazon.com` (DE `A1PA6795UKMFR9`, TR `A33AVAJ2PDY3EV`).
- Kimlik: LWA (`https://api.amazon.com/auth/o2/token`), `x-amz-access-token` başlığı; SigV4 yok.
- Stok: `PATCH /listings/2021-08-01/items/{sellerId}/{sku}` `/attributes/fulfillment_availability` (DEFAULT = satıcı gönderimi; FBA stoğuna dokunulmaz).
- Fiyat: aynı uç, `/attributes/purchasable_offer` `our_price.schedule.value_with_tax`.
- Sipariş: `GET /orders/v0/orders?LastUpdatedAfter` (+ `NextToken`), kalemler `/orderItems`; olay kimliği `{AmazonOrderId}:{OrderStatus}`.
- Kargo: `POST /orders/v0/orders/{id}/shipmentConfirmation`.
- **Doğrulanmadı:** searchListingsItems `offers`/`fulfillmentAvailability` alanları, `productType: PRODUCT` yamasının her kategoride kabulü, getOrderItems hız sınırı altında tur süresi.
