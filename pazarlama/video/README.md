# 34Pazar tanıtım videoları (Remotion)

- `PazarReelsTR` — Türkçe reklam Reels'i, 1080×1920, 30 fps, 24 sn, sessiz (ses Instagram'da eklenir).
- `PazarKapakTR` — Reels kapağı (1080×1920 PNG).
- `PazarReelsTRv2` / `PazarKareTRv2` — v2 "şov" sürümü, 29 sn: bildirim yağmuru + kinetik kanca, 15 kanala akan satış (62→61), sayan rakamlar, 3B eğik sipariş kartı, set ürün, basılan CTA. Aynı bileşen 9:16 ve 1:1 kadraja kendini yerleştirir.
- `PazarKapakTRv2` — v2 kapağı.
- `PazarReelsTRv3` / `PazarKareTRv3` — v2'nin müzikli sürümü, 32,4 sn. Müzik: "House - July_32Sec" (Easy_Eva, Pixabay Content License, Content ID yok). Kurgu 127 BPM bar ızgarasında, drop bar 9'da (17,43 sn).
  Müzik dosyası depoda YOK: `public/muzik/july32-norm.wav` (−14 LUFS) yerelde üretilir; kaynak ve lisans kaydı Masaüstü `03 34Devs/34Pazar - Entegrasyon/reels/muzik/`.

Görseller `public/img` → deponun `public/images` klasörüne bağlantı (logolar, kanal ikonları).
Not: Remotion tek dosya bağlantısını sunmuyor, klasör bağlantısını sunuyor.

```
npm install
npx remotion render src/index.ts PazarReelsTR out/34pazar-reels-tr.mp4 --codec=h264 --crf=18
npx remotion still src/index.ts PazarKapakTR out/kapak.png
# v3 (önce public/muzik/july32-norm.wav gerekir):
npx remotion render src/index.ts PazarReelsTRv3 out/v3.mp4 --codec=h264 --crf=18 --audio-codec=aac --audio-bitrate=192k
```
