# 34Pazar — Shopify App Store listeleme metni

Birincil dil İngilizce (inceleme İngilizce). Sınırlar Partner formundan
ölçüldü (5 Eki 2026). Kurallar: istatistik/yorum yok (4.3.3, 4.3.6),
görsellerde fiyat ve Shopify logosu yok (4.2.2, 4.4.3), dil yalnız
arayüzün desteklediği diller (4.3.2).

Görseller: `listing/` (1600×900) — öne çıkan görsel + 5 ekran görüntüsü,
İngilizce panelden, yerel demo mağazadan ("Nur Atelier") üretildi.

## Basic app information

- **App name** (≤30): `34Pazar`
- **Languages:** English, Turkish

## App store listing content

**App introduction** (≤100)
> Sell on Trendyol from your Shopify store. One stock count keeps both channels in sync.

**App details** (≤500)
> 34Pazar connects your Shopify store to your Trendyol seller account and keeps a single stock count for both. When an order arrives on either channel, the stock goes down everywhere, so you don't sell items you no longer have. Import your Shopify products, send them to Trendyol and follow their approval. See all orders in one list and enter tracking numbers for Shopify orders. Requires a Trendyol seller account. The app is available in English and Turkish.

**Features** (3–5, ≤80)
1. `One stock count for Shopify and Trendyol, updated on every order`
2. `Import your Shopify products and list them on Trendyol`
3. `Orders from both channels in one list, with stock status`
4. `Count stock in place, with low-stock and out-of-stock filters`
5. `See why a channel rejected a product and send it again`

**Feature media:** image `listing/0-feature.png`

**Screenshots** (alt text ≤64)
1. `listing/1-dashboard.png` — `Home screen with tasks that need attention`
2. `listing/2-products.png` — `Products with stock and channel status`
3. `listing/3-orders.png` — `Orders from Shopify and Trendyol in one list`
4. `listing/4-listing.png` — `Product status on each connected channel`
5. `listing/5-channels.png` — `Connected Shopify store and Trendyol account`

**Integrations:** `Trendyol`

**Support:** email `info@34devs.com`
**Privacy policy:** https://34pazar.com/yasal/privacy
**Developer website:** https://34pazar.com

## Pricing (Shopify Billing, USD, monthly)

| Plan | Price | Limits |
|---|---|---|
| Free | $0 | 25 products, 1 channel |
| Starter | $9.99 | 500 products, 2 channels |
| Professional | $29.99 | 5,000 products, 5 channels |
| Business | $79.99 | unlimited products and channels |

## App discovery content

- **App card subtitle** (≤62): `Sync Shopify stock and orders with the Trendyol marketplace`
- **Search terms** (1–5, ≤20): `trendyol`, `marketplace`, `inventory sync`, `stock sync`, `multichannel`
- **Title tag** (≤60): `34Pazar: Trendyol stock and order sync for Shopify`
- **Meta description** (≤160): `Connect your Shopify store to Trendyol. One stock count for both channels, product import and listing, and all orders in one list.`

## Install requirements

- My app doesn't require the Shopify Online Store or Shopify POS.
- Geographic: none set (Trendyol seller account requirement is stated in App details).

## Contact

- Merchant review email: info@34devs.com
- App submission email: info@34devs.com

## App testing information

**Test account:** review@34pazar.com (password in the Partner form only — not stored in the repo).
Account description: `34Pazar account (English UI). You can also create a new account during install.`

**Testing instructions** (≤2800)

```
34Pazar is a standalone web app (not embedded). It opens at https://34pazar.com in a new tab.

1. Install the app on your development store. Shopify's permission screen opens first; after you approve, you land on 34Pazar.
2. Create a 34Pazar account (name and store email are prefilled), or choose "Log in" and use the test account below. Your store connects to the account automatically. No store address or API key is asked.
3. The panel follows your browser language. Use "EN · English" at the bottom of the left menu to switch.
4. Channels: your store shows as connected. If the store has more than one location, choose the location stock should sync with.
5. Products > Bulk import > Import from a channel: imports your Shopify products. The Free plan imports up to 25 products.
6. Products: click a product's stock number, enter a new count and save. Within about a minute, the available quantity at that location in Shopify admin changes to the same number.
7. Place a test order in Shopify (Bogus Gateway) for one of the imported products. It appears under Orders and that product's stock goes down.
8. Open the order, enter a tracking number under Shipping and click "Mark as shipped". A fulfillment with that tracking number is created in Shopify.
9. Subscription: choose "Starter" > "Switch to this plan". Shopify's charge approval page opens (a test charge on development stores). After approving you return to 34Pazar with the plan active. "Switch to the free plan" cancels the subscription.
10. Uninstalling closes the connection. Compliance webhooks are handled at https://34pazar.com/webhooks/shopify/compliance (invalid HMAC returns 401).

Trendyol: connecting Trendyol needs the merchant's own Trendyol seller API key and seller ID (Channels > Connect store > Trendyol).
```
