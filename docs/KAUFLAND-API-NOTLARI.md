# Kaufland Seller API v2 — notlar (21 Eki 2026)

Kaynak: https://sellerapi.kaufland.com/?page=rest-api ve yayımlanan OpenAPI 3
(`https://sellerapi.kaufland.com/swagger.json`, 21 Eki 2026 indirildi).

## Kimlik
- Taban: `https://sellerapi.kaufland.com/v2/`
- Başlıklar: `Accept: application/json`, `Shop-Client-Key`, `Shop-Timestamp` (Unix sn, ±5 dk),
  `Shop-Signature`, `User-Agent` (yazılım sağlayıcı adı; biz `34Pazar`), POST/PATCH'te `Content-Type: application/json`.
- İmza: `hex(HMAC-SHA256(secret, METHOD\nTAM_URI\nGÖVDE\nTIMESTAMP))`. URI `https://` ve sorgu dahil.
  Secret hex görünür ama metin olarak kullanılır (hex çözülmez).

## Kullanılan uçlar
| İş | Uç |
|---|---|
| Sağlık | `GET /units?storefront=de&limit=1` |
| İçe aktarma | `GET /units?storefront&limit≤100&offset&embedded=product` |
| Stok / fiyat | `PATCH /units/{id_unit}?storefront` gövde `{"amount"}` / `{"listing_price"}` (KURUŞ tam sayı) |
| Okuma (mutabakat) | `GET /units/{id_unit}?storefront` |
| Sipariş | `GET /order-units?storefront&ts_updated_from_iso&sort=ts_updated:desc&limit≤100&offset` |
| Sipariş detayı | `GET /orders/{id_order}` (`order_units[]`) |
| Kargo | `PATCH /order-units/{id_order_unit}/send` `{"tracking_numbers","carrier_code"}` |

- Storefront: de, cz, sk, pl, at, fr, it, es, nl. Para: EUR, CZK (cz), PLN (pl).
- Birim durumları: open, need_to_be_sent, sent, sent_and_autopaid, received, cancelled, returned, returned_paid.
- Her sipariş birimi = 1 adet. 34Pazar birimleri `id_order` altında toplar; iptal/iade birim başına olay.
- Taşıyıcı kodları ad olarak (DHL, DPD, GLS, Hermes, UPS, Deutsche Post …); takip no yoksa `Other`.

## Doğrulanmadı (beta — ilk müşteriyle)
- Hız sınırı (belgede sayı yok; 2/sn varsayıldı).
- `ts_updated_from_iso` biçimi (`2026-10-20T10:00:00Z` gönderiliyor).
- Yeni ürün açma (katalogda olmayan EAN) YOK; `POST /units` ile teklif açma yazılmadı.
- İade (returned) olayının stok etkisi: ürün fiziksel olarak dönmeden `returned` gelirse fazla stok riski.
