# İnceleme videosu nasıl yeniden çekilir

Yayındaki dosya: `public/review/screencast.mp4` (Partner formunda bu adres yazılı, DEĞİŞTİRME).

1. Chrome: `open -na "Google Chrome" --args --user-data-dir=<claudeekran>/shopify-partner/.chrome-profil --remote-debugging-port=9333`
2. Shopify hesap dili → English (accounts.shopify.com → personal → Language); bitince `tr`'ye geri al.
3. Bu klasörde `node_modules` → playwright gerekli (`ln -s <claudeekran>/sosyal/node_modules`).
4. Her bölüm: `node kaydet.mjs 03-giris &` → `node sur.mjs '<adımlar>'` → `kill -TERM $(cat bolumler/03-giris/pid)`.
5. `python3 montaj.py` → `cikti/screencast.mp4` (altyazılar `SEGMENTS` içinde).

Tuzaklar (6 Eki 2026):
- Panel dili tarayıcıdan gelir; profil Türkçe → kaydedici `Accept-Language: en` basar (kaydet.mjs).
- Onay pencereleri (confirm): kaydedicide boş `dialog` dinleyicisi şart, yoksa iki Playwright yarışır ve kaydedici çöker.
- Shopify kaldırma: sebep seçilmeden "Uninstall" pasif; listeyi kapatmak için Escape DEĞİL başlığa tıkla.
- Tarayıcı e-postayı otomatik doldurur → yazmadan önce `fill("")`.
- Homebrew ffmpeg'de `drawtext` yok → altyazı PIL ile kareye basılır.
- Ücret adı, flash mesajları, kanal sebepleri Türkçe sızıyordu — üçü de düzeltildi; yeni metin eklerken İngilizce panelde bak.
