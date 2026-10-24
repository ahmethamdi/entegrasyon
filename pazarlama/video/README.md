# 34Pazar tanıtım videoları (Remotion)

- `PazarReelsTR` — Türkçe reklam Reels'i, 1080×1920, 30 fps, 24 sn, sessiz (ses Instagram'da eklenir).
- `PazarKapakTR` — Reels kapağı (1080×1920 PNG).
- `PazarReelsTRv2` / `PazarKareTRv2` — v2 "şov" sürümü, 29 sn: bildirim yağmuru + kinetik kanca, 15 kanala akan satış (62→61), sayan rakamlar, 3B eğik sipariş kartı, set ürün, basılan CTA. Aynı bileşen 9:16 ve 1:1 kadraja kendini yerleştirir.
- `PazarKapakTRv2` — v2 kapağı.

Görseller `public/img` → deponun `public/images` klasörüne bağlantı (logolar, kanal ikonları).
Not: Remotion tek dosya bağlantısını sunmuyor, klasör bağlantısını sunuyor.

```
npm install
npx remotion render src/index.ts PazarReelsTR out/34pazar-reels-tr.mp4 --codec=h264 --crf=18
npx remotion still src/index.ts PazarKapakTR out/kapak.png
```
