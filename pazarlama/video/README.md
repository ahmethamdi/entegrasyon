# 34Pazar tanıtım videoları (Remotion)

- `PazarReelsTR` — Türkçe reklam Reels'i, 1080×1920, 30 fps, 24 sn, sessiz (ses Instagram'da eklenir).
- `PazarKapakTR` — Reels kapağı (1080×1920 PNG).

Görseller `public/img` → deponun `public/images` klasörüne bağlantı (logolar, kanal ikonları).
Not: Remotion tek dosya bağlantısını sunmuyor, klasör bağlantısını sunuyor.

```
npm install
npx remotion render src/index.ts PazarReelsTR out/34pazar-reels-tr.mp4 --codec=h264 --crf=18
npx remotion still src/index.ts PazarKapakTR out/kapak.png
```
